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
    #: autor (usuario de Moodle) del último commit que modificó el fichero
    last_author: str | None = None


#: Máximo de imágenes por guardado (docs/imagenes, D7).
MAX_IMAGES_PER_SAVE = 20


class ImageUpload(BaseModel):
    """Imagen nueva que viaja con el guardado (ruta relativa al ``.md``)."""

    path: str = Field(min_length=1, max_length=255)
    content_base64: str = Field(min_length=1, max_length=3 * 1024 * 1024)


class SaveRequest(BaseModel):
    """Cuerpo de ``POST /edit/save`` (D5: optimistic locking por SHA)."""

    user: str = Field(min_length=1)
    file: str = Field(min_length=1)
    branch: str = Field(min_length=1)
    original_sha: str = Field(min_length=40, max_length=40)
    content: str = Field(max_length=MAX_FILE_SIZE)
    message: str | None = None
    images: list[ImageUpload] = Field(default_factory=list, max_length=MAX_IMAGES_PER_SAVE)


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
    # La carpeta del material cambió entre lo publicado y el HEAD de la rama.
    content_changed: bool = False


class MaterialFile(BaseModel):
    """Un material ``.md`` del repo, con el título de su cabecera LiaScript."""

    path: str
    title: str


class MaterialList(BaseModel):
    """Listado de materiales de una rama (catálogo master, LIA-016)."""

    branch: str
    head: str
    files: list[MaterialFile]


# -- Incorporar main a las ramas de edición (LIA-130) ------------------------


class MergeSegment(BaseModel):
    """Trozo del material al fusionar: común (``text``) o en conflicto."""

    kind: Literal["same", "conflict"]
    text: str = ""
    ours: str = ""  # versión de la rama de edición (la del profesor)
    base: str = ""  # versión de la que partieron las dos
    theirs: str = ""  # versión oficial (main)


SyncState = Literal["up_to_date", "clean", "conflict", "conflict_other", "not_applicable"]


class SyncStatus(BaseModel):
    """¿Trae ``main`` cambios del material que la rama de edición no tiene?

    - ``up_to_date``: no hay nada que incorporar (o solo cambió otro material).
    - ``clean``: se pueden incorporar sin conflictos.
    - ``conflict``: el material choca; ``segments`` trae lo necesario para
      resolverlo en el editor.
    - ``conflict_other``: chocan otros ficheros (o el material ya no está en
      main); lo resuelve coordinación (Meld en local).
    - ``not_applicable``: la rama es la propia ``main``.
    """

    file: str
    branch: str
    status: SyncState
    head_sha: str
    main_sha: str
    behind: int = 0  # commits de main que la rama no tiene
    conflicts: list[str] = Field(default_factory=list)
    segments: list[MergeSegment] = Field(default_factory=list)
    #: el material en la rama, y, si la fusión es limpia, cómo quedará (para revisarlo)
    ours: str = ""
    merged: str = ""


class MergeRequest(BaseModel):
    """Cuerpo de ``POST /edit/merge``: incorpora ``main`` a la rama.

    ``head_sha`` y ``main_sha`` son los que vio el editor en ``/edit/sync``:
    si alguno cambió, 409 y vuelta a empezar. ``content`` es el material con
    los conflictos resueltos (solo si los había).
    """

    user: str = Field(min_length=1)
    file: str = Field(min_length=1)
    branch: str = Field(min_length=1)
    head_sha: str = Field(min_length=40, max_length=40)
    main_sha: str = Field(min_length=40, max_length=40)
    content: str | None = Field(default=None, max_length=MAX_FILE_SIZE)
