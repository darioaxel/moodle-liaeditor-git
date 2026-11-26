"""Rutas, tipos y conversión de las imágenes de los materiales (docs/imagenes)."""

from __future__ import annotations

from pathlib import Path

import pytest

from materiales_liascript.images import (
    MAX_IMAGE_BYTES,
    ImageError,
    check,
    png_copies_for_pdf,
    resolve,
    sniff,
)

FIXTURES = Path(__file__).parent / "fixtures" / "liascript"
PNG = (FIXTURES / "img" / "esquema.png").read_bytes()
JPG = (FIXTURES / "img" / "foto.jpg").read_bytes()
WEBP = (FIXTURES / "diagrama.webp").read_bytes()
MATERIAL = "materiales/ut06/ut06.md"


@pytest.mark.parametrize(
    ("ref", "path"),
    [
        ("img/esquema.png", "materiales/ut06/img/esquema.png"),
        ("./img/a.JPG", "materiales/ut06/img/a.JPG"),
        ("diagrama.webp", "materiales/ut06/diagrama.webp"),
        ("img/../b.gif", "materiales/ut06/b.gif"),
        ("img/a.png?v=2#x", "materiales/ut06/img/a.png"),
    ],
)
def test_resuelve_rutas_relativas_al_material(ref: str, path: str) -> None:
    assert resolve(MATERIAL, ref) == path


def test_material_en_la_raiz() -> None:
    assert resolve("ut06.md", "img/a.png") == "img/a.png"
    with pytest.raises(ImageError):
        resolve("ut06.md", "../a.png")


@pytest.mark.parametrize(
    "ref",
    [
        "../ut05/img/a.png",  # otra carpeta
        "img/../../x.png",
        "/etc/passwd.png",
        "https://example.org/a.png",
        "data:image/png;base64,AAAA",
        "img/a.svg",  # SVG nunca
        "img/a.md",
        "img/a b.png",  # caracteres
        "img\\a.png",
        "",
    ],
)
def test_rechaza_rutas_no_validas(ref: str) -> None:
    with pytest.raises(ImageError):
        resolve(MATERIAL, ref)


def test_tipo_real_por_la_firma() -> None:
    assert sniff(PNG) == "image/png"
    assert sniff(JPG) == "image/jpeg"
    assert sniff(WEBP) == "image/webp"
    assert sniff(b"GIF89a....") == "image/gif"
    assert sniff(b"<svg xmlns=") is None


def test_check_contenido_y_extension() -> None:
    assert check("a/b.png", PNG) == "image/png"
    assert check("a/b.jpeg", JPG) == "image/jpeg"
    with pytest.raises(ImageError, match="no es image/png"):
        check("a/b.png", JPG)  # extensión que no coincide
    with pytest.raises(ImageError, match="no es"):
        check("a/b.png", b"<html>")
    with pytest.raises(ImageError, match="demasiado grande"):
        check("a/b.png", PNG + b"\0" * MAX_IMAGE_BYTES)


def test_subida_sin_webp() -> None:
    assert check("a/b.webp", WEBP) == "image/webp"  # se puede leer…
    with pytest.raises(ImageError, match="no admitido para subir"):
        check("a/b.webp", WEBP, upload=True)  # …pero no subir


def test_copias_png_para_el_pdf(tmp_path: Path) -> None:
    (tmp_path / "img").mkdir()
    (tmp_path / "img" / "d.webp").write_bytes(WEBP)
    (tmp_path / "x.png").write_bytes(PNG)
    (tmp_path / ".oculta").mkdir()
    (tmp_path / ".oculta" / "o.webp").write_bytes(WEBP)
    creadas = png_copies_for_pdf(tmp_path)
    assert creadas == [tmp_path / "img" / "d.webp.png"]
    assert sniff(creadas[0].read_bytes()) == "image/png"
