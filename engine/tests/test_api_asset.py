"""Imágenes del material para el editor: ``GET /edit/asset`` (docs/imagenes, fase 1)."""

from __future__ import annotations

import subprocess
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from materiales_liascript.app import create_app
from materiales_liascript.git import LocalGitProvider

FILE = "materiales/ut03-mvc/02-mvc.md"
FIXTURES = Path(__file__).parent / "fixtures" / "liascript"
PNG = (FIXTURES / "img" / "esquema.png").read_bytes()
WEBP = (FIXTURES / "diagrama.webp").read_bytes()
HTTP_OK = 200
HTTP_NOT_FOUND = 404


@pytest.fixture()
def client(git_repo: Path) -> TestClient:
    carpeta = git_repo / "materiales" / "ut03-mvc"
    (carpeta / "img").mkdir()
    (carpeta / "img" / "esquema.png").write_bytes(PNG)
    (carpeta / "capas.webp").write_bytes(WEBP)
    (carpeta / "img" / "falso.png").write_text("<html>no soy una imagen</html>")
    (carpeta / "img" / "dibujo.svg").write_text("<svg onload='alert(1)'/>")
    otra = git_repo / "materiales" / "otra"
    otra.mkdir()
    (otra / "secreto.png").write_bytes(PNG)
    for args in (["add", "-A"], ["commit", "-qm", "imágenes"]):
        subprocess.run(["git", "-C", str(git_repo), *args], check=True)
    return TestClient(create_app(git_repo, provider=LocalGitProvider(git_repo)))


def _asset(client: TestClient, path: str, branch: str = "main") -> tuple[int, bytes, str]:
    r = client.get("/edit/asset", params={"file": FILE, "branch": branch, "path": path})
    return r.status_code, r.content, r.headers.get("content-type", "")


def test_sirve_la_imagen_con_su_tipo(client: TestClient) -> None:
    status, body, tipo = _asset(client, "img/esquema.png")
    assert (status, body, tipo) == (HTTP_OK, PNG, "image/png")
    status, body, tipo = _asset(client, "./capas.webp")
    assert (status, tipo) == (HTTP_OK, "image/webp")


def test_cabeceras(client: TestClient) -> None:
    r = client.get(
        "/edit/asset", params={"file": FILE, "branch": "main", "path": "img/esquema.png"}
    )
    assert r.headers["x-content-type-options"] == "nosniff"


@pytest.mark.parametrize(
    "path",
    [
        "img/no-existe.png",
        "../otra/secreto.png",  # otra carpeta
        "img/../../otra/secreto.png",
        "/materiales/otra/secreto.png",
        "img/dibujo.svg",  # SVG nunca
        "img/falso.png",  # extensión de imagen, contenido no
        "02-mvc.md",
        "https://example.org/a.png",
    ],
)
def test_404_sin_detalle(client: TestClient, path: str) -> None:
    r = client.get("/edit/asset", params={"file": FILE, "branch": "main", "path": path})
    assert r.status_code == HTTP_NOT_FOUND
    assert r.json() == {"detail": "imagen no encontrada"}


def test_rama_inexistente(client: TestClient) -> None:
    assert _asset(client, "img/esquema.png", branch="no-existe")[0] == HTTP_NOT_FOUND
