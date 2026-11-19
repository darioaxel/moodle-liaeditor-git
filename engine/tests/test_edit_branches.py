"""Tests de ramas de edición, validación de rutas y URLs de diff (LIA-001/003)."""

from __future__ import annotations

from datetime import UTC, datetime

import pytest

from materiales_liascript.edit import (
    EditError,
    build_compare_url,
    edit_branch_name,
    resolution_branch_name,
    validate_file_path,
)


def test_validate_file_path_acepta_md_relativo() -> None:
    assert validate_file_path("unidades/unidad-3.md") == "unidades/unidad-3.md"
    assert validate_file_path("README.md") == "README.md"


@pytest.mark.parametrize(
    "file",
    [
        "/etc/passwd",
        "../fuera.md",
        "unidades/../../fuera.md",
        "unidades/./unidad.md",
        "C:\\windows\\system32",
        "documento.txt",
        "sin-extension",
        "",
    ],
)
def test_validate_file_path_rechaza_traversal_y_no_md(file: str) -> None:
    with pytest.raises(EditError) as excinfo:
        validate_file_path(file)
    assert excinfo.value.status_code == 400  # noqa: PLR2004


def test_validate_file_path_rechaza_ruta_larga() -> None:
    with pytest.raises(EditError):
        validate_file_path("a" * 260 + ".md")


def test_edit_branch_name_formato() -> None:
    assert edit_branch_name("profesor-x", "unidades/unidad-3.md") == (
        "edicion/profesor-x/unidades-unidad-3"
    )


def test_edit_branch_name_sanitiza() -> None:
    branch = edit_branch_name("María García", "Unidad 3 — Repaso Final.md")
    assert branch == "edicion/mar-a-garc-a/unidad-3-repaso-final"


def test_edit_branch_name_max_100() -> None:
    branch = edit_branch_name("u" * 80, "f" * 90 + ".md")
    assert len(branch) <= 100  # noqa: PLR2004
    assert branch.startswith("edicion/")


def test_resolution_branch_name_incluye_timestamp() -> None:
    now = datetime(2026, 11, 17, 15, 30, 45, tzinfo=UTC)
    branch = resolution_branch_name("profesor-x", "unidad-3.md", now)
    assert branch == "resolucion/profesor-x/20261117-153045-unidad-3"


def test_build_compare_url_https() -> None:
    url = build_compare_url(
        "https://github.com/FPVirtual/contenidos-test.git", "a" * 40, "resolucion/x/y"
    )
    expected = f"https://github.com/FPVirtual/contenidos-test/compare/{'a' * 40}...resolucion/x/y"
    assert url == expected


def test_build_compare_url_ssh() -> None:
    url = build_compare_url("git@gitlab.com:org/repo.git", "b" * 40, "c" * 40)
    assert url == f"https://gitlab.com/org/repo/compare/{'b' * 40}...{'c' * 40}"


def test_build_compare_url_credenciales_en_https() -> None:
    url = build_compare_url("https://token@github.com/org/repo.git", "b" * 40, "c" * 40)
    assert url is not None
    assert "token@" not in url
    assert url.startswith("https://github.com/org/repo/compare/")


def test_build_compare_url_sin_repo_url() -> None:
    assert build_compare_url(None, "a" * 40, "b" * 40) is None
