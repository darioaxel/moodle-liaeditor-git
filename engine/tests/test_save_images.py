"""Guardado con imágenes: un commit con Markdown e imágenes (docs/imagenes, fase 2)."""

from __future__ import annotations

import base64
import subprocess
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from materiales_liascript.app import create_app
from materiales_liascript.edit import EditError, EditService, SaveConflict, SaveOk, SaveRequest
from materiales_liascript.edit.models import ImageUpload
from materiales_liascript.git import LocalGitProvider

FILE = "materiales/ut03-mvc/02-mvc.md"
USER = "ana"
BRANCH = "edicion/ana/materiales-ut03-mvc-02-mvc"
FIXTURES = Path(__file__).parent / "fixtures" / "liascript"
PNG = (FIXTURES / "img" / "esquema.png").read_bytes()
JPG = (FIXTURES / "img" / "foto.jpg").read_bytes()
WEBP = (FIXTURES / "diagrama.webp").read_bytes()
HTTP_BAD_REQUEST = 400
HTTP_UNPROCESSABLE = 422


def b64(data: bytes) -> str:
    return base64.b64encode(data).decode()


@pytest.fixture()
def provider(git_repo: Path) -> LocalGitProvider:
    return LocalGitProvider(git_repo)


@pytest.fixture()
def service(provider: LocalGitProvider) -> EditService:
    return EditService(provider)


def _save(sha: str, content: str, images: list[tuple[str, bytes]]) -> SaveRequest:
    return SaveRequest(
        user=USER,
        file=FILE,
        branch=BRANCH,
        original_sha=sha,
        content=content,
        images=[ImageUpload(path=p, content_base64=b64(d)) for p, d in images],
    )


def _files_in_commit(provider: LocalGitProvider, sha: str) -> set[str]:
    out = subprocess.run(
        ["git", "-C", str(provider._root), "show", "--name-only", "--format=", sha],
        check=True,
        capture_output=True,
        text=True,
    ).stdout
    return set(out.split())


def test_un_commit_con_markdown_e_imagenes(
    service: EditService, provider: LocalGitProvider
) -> None:
    sha = service.open(USER, FILE).sha
    md = "# MVC\n\n![Esquema](img/esquema-1a2b3c4d.png)\n\n![Foto](foto.jpg)\n"
    result = service.save(_save(sha, md, [("img/esquema-1a2b3c4d.png", PNG), ("./foto.jpg", JPG)]))
    assert isinstance(result, SaveOk)
    assert _files_in_commit(provider, result.new_sha) == {
        FILE,
        "materiales/ut03-mvc/img/esquema-1a2b3c4d.png",
        "materiales/ut03-mvc/foto.jpg",
    }
    # Binario intacto.
    assert provider.get_bytes(BRANCH, "materiales/ut03-mvc/img/esquema-1a2b3c4d.png") == PNG
    assert provider.get_file(BRANCH, FILE) == md
    # Y se puede servir al editor.
    assert service.asset(FILE, BRANCH, "img/esquema-1a2b3c4d.png") == (PNG, "image/png")


def test_el_conflicto_lleva_las_imagenes_a_la_rama_de_resolucion(
    service: EditService, provider: LocalGitProvider
) -> None:
    sha = service.open(USER, FILE).sha
    service.save(_save(sha, "# otra pestaña\n", []))  # la rama avanza
    result = service.save(_save(sha, "# mío\n![x](img/x.png)\n", [("img/x.png", PNG)]))
    assert isinstance(result, SaveConflict)
    assert provider.get_bytes(result.resolution_branch, "materiales/ut03-mvc/img/x.png") == PNG
    with pytest.raises(Exception):  # noqa: B017 - no está en la rama de edición
        provider.get_bytes(BRANCH, "materiales/ut03-mvc/img/x.png")


@pytest.mark.parametrize(
    ("path", "data"),
    [
        ("img/a.webp", WEBP),  # WebP no se sube (H5P)
        ("img/a.svg", b"<svg onload='x'/>"),
        ("img/a.png", JPG),  # extensión y contenido no coinciden
        ("img/a.png", b"<html>"),
        ("../otra/a.png", PNG),  # fuera de la carpeta
        ("/etc/a.png", PNG),
        ("img/a b.png", PNG),
        ("img/a.png", PNG + b"\0" * (2 * 1024 * 1024)),  # demasiado grande
    ],
)
def test_imagenes_rechazadas_sin_commit(
    service: EditService, provider: LocalGitProvider, path: str, data: bytes
) -> None:
    sha = service.open(USER, FILE).sha
    with pytest.raises(EditError) as exc:
        service.save(_save(sha, "# v2\n", [(path, data)]))
    assert exc.value.status_code == HTTP_BAD_REQUEST
    assert provider.get_commit(BRANCH) == sha  # nada commiteado


def test_base64_no_valido(service: EditService, provider: LocalGitProvider) -> None:
    sha = service.open(USER, FILE).sha
    req = SaveRequest(
        user=USER,
        file=FILE,
        branch=BRANCH,
        original_sha=sha,
        content="# v2\n",
        images=[ImageUpload(path="img/a.png", content_base64="no es base64!!")],
    )
    with pytest.raises(EditError):
        service.save(req)
    assert provider.get_commit(BRANCH) == sha


def test_api_limites(git_repo: Path) -> None:
    client = TestClient(create_app(git_repo, provider=LocalGitProvider(git_repo)))
    sha = client.post("/edit/open", json={"user": USER, "file": FILE}).json()["sha"]
    body = {"user": USER, "file": FILE, "branch": BRANCH, "original_sha": sha, "content": "# v\n"}
    muchas = [{"path": f"img/{i}.png", "content_base64": b64(PNG)} for i in range(21)]
    assert (
        client.post("/edit/save", json={**body, "images": muchas}).status_code == HTTP_UNPROCESSABLE
    )
    mala = [{"path": "img/a.svg", "content_base64": b64(b"<svg/>")}]
    r = client.post("/edit/save", json={**body, "images": mala})
    assert r.status_code == HTTP_BAD_REQUEST
    assert r.json() == {"detail": "imagen no válida: img/a.svg"}
    ok = [{"path": "img/a.png", "content_base64": b64(PNG)}]
    assert client.post("/edit/save", json={**body, "images": ok}).json()["status"] == "ok"
