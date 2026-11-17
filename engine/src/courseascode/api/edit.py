"""Endpoints REST del flujo de edición LiaEditor (LIA-002/004/005).

El plugin Moodle (``content.php``, ``save.php``, panel del curso) consume
estas rutas; la lógica Git vive en ``courseascode.edit`` (D2):

- ``GET /edit/content``: contenido de un fichero en una rama + SHA del HEAD.
- ``POST /edit/open``: asegura la rama ``edicion/<user>/<file>`` (D4) y
  devuelve el contenido; marca ``stale`` si el fichero desapareció de main.
- ``POST /edit/save``: guardado optimistic-locking (D5). ``200`` con el
  nuevo SHA o ``409`` con la rama ``resolucion/...`` creada y la URL de diff.
- ``GET /edit/state``: UP_TO_DATE / UPDATE_AVAILABLE por comparación de SHAs
  (§5.3 de la v1), para el panel del curso y las notificaciones.

El router se monta siempre; sin provider Git responde 503 con JSON claro.
"""

from __future__ import annotations

from dataclasses import dataclass

from fastapi import APIRouter, HTTPException, Query
from fastapi.responses import JSONResponse

from courseascode.edit.branches import EditError
from courseascode.edit.models import EditOpenRequest, SaveRequest
from courseascode.edit.service import EditService
from courseascode.git.provider import GitProvider, GitProviderError


@dataclass(frozen=True)
class EditApiDeps:
    """Dependencias de los endpoints de edición (inyectadas desde ``create_app``)."""

    git: GitProvider | None
    repo_url: str | None = None  # para construir diff_url de la forja
    default_branch: str = "main"


def create_edit_router(deps: EditApiDeps) -> APIRouter:
    """Router con los endpoints de edición sobre las dependencias dadas."""
    router = APIRouter()

    def service() -> EditService:
        if deps.git is None:
            raise HTTPException(
                status_code=503,
                detail="edición no disponible: el contenido no es un repositorio Git",
            )
        return EditService(deps.git, repo_url=deps.repo_url, default_branch=deps.default_branch)

    @router.get("/edit/files")
    def files(branch: str = Query(default="main")) -> JSONResponse:
        """Listado de materiales ``.md`` de la rama con títulos (LIA-016)."""
        try:
            result = service().list_materials(branch)
        except EditError as exc:
            raise HTTPException(status_code=exc.status_code, detail=exc.detail) from exc
        return JSONResponse(result.model_dump(mode="json"))

    @router.get("/edit/content")
    def content(file: str = Query(min_length=1), branch: str = Query(min_length=1)) -> JSONResponse:
        """Contenido del fichero en la rama, con el SHA del HEAD (LIA-002)."""
        try:
            result = service().content(file, branch)
        except EditError as exc:
            raise HTTPException(status_code=exc.status_code, detail=exc.detail) from exc
        return JSONResponse(result.model_dump(mode="json"))

    @router.post("/edit/open")
    def open_edit(request: EditOpenRequest) -> JSONResponse:
        """Asegura la rama de edición del usuario y sirve el contenido (LIA-003)."""
        try:
            result = service().open(request.user, request.file)
        except EditError as exc:
            raise HTTPException(status_code=exc.status_code, detail=exc.detail) from exc
        except GitProviderError as exc:
            raise HTTPException(status_code=502, detail=str(exc)) from exc
        return JSONResponse(result.model_dump(mode="json"))

    @router.post("/edit/save")
    def save(request: SaveRequest) -> JSONResponse:
        """Guardado por SHA (LIA-004): 200 ok o 409 con rama de resolución (D5)."""
        try:
            result = service().save(request)
        except EditError as exc:
            raise HTTPException(status_code=exc.status_code, detail=exc.detail) from exc
        except GitProviderError as exc:
            raise HTTPException(status_code=502, detail=str(exc)) from exc
        status = 200 if result.status == "ok" else 409
        return JSONResponse(result.model_dump(mode="json"), status_code=status)

    @router.get("/edit/state")
    def state(
        file: str = Query(min_length=1),
        sha: str = Query(min_length=40, max_length=40),
        branch: str = Query(default="main"),
    ) -> JSONResponse:
        """Estado por comparación de SHAs (LIA-005): up_to_date / update_available."""
        try:
            result = service().state(file, sha, branch=branch)
        except EditError as exc:
            raise HTTPException(status_code=exc.status_code, detail=exc.detail) from exc
        except GitProviderError as exc:
            raise HTTPException(status_code=502, detail=str(exc)) from exc
        return JSONResponse(result.model_dump(mode="json"))

    return router
