"""Tema visual de los contenidos exportados (publish/theme.py)."""

from __future__ import annotations

import io
import subprocess
import zipfile
from pathlib import Path

import pytest

from materiales_liascript.publish.models import PublishError
from materiales_liascript.publish.service import LiaexDockerExecutor
from materiales_liascript.publish.theme import (
    THEME_FILENAME,
    add_stylesheet_link,
    append_css_to_epub,
    load_theme_css,
)


def test_tema_verde_empaquetado() -> None:
    css = load_theme_css("verde")
    assert css is not None
    assert "--color-highlight: 42, 125, 87 !important;" in css
    assert "No editar a mano" in css


def test_tema_none_y_desconocido() -> None:
    assert load_theme_css("none") is None
    assert load_theme_css("") is None
    with pytest.raises(ValueError, match="desconocido"):
        load_theme_css("morado")
    with pytest.raises(ValueError, match="no válido"):
        load_theme_css("../secreto")


def test_link_en_cabecera_existente() -> None:
    md = "<!--\nauthor: Ana\ntitle: UT03\n\n-->\n\n# UT03\n"
    assert add_stylesheet_link(md, "tema.css") == (
        "<!--\nauthor: Ana\ntitle: UT03\n\nlink:     tema.css\n-->\n\n# UT03\n"
    )


def test_link_cabecera_en_una_linea_y_sin_cabecera() -> None:
    assert add_stylesheet_link("<!-- title: X -->\n# X\n", "t.css") == (
        "<!-- title: X \nlink:     t.css\n-->\n# X\n"
    )
    assert add_stylesheet_link("# Sin cabecera\n", "t.css") == (
        "<!--\nlink:     t.css\n-->\n\n# Sin cabecera\n"
    )


def _epub() -> bytes:
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, "w") as zf:
        zf.writestr(zipfile.ZipInfo("mimetype"), "application/epub+zip")  # sin comprimir
        info = zipfile.ZipInfo("OEBPS/style.css")
        info.compress_type = zipfile.ZIP_DEFLATED
        zf.writestr(info, "body{color:red}")
        zf.writestr("OEBPS/chapter_1.xhtml", "<html/>")
    return buffer.getvalue()


def test_epub_con_tema_conserva_mimetype() -> None:
    result = zipfile.ZipFile(io.BytesIO(append_css_to_epub(_epub(), "h1{color:green}")))
    first = result.infolist()[0]
    assert first.filename == "mimetype"
    assert first.compress_type == zipfile.ZIP_STORED
    assert result.read("OEBPS/style.css").decode().endswith("h1{color:green}")
    assert result.read("OEBPS/chapter_1.xhtml") == b"<html/>"


class _Completed:
    returncode = 0
    stderr = ""


def _material(tmp_path: Path) -> Path:
    (tmp_path / "materiales").mkdir()
    source = tmp_path / "materiales" / "ut.md"
    source.write_bytes(b"<!--\r\ntitle: UT\r\n-->\r\n\r\n# UT\r\n")
    return source


def test_executor_aplica_el_tema_y_lo_retira(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    source = _material(tmp_path)
    original = source.read_bytes()
    seen: dict[str, str] = {}

    def fake_run(args: list[str], **_: object) -> _Completed:
        seen["md"] = source.read_text(encoding="utf-8")
        seen["css"] = (source.parent / THEME_FILENAME).read_text(encoding="utf-8")
        (tmp_path / "artifact.pdf").write_bytes(b"%PDF")
        return _Completed()

    monkeypatch.setattr(subprocess, "run", fake_run)
    executor = LiaexDockerExecutor(theme_css="h1{color:green}")
    assert executor.generate(tmp_path, "materiales/ut.md", "pdf") == b"%PDF"
    assert f"link:     {THEME_FILENAME}" in seen["md"]
    assert seen["css"] == "h1{color:green}"
    assert source.read_bytes() == original  # restaurado byte a byte (\r\n incluido)
    assert not (source.parent / THEME_FILENAME).exists()


def test_executor_restaura_aunque_falle_liaex(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    source = _material(tmp_path)
    original = source.read_bytes()
    monkeypatch.setattr(subprocess, "run", lambda *a, **k: _Completed())  # sin fichero
    with pytest.raises(PublishError):
        LiaexDockerExecutor(theme_css="x").generate(tmp_path, "materiales/ut.md", "pdf")
    assert source.read_bytes() == original
    assert not (source.parent / THEME_FILENAME).exists()


def test_executor_sin_tema_no_toca_el_material(
    monkeypatch: pytest.MonkeyPatch, tmp_path: Path
) -> None:
    source = _material(tmp_path)
    seen: dict[str, bytes] = {}

    def fake_run(args: list[str], **_: object) -> _Completed:
        seen["md"] = source.read_bytes()
        (tmp_path / "artifact.pdf").write_bytes(b"%PDF")
        return _Completed()

    monkeypatch.setattr(subprocess, "run", fake_run)
    LiaexDockerExecutor().generate(tmp_path, "materiales/ut.md", "pdf")
    assert seen["md"] == b"<!--\r\ntitle: UT\r\n-->\r\n\r\n# UT\r\n"
