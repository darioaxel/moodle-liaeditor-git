"""Endpoint REST de publicación de artefactos (LIA-040, D7 fase 2).

``POST /publish`` recibe ``{file, sha, format}`` y devuelve los **bytes del
artefacto** (H5P/ePub/PDF) generado con liaex sobre el checkout de ese SHA.
El plugin Moodle lo consume desde su fachada de publicación: guarda el
fichero y crea el módulo del curso (D6). El botón Publicar del editor
(contrato roadmap §5) recibe del plugin ``{cmid, url}``.

El router se monta siempre; sin ejecutor de artefactos (no hay Docker en el
host) responde 503 con JSON claro.
"""

from __future__ import annotations

from dataclasses import dataclass

from fastapi import APIRouter, HTTPException
from fastapi.responses import Response

from materiales_liascript.edit.branches import EditError
from materiales_liascript.git.provider import GitProvider
from materiales_liascript.publish.models import PublishError, PublishRequest
from materiales_liascript.publish.service import ArtifactExecutor, PublishService


@dataclass(frozen=True)
class PublishApiDeps:
    """Dependencias del endpoint de publicación (inyectadas desde ``create_app``)."""

    git: GitProvider | None
    executor: ArtifactExecutor | None = None


def create_publish_router(deps: PublishApiDeps) -> APIRouter:
    """Router con el endpoint de publicación sobre las dependencias dadas."""
    router = APIRouter()

    def service() -> PublishService:
        if deps.git is None:
            raise HTTPException(
                status_code=503,
                detail="publicación no disponible: el contenido no es un repositorio Git",
            )
        return PublishService(deps.git, deps.executor)

    @router.post("/publish")
    def publish(request: PublishRequest) -> Response:
        """Genera el artefacto del material en el SHA dado (bytes binarios)."""
        try:
            result = service().publish(request)
        except PublishError as exc:
            raise HTTPException(status_code=exc.status_code, detail=exc.detail) from exc
        except EditError as exc:
            raise HTTPException(status_code=exc.status_code, detail=exc.detail) from exc
        return Response(
            content=result.content,
            media_type=result.media_type,
            headers={"Content-Disposition": f'attachment; filename="{result.filename}"'},
        )

    return router
