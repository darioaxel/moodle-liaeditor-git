"""Modelos del flujo de publicación de artefactos (LIA-040)."""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, Field

ArtifactFormat = Literal["h5p", "epub", "pdf", "web", "pdfcontenido"]

ARTIFACT_MEDIA_TYPES: dict[str, str] = {
    "h5p": "application/zip",
    "epub": "application/epub+zip",
    "pdf": "application/pdf",
    "web": "application/zip",
    "pdfcontenido": "application/pdf",
}

#: Extensión del fichero que genera liaex para cada formato de la API.
ARTIFACT_EXTENSIONS: dict[str, str] = {
    "h5p": "h5p",
    "epub": "epub",
    "pdf": "pdf",
    "web": "zip",
    "pdfcontenido": "pdf",
}

#: Formatos generados con Pandoc + lector LiaScript (docs/generacion_web);
#: el resto, con liaex.
PANDOC_FORMATS = frozenset({"web", "pdfcontenido"})


class PublishError(RuntimeError):
    """Error de publicación con status HTTP asociado para la API."""

    def __init__(self, status_code: int, detail: str) -> None:
        super().__init__(detail)
        self.status_code = status_code
        self.detail = detail


class PublishRequest(BaseModel):
    """Cuerpo de ``POST /publish``: genera el artefacto de un material."""

    file: str = Field(min_length=1)
    sha: str = Field(min_length=40, max_length=40)
    format: ArtifactFormat
    #: Autor que figura en lo generado con Pandoc (web, PDF LaTeX) en lugar
    #: del ``author:`` de la cabecera del material; lo decide el plugin.
    author: str | None = Field(default=None, min_length=1, max_length=200)
    #: Título del módulo (el curso de Moodle): título de la portada del PDF
    #: LaTeX, con la unidad como subtítulo. Sin él, el título es la unidad.
    module: str | None = Field(default=None, min_length=1, max_length=255)


class PublishResult(BaseModel):
    """Artefacto generado: bytes + metadatos para nombrarlo y servirlo."""

    filename: str
    media_type: str
    content: bytes
    #: Construcciones LiaScript que no se pudieron convertir (solo Pandoc).
    avisos: list[str] = Field(default_factory=list)
