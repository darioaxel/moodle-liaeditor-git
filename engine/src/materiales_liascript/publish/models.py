"""Modelos del flujo de publicación de artefactos (LIA-040)."""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, Field

ArtifactFormat = Literal["h5p", "epub", "pdf"]

ARTIFACT_MEDIA_TYPES: dict[str, str] = {
    "h5p": "application/zip",
    "epub": "application/epub+zip",
    "pdf": "application/pdf",
}


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


class PublishResult(BaseModel):
    """Artefacto generado: bytes + metadatos para nombrarlo y servirlo."""

    filename: str
    media_type: str
    content: bytes
