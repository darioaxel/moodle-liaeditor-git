"""Tests del endpoint de publicación de artefactos (LIA-040) con fake inyectado."""

from __future__ import annotations

import json
import shutil
import subprocess
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from materiales_liascript.app import create_app
from materiales_liascript.edit import EditService
from materiales_liascript.git import LocalGitProvider
from materiales_liascript.publish.models import PublishError, PublishRequest
from materiales_liascript.publish.pandoc_export import PandocExporter, PandocResult
from materiales_liascript.publish.service import (
    ArtifactExecutor,
    LiaexDockerExecutor,
    PublishService,
)

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


# -- LiaexDockerExecutor: orden docker construida --------------------------------


class _Completed:
    returncode = 0
    stderr = ""


def _capture_docker(monkeypatch: pytest.MonkeyPatch, tmp_path: Path) -> list[list[str]]:
    calls: list[list[str]] = []

    def fake_run(args: list[str], **_: object) -> _Completed:
        calls.append(args)
        # Como liaex real: añade la extensión del formato al nombre base.
        base = args[args.index("--output") + 1].removeprefix("/work/")
        fmt = args[args.index("--format") + 1]
        (tmp_path / f"{base}.{fmt}").write_bytes(b"ARTEFACTO")
        return _Completed()

    monkeypatch.setattr(subprocess, "run", fake_run)
    return calls


def test_liaex_sin_red_por_defecto(monkeypatch: pytest.MonkeyPatch, tmp_path: Path) -> None:
    calls = _capture_docker(monkeypatch, tmp_path)
    assert LiaexDockerExecutor().generate(tmp_path, "a.md", "epub") == b"ARTEFACTO"
    args = calls[0]
    assert args[args.index("--network") + 1] == "none"
    assert "--shm-size=1g" not in args


def test_liaex_red_configurable_y_pdf(monkeypatch: pytest.MonkeyPatch, tmp_path: Path) -> None:
    calls = _capture_docker(monkeypatch, tmp_path)
    executor = LiaexDockerExecutor(
        network="bridge", workdir=str(tmp_path.parent), host_workdir="/host/tmp"
    )
    executor.generate(tmp_path, "a.md", "pdf")
    args = calls[0]
    assert args[args.index("--network") + 1] == "bridge"
    assert "--shm-size=1g" in args
    assert args[args.index("-v") + 1] == f"/host/tmp/{tmp_path.name}:/work"


def test_liaex_salida_sin_extension_y_limpieza(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    calls = _capture_docker(monkeypatch, tmp_path)
    for fmt in ("pdf", "h5p", "epub"):
        assert LiaexDockerExecutor().generate(tmp_path, "a.md", fmt) == b"ARTEFACTO"
        args = calls[-1]
        assert args[args.index("--output") + 1] == "/work/artifact"
    assert not list(tmp_path.glob("artifact*"))  # no deja ficheros en el checkout


def test_liaex_nombre_alternativo_y_fichero_ausente(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    def doble_extension(args: list[str], **_: object) -> _Completed:
        (tmp_path / "artifact.pdf.pdf").write_bytes(b"PDF")
        return _Completed()

    monkeypatch.setattr(subprocess, "run", doble_extension)
    assert LiaexDockerExecutor().generate(tmp_path, "a.md", "pdf") == b"PDF"

    monkeypatch.setattr(subprocess, "run", lambda *a, **k: _Completed())
    with pytest.raises(PublishError) as excinfo:
        LiaexDockerExecutor().generate(tmp_path, "a.md", "pdf")
    assert excinfo.value.status_code == 502  # noqa: PLR2004


def test_liaex_recibe_el_formato_de_la_api(monkeypatch: pytest.MonkeyPatch, tmp_path: Path) -> None:
    calls = _capture_docker(monkeypatch, tmp_path)
    assert LiaexDockerExecutor().generate(tmp_path, "a.md", "h5p") == b"ARTEFACTO"
    args = calls[0]
    assert args[args.index("--format") + 1] == "h5p"
    assert not list(tmp_path.glob("artifact*"))


def test_publish_scorm_ya_no_se_admite(client: TestClient, material_sha: str) -> None:
    response = client.post("/publish", json={"file": FILE, "sha": material_sha, "format": "scorm"})
    assert response.status_code == 422  # noqa: PLR2004


# -- Web estática y PDF de contenido (Pandoc) -----------------------------------


class FakePandoc(PandocExporter):
    """Devuelve artefactos prefijados y avisos, sin Docker."""

    def __init__(self) -> None:
        super().__init__()
        self.calls: list[tuple[str, str]] = []

    def web(self, checkout: Path, file: str) -> PandocResult:
        self.calls.append(("web", file))
        assert (checkout / file).is_file()
        return PandocResult(b"PK-WEB", ["macro · @Pyodide.eval no se puede expandir"])

    def content_pdf(self, checkout: Path, file: str) -> PandocResult:
        self.calls.append(("pdfcontenido", file))
        return PandocResult(b"%PDF-CONTENIDO", [])


def test_publish_web_con_avisos(
    git_repo: Path, provider: LocalGitProvider, material_sha: str
) -> None:
    pandoc = FakePandoc()
    client = TestClient(
        create_app(git_repo, provider=provider, executor=FakeExecutor(), pandoc=pandoc)
    )
    response = client.post("/publish", json={"file": FILE, "sha": material_sha, "format": "web"})
    assert response.status_code == HTTP_OK
    assert response.content == b"PK-WEB"
    assert response.headers["content-type"] == "application/zip"
    assert f'@{material_sha[:8]}.zip"' in response.headers["content-disposition"]
    assert json.loads(response.headers["x-liamat-avisos"]) == [
        "macro · @Pyodide.eval no se puede expandir"
    ]
    assert pandoc.calls == [("web", FILE)]


def test_publish_pdf_de_contenido(
    git_repo: Path, provider: LocalGitProvider, material_sha: str
) -> None:
    client = TestClient(
        create_app(git_repo, provider=provider, executor=FakeExecutor(), pandoc=FakePandoc())
    )
    response = client.post(
        "/publish", json={"file": FILE, "sha": material_sha, "format": "pdfcontenido"}
    )
    assert response.status_code == HTTP_OK
    assert response.content == b"%PDF-CONTENIDO"
    assert response.headers["content-type"] == "application/pdf"
    assert "x-liamat-avisos" not in response.headers


def test_publish_web_sin_pandoc_es_503(
    material_sha: str, git_repo: Path, provider: LocalGitProvider
) -> None:
    service = PublishService(provider, FakeExecutor(), pandoc=None)
    with pytest.raises(PublishError) as excinfo:
        service.publish(PublishRequest(file=FILE, sha=material_sha, format="web"))
    assert excinfo.value.status_code == HTTP_UNAVAILABLE
