"""Tests del endpoint de publicación de artefactos (LIA-040) con fake inyectado."""

from __future__ import annotations

import shutil
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from courseascode.edit import EditService
from courseascode.git import LocalGitProvider
from courseascode.publish.models import PublishError, PublishRequest
from courseascode.publish.service import ArtifactExecutor, PublishService
from courseascode.web import create_app

FILE = "materiales/ut03-mvc.md"
USER = "ana"

HTTP_OK = 200
HTTP_NOT_FOUND = 404
HTTP_UNAVAILABLE = 503
HTTP_BAD_REQUEST = 400


class FakeExecutor(ArtifactExecutor):
    """Devuelve bytes prefijados y registra las llamadas."""

    def __init__(self) -> None:
        self.calls: list[tuple[str, str]] = []

    def generate(self, checkout: Path, file: str, artifact_format: str) -> bytes:
        self.calls.append((file, artifact_format))
        assert (checkout / file).is_file()  # el checkout contiene el material
        return f"FAKE-{artifact_format}-BYTES".encode()


@pytest.fixture()
def provider(git_repo: Path) -> LocalGitProvider:
    return LocalGitProvider(git_repo)


@pytest.fixture()
def executor() -> FakeExecutor:
    return FakeExecutor()


@pytest.fixture()
def client(git_repo: Path, provider: LocalGitProvider, executor: FakeExecutor) -> TestClient:
    return TestClient(create_app(git_repo, provider=provider, executor=executor))


@pytest.fixture()
def material_sha(provider: LocalGitProvider) -> str:
    """Crea el material en main y devuelve el SHA del commit."""
    provider.commit_file(
        "main", FILE, "<!--\ntitle: UT03\n-->\n\n# UT03\n", "material", "t <t@t.local>"
    )
    return provider.get_commit("main")


def test_publish_devuelve_bytes_del_artefacto(
    client: TestClient, executor: FakeExecutor, material_sha: str
) -> None:
    response = client.post("/publish", json={"file": FILE, "sha": material_sha, "format": "epub"})

    assert response.status_code == HTTP_OK
    assert response.headers["content-type"] == "application/epub+zip"
    assert response.content == b"FAKE-epub-BYTES"
    disposition = response.headers["content-disposition"]
    assert "ut03-mvc" in disposition and disposition.endswith('.epub"')
    assert executor.calls == [(FILE, "epub")]


def test_publish_h5p_y_pdf(client: TestClient, material_sha: str) -> None:
    for fmt, media in [("h5p", "application/zip"), ("pdf", "application/pdf")]:
        response = client.post("/publish", json={"file": FILE, "sha": material_sha, "format": fmt})
        assert response.status_code == HTTP_OK
        assert response.headers["content-type"] == media


def test_publish_sha_o_fichero_inexistente(client: TestClient, material_sha: str) -> None:
    response = client.post("/publish", json={"file": FILE, "sha": "0" * 40, "format": "epub"})
    assert response.status_code == HTTP_NOT_FOUND
    response = client.post(
        "/publish", json={"file": "no/existe.md", "sha": material_sha, "format": "epub"}
    )
    assert response.status_code == HTTP_NOT_FOUND


def test_publish_formato_invalido(client: TestClient, material_sha: str) -> None:
    response = client.post("/publish", json={"file": FILE, "sha": material_sha, "format": "exe"})
    assert response.status_code == 422  # noqa: PLR2004 - validación pydantic


def test_publish_path_traversal(client: TestClient, material_sha: str) -> None:
    response = client.post(
        "/publish", json={"file": "../fuera.md", "sha": material_sha, "format": "epub"}
    )
    assert response.status_code == HTTP_BAD_REQUEST


def test_publish_sin_ejecutor(
    git_repo: Path, provider: LocalGitProvider, material_sha: str
) -> None:
    client = TestClient(create_app(git_repo, provider=provider, executor=None))
    # executor=None en create_app autodetecta Docker; si no hay, 503.
    if shutil.which("docker") is None:
        response = client.post(
            "/publish", json={"file": FILE, "sha": material_sha, "format": "epub"}
        )
        assert response.status_code == HTTP_UNAVAILABLE


def test_publish_service_sin_ejecutor(provider: LocalGitProvider, material_sha: str) -> None:
    service = PublishService(provider, None)
    with pytest.raises(PublishError) as excinfo:
        service.publish(PublishRequest(file=FILE, sha=material_sha, format="epub"))
    assert excinfo.value.status_code == HTTP_UNAVAILABLE


def test_edit_service_sigue_funcionando(provider: LocalGitProvider, material_sha: str) -> None:
    """El material publicable también se edita por el flujo normal (LIA-001)."""
    opened = EditService(provider).open(USER, FILE)
    assert opened.branch == "edicion/ana/materiales-ut03-mvc"
