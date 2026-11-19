"""Ramas de edición ``edicion/<user>/<file>`` y validación de rutas (LIA-003, D4).

Una rama de edición por usuario y fichero; ``main`` protegido. El nombre se
sanitiza: minúsculas, sin espacios, máximo 100 caracteres. En conflicto (D5)
se crea ``resolucion/<user>/<timestamp>-<file>`` para no perder el trabajo.
"""

from __future__ import annotations

import re
from datetime import UTC, datetime

_MAX_BRANCH = 100
_MAX_PATH = 255
_SLUG_RE = re.compile(r"[^a-z0-9]+")
_DASH_RUN_RE = re.compile(r"-{2,}")


class EditError(RuntimeError):
    """Error del flujo de edición con status HTTP asociado para la API."""

    def __init__(self, status_code: int, detail: str) -> None:
        super().__init__(detail)
        self.status_code = status_code
        self.detail = detail


def _slug(text: str) -> str:
    slug = _DASH_RUN_RE.sub("-", _SLUG_RE.sub("-", text.lower())).strip("-")
    return slug or "material"


def validate_file_path(file: str) -> str:
    """Ruta relativa de un ``.md`` del repo; rechaza path traversal."""
    if not file or file.startswith("/") or file.startswith("\\"):
        raise EditError(400, f"ruta inválida: {file!r}")
    parts = file.replace("\\", "/").split("/")
    if any(part in ("", ".", "..") for part in parts):
        raise EditError(400, f"ruta inválida (path traversal): {file!r}")
    if not file.lower().endswith(".md"):
        raise EditError(400, f"solo se editan ficheros Markdown (.md): {file!r}")
    if len(file) > _MAX_PATH:
        raise EditError(400, f"ruta demasiado larga: {file!r}")
    return file


def edit_branch_name(user: str, file: str) -> str:
    """Rama de edición del usuario para el fichero (D4)."""
    stem = _slug(file.removesuffix(".md").replace("/", "-"))
    prefix = f"edicion/{_slug(user)}/"
    return prefix + stem[: _MAX_BRANCH - len(prefix)]


def resolution_branch_name(user: str, file: str, now: datetime | None = None) -> str:
    """Rama de resolución de conflicto (D5): conserva el trabajo del usuario."""
    stamp = (now or datetime.now(UTC)).strftime("%Y%m%d-%H%M%S")
    stem = _slug(file.removesuffix(".md").replace("/", "-"))
    prefix = f"resolucion/{_slug(user)}/{stamp}-"
    return prefix + stem[: _MAX_BRANCH - len(prefix)]
