"""Tests de los endpoints de edición (LIA-002/004/005) con provider real inyectado."""

from __future__ import annotations

from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from courseascode.app import create_app
from courseascode.edit import EditService
from courseascode.git import LocalGitProvider

FILE = "materiales/ut03-mvc/02-mvc.md"
USER = "ana"
BRANCH = "edicion/ana/materiales-ut03-mvc-02-mvc"
REPO_URL = "https://github.com/FPVirtual/contenidos-test.git"

HTTP_OK = 200
HTTP_BAD_REQUEST = 400
HTTP_NOT_FOUND = 404
HTTP_CONFLICT = 409
HTTP_UNAVAILABLE = 503


@pytest.fixture()
def provider(git_repo: Path) -> LocalGitProvider:
    return LocalGitProvider(git_repo)


@pytest.fixture()
def client(git_repo: Path, provider: LocalGitProvider) -> TestClient:
    return TestClient(create_app(git_repo, provider=provider, content_repo_url=REPO_URL))


def test_open_y_content(client: TestClient) -> None:
    opened = client.post("/edit/open", json={"user": USER, "file": FILE})
    assert opened.status_code == HTTP_OK
    data = opened.json()
    assert data["branch"] == BRANCH
    assert data["stale"] is False
    assert len(data["sha"]) == 40  # noqa: PLR2004
    assert "Detalle extra." in data["content"]

    content = client.get("/edit/content", params={"file": FILE, "branch": BRANCH})
    assert content.status_code == HTTP_OK
    assert content.json()["sha"] == data["sha"]


def test_open_es_idempotente(client: TestClient) -> None:
    first = client.post("/edit/open", json={"user": USER, "file": FILE})
    second = client.post("/edit/open", json={"user": USER, "file": FILE})
    assert first.status_code == HTTP_OK
    assert second.status_code == HTTP_OK
    assert first.json()["sha"] == second.json()["sha"]


def test_content_404_fichero_o_rama(client: TestClient) -> None:
    response = client.get("/edit/content", params={"file": "no/existe.md", "branch": "main"})
    assert response.status_code == HTTP_NOT_FOUND
    response = client.get("/edit/content", params={"file": FILE, "branch": "no-existe"})
    assert response.status_code == HTTP_NOT_FOUND


def test_content_400_path_traversal(client: TestClient) -> None:
    response = client.get("/edit/content", params={"file": "../fuera.md", "branch": "main"})
    assert response.status_code == HTTP_BAD_REQUEST


def test_save_ok(client: TestClient) -> None:
    opened = client.post("/edit/open", json={"user": USER, "file": FILE}).json()
    response = client.post(
        "/edit/save",
        json={
            "user": USER,
            "file": FILE,
            "branch": BRANCH,
            "original_sha": opened["sha"],
            "content": "# MVC editado\n",
        },
    )
    assert response.status_code == HTTP_OK
    data = response.json()
    assert data["status"] == "ok"
    assert data["new_sha"] != opened["sha"]

    content = client.get("/edit/content", params={"file": FILE, "branch": BRANCH})
    assert content.json()["content"] == "# MVC editado\n"


def test_save_conflicto_409_con_diff_url(client: TestClient, provider: LocalGitProvider) -> None:
    opened = client.post("/edit/open", json={"user": USER, "file": FILE}).json()
    provider.commit_file(BRANCH, FILE, "# Ajeno\n", "otro", "otro <otro@test.local>")

    response = client.post(
        "/edit/save",
        json={
            "user": USER,
            "file": FILE,
            "branch": BRANCH,
            "original_sha": opened["sha"],
            "content": "# Mío\n",
        },
    )

    assert response.status_code == HTTP_CONFLICT
    data = response.json()
    assert data["status"] == "conflict"
    assert data["resolution_branch"].startswith("resolucion/ana/")
    assert data["current_sha"] == provider.get_commit(BRANCH)
    assert data["diff_url"].startswith("https://github.com/FPVirtual/contenidos-test/compare/")
    assert data["diff_url"].endswith(f"...{data['resolution_branch']}")
    # El trabajo queda accesible en la rama de resolución.
    rescue = client.get("/edit/content", params={"file": FILE, "branch": data["resolution_branch"]})
    assert rescue.json()["content"] == "# Mío\n"


def test_save_rechaza_contenido_gigante(client: TestClient) -> None:
    response = client.post(
        "/edit/save",
        json={
            "user": USER,
            "file": FILE,
            "branch": BRANCH,
            "original_sha": "a" * 40,
            "content": "x" * (1_048_577),
        },
    )
    assert response.status_code == 422  # noqa: PLR2004 - validación pydantic


def test_state(client: TestClient, provider: LocalGitProvider) -> None:
    head = provider.get_commit("main")
    response = client.get("/edit/state", params={"file": FILE, "sha": head})
    assert response.status_code == HTTP_OK
    assert response.json()["state"] == "up_to_date"

    old = provider.get_commit("main~1")
    response = client.get("/edit/state", params={"file": FILE, "sha": old})
    assert response.json()["state"] == "update_available"
    assert response.json()["commits_ahead"] > 0


def test_files_lista_materiales_md(client: TestClient, provider: LocalGitProvider) -> None:
    response = client.get("/edit/files")

    assert response.status_code == HTTP_OK
    data = response.json()
    assert data["branch"] == "main"
    assert data["head"] == provider.get_commit("main")
    paths = [f["path"] for f in data["files"]]
    assert FILE in paths
    assert "README.md" not in paths  # el README raíz no es un material
    assert all(p.endswith(".md") for p in paths)


def test_files_rama_inexistente(client: TestClient) -> None:
    response = client.get("/edit/files", params={"branch": "no-existe"})
    assert response.status_code == HTTP_NOT_FOUND


def test_material_title_desde_cabecera_liascript(provider: LocalGitProvider) -> None:
    provider.create_branch("edicion/test/titulo", "main")
    provider.commit_file(
        "edicion/test/titulo",
        "nuevo.md",
        '<!--\ntitle: "Unidad 3 — MVC"\nauthor: Ana\n-->\n\n# Contenido\n',
        "nuevo material",
        "ana <ana@test.local>",
    )
    materials = EditService(provider).list_materials("edicion/test/titulo")

    titles = {f.path: f.title for f in materials.files}
    assert titles["nuevo.md"] == "Unidad 3 — MVC"
    assert titles[FILE] == FILE.rsplit("/", 1)[-1].removesuffix(".md")  # fallback


def test_endpoints_sin_provider_git(tmp_path: Path) -> None:
    client = TestClient(create_app(tmp_path))
    response = client.get("/edit/content", params={"file": FILE, "branch": "main"})
    assert response.status_code == HTTP_UNAVAILABLE
