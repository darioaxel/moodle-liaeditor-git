"""Borrado de las imágenes que el material deja de usar (mismo commit que el Markdown)."""

from __future__ import annotations

import base64
import subprocess
from pathlib import Path

import pytest

from materiales_liascript import images
from materiales_liascript.edit import EditService, SaveConflict, SaveOk, SaveRequest
from materiales_liascript.edit.models import ImageUpload
from materiales_liascript.git import GitProviderError, LocalGitProvider

FILE = "materiales/ut03-mvc/02-mvc.md"
USER = "ana"
BRANCH = "edicion/ana/materiales-ut03-mvc-02-mvc"
PNG = (Path(__file__).parent / "fixtures" / "liascript" / "img" / "esquema.png").read_bytes()
A, B = "materiales/ut03-mvc/img/a.png", "materiales/ut03-mvc/img/b.png"


@pytest.fixture()
def provider(git_repo: Path) -> LocalGitProvider:
    return LocalGitProvider(git_repo)


@pytest.fixture()
def service(provider: LocalGitProvider) -> EditService:
    return EditService(provider)


def _save(sha: str, content: str, uploads: dict[str, bytes] | None = None) -> SaveRequest:
    return SaveRequest(
        user=USER,
        file=FILE,
        branch=BRANCH,
        original_sha=sha,
        content=content,
        images=[
            ImageUpload(path=p, content_base64=base64.b64encode(d).decode())
            for p, d in (uploads or {}).items()
        ],
    )


def _exists(provider: LocalGitProvider, ref: str, path: str) -> bool:
    try:
        provider.get_bytes(ref, path)
    except GitProviderError:
        return False
    return True


def _with_two_images(service: EditService) -> str:
    sha = service.open(USER, FILE).sha
    md = "# MVC\n\n![A](img/a.png)\n\nTexto ![B](./img/b.png) en línea.\n"
    result = service.save(_save(sha, md, {"img/a.png": PNG, "img/b.png": PNG}))
    assert isinstance(result, SaveOk)
    return result.new_sha


def test_quitar_una_imagen_la_borra_en_el_mismo_commit(
    service: EditService, provider: LocalGitProvider
) -> None:
    sha = _with_two_images(service)
    result = service.save(_save(sha, "# MVC\n\nTexto ![B](./img/b.png) en línea.\n"))
    assert isinstance(result, SaveOk)
    assert not _exists(provider, result.new_sha, A)
    assert _exists(provider, result.new_sha, B)
    changed = subprocess.run(
        ["git", "-C", str(provider._root), "show", "--name-status", "--format=", result.new_sha],
        check=True,
        capture_output=True,
        text=True,
    ).stdout.split("\n")
    assert f"D\t{A}" in changed and f"M\t{FILE}" in changed


def test_quitar_todas_borra_la_carpeta_img(
    service: EditService, provider: LocalGitProvider
) -> None:
    sha = _with_two_images(service)
    result = service.save(_save(sha, "# MVC sin imágenes\n"))
    assert isinstance(result, SaveOk)
    assert not any(p.startswith("materiales/ut03-mvc/img/") for p in provider.list_files(BRANCH))


def test_no_borra_las_que_usa_otro_material(
    service: EditService, provider: LocalGitProvider
) -> None:
    sha = _with_two_images(service)
    provider.commit_file(
        BRANCH,
        "materiales/anexo/anexo.md",
        "# Anexo\n\n<img src='../ut03-mvc/img/a.png'>\n",
        "anexo",
        "t <t@t.local>",
    )
    sha = provider.get_commit(BRANCH)
    result = service.save(_save(sha, "# MVC sin imágenes\n"))
    assert isinstance(result, SaveOk)
    assert _exists(provider, result.new_sha, A)  # la usa el anexo
    assert not _exists(provider, result.new_sha, B)


def test_no_toca_imagenes_que_el_material_no_referenciaba(
    service: EditService, provider: LocalGitProvider
) -> None:
    sha = service.open(USER, FILE).sha
    provider.commit_files(
        BRANCH, {"materiales/ut03-mvc/img/suelta.png": PNG}, "a mano", "t <t@t.local>"
    )
    sha = provider.get_commit(BRANCH)
    result = service.save(_save(sha, "# Cambio de texto\n"))
    assert isinstance(result, SaveOk)
    assert _exists(provider, result.new_sha, "materiales/ut03-mvc/img/suelta.png")


def test_quitar_y_volver_a_subir_en_el_mismo_guardado_la_conserva(
    service: EditService, provider: LocalGitProvider
) -> None:
    sha = _with_two_images(service)
    # El editor reenvía la imagen al volver a usarla (deshacer tras guardar).
    result = service.save(_save(sha, "# MVC\n\n![A](img/a.png)\n", {"img/b.png": PNG}))
    assert isinstance(result, SaveOk)
    assert _exists(provider, result.new_sha, A)
    assert _exists(provider, result.new_sha, B)


def test_el_conflicto_borra_en_la_rama_de_resolucion(
    service: EditService, provider: LocalGitProvider
) -> None:
    sha = _with_two_images(service)
    other = service.save(
        _save(sha, "# MVC\n\n![A](img/a.png)\n\n![B](img/b.png)\n\notra pestaña\n")
    )
    assert isinstance(other, SaveOk)
    result = service.save(_save(sha, "# MVC\n\nTexto ![B](./img/b.png) en línea.\n"))
    assert isinstance(result, SaveConflict)
    assert not _exists(provider, result.resolution_branch, A)
    assert _exists(provider, BRANCH, A)  # la rama de edición no cambia


def test_references_markdown_html_y_rutas_externas() -> None:
    md = (
        '![a](img/a.png "t") ![b](<img/b.png>) ![c](https://x.org/c.png)\n'
        '<img src="img/d.jpg"> ![e](../fuera/e.png) `no` ![](img/f.gif#x)\n'
    )
    assert images.references(FILE, md) == {
        "materiales/ut03-mvc/img/a.png",
        "materiales/ut03-mvc/img/b.png",
        "materiales/ut03-mvc/img/d.jpg",
        "materiales/ut03-mvc/img/f.gif",
    }
    assert "materiales/fuera/e.png" in images.references(FILE, md, confined=False)
