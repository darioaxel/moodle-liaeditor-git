"""Modelos del flujo de edición LiaEditor (LIA-001).

El contenido es un ``.md`` LiaScript identificado por SHA (v1): ya no hay
Books ni capítulos. El plugin Moodle guarda la trazabilidad de sesiones;
estos modelos son el contrato del engine con ``content.php``/``save.php``.
"""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, Field

MAX_FILE_SIZE = 1_048_576  # 1 MB (RNF-02 de la spec mod_liamd)


class EditOpenRequest(BaseModel):
    """Cuerpo de ``POST /edit/open``: asegura la rama de edición del usuario."""

    user: str = Field(min_length=1)
    file: str = Field(min_length=1)


class EditContent(BaseModel):
    """Contenido de un fichero en una rama, con su SHA (respuesta de
    ``GET /edit/content`` y de ``POST /edit/open``)."""

    file: str
    branch: str
    sha: str
    content: str
    stale: bool = False  # el fichero ya no existe en main (rama desactualizada)
    committed_at: str | None = None  # fecha del commit ``sha`` (ISO 8601 con zona)


class SaveRequest(BaseModel):
    """Cuerpo de ``POST /edit/save`` (D5: optimistic locking por SHA)."""

    user: str = Field(min_length=1)
    file: str = Field(min_length=1)
    branch: str = Field(min_length=1)
    original_sha: str = Field(min_length=40, max_length=40)
    content: str = Field(max_length=MAX_FILE_SIZE)
    message: str | None = None


class SaveOk(BaseModel):
    """Guardado aplicado sobre la rama de edición."""

    status: Literal["ok"] = "ok"
    branch: str
    new_sha: str
    diff_url: str | None = None
    committed_at: str | None = None  # fecha del commit ``new_sha`` (ISO 8601 con zona)


class SaveConflict(BaseModel):
    """Conflicto (D5): la rama avanzó; el trabajo queda en ``resolucion/...``."""

    status: Literal["conflict"] = "conflict"
    resolution_branch: str
    current_sha: str
    diff_url: str | None = None


SaveResult = SaveOk | SaveConflict


class EditState(BaseModel):
    """Estado de un material publicado comparando SHAs (LIA-005, §5.3 de la v1)."""

    file: str
    branch: str
    published_sha: str
    head_sha: str
    state: Literal["up_to_date", "update_available"]
    commits_ahead: int | None = None  # None si published_sha no es ancestro


class MaterialFile(BaseModel):
    """Un material ``.md`` del repo, con el título de su cabecera LiaScript."""

    path: str
    title: str


class MaterialList(BaseModel):
    """Listado de materiales de una rama (catálogo master, LIA-016)."""

    branch: str
    head: str
    files: list[MaterialFile]
