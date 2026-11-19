"""App FastAPI del engine LiaEditor-Moodle.

Monta los dos routers que consume el plugin «Materiales Editables»:

- ``/edit/*`` (``materiales_liascript.api.edit``): ramas ``edicion/*``, contenido,
  guardado con optimistic locking por SHA y estado (D2-D5).
- ``/publish`` (``materiales_liascript.api.publish``): artefactos H5P/ePub/PDF con
  liaex (D7 fase 2).

Más ``GET /health`` para el healthcheck del contenedor.
"""

from __future__ import annotations

import os
from pathlib import Path

from fastapi import FastAPI, Request
from fastapi.responses import Response
from starlette.middleware.base import RequestResponseEndpoint

from materiales_liascript.api.edit import EditApiDeps, create_edit_router
from materiales_liascript.api.publish import PublishApiDeps, create_publish_router
from materiales_liascript.git.local_provider import LocalGitProvider
from materiales_liascript.git.provider import GitProvider, GitProviderError
from materiales_liascript.logging_config import bound_correlation, configure_logging
from materiales_liascript.publish.service import ArtifactExecutor, LiaexDockerExecutor


def create_app(
    content_root: Path,
    provider: GitProvider | None = None,
    content_repo_url: str | None = None,
    executor: ArtifactExecutor | None = None,
) -> FastAPI:
    """Crea la app sobre el repo de contenidos ``content_root``.

    Args:
        content_root: clon del repo de contenidos.
        provider: provider Git; si es ``None`` se intenta ``LocalGitProvider``
            y, si ``content_root`` no es un repo Git, los endpoints responden 503.
        content_repo_url: URL del repo en la forja (``CONTENT_REPO_URL``) para
            construir la ``diff_url`` de los conflictos 409.
        executor: ejecutor de artefactos para ``POST /publish``; ``None`` →
            ``LiaexDockerExecutor`` si hay Docker en el host, si no 503.
    """
    configure_logging()
    if provider is None:
        try:
            provider = LocalGitProvider(content_root)
        except GitProviderError:
            provider = None
    if executor is None:
        # Docker-in-docker: WORKDIR/HOST_WORKDIR traducen las rutas de -v
        # entre la vista del contenedor y la del demonio del host.
        docker_executor = LiaexDockerExecutor(
            workdir=os.environ.get("MATERIALES_LIASCRIPT_WORKDIR"),
            host_workdir=os.environ.get("MATERIALES_LIASCRIPT_HOST_WORKDIR"),
            network=os.environ.get("MATERIALES_LIASCRIPT_LIAEX_NETWORK", "none"),
        )
        executor = docker_executor if docker_executor.available() else None

    app = FastAPI(title="materiales_liascript-git engine", docs_url=None, redoc_url=None)

    @app.middleware("http")
    async def _correlation_middleware(
        request: Request, call_next: RequestResponseEndpoint
    ) -> Response:
        """Correlation id por petición (header ``X-Correlation-Id`` o generado)."""
        with bound_correlation(request.headers.get("X-Correlation-Id")):
            return await call_next(request)

    @app.get("/health")
    def health() -> dict[str, bool]:
        """Healthcheck: el proceso responde y si hay repo Git / ejecutor."""
        return {"ok": True, "git": provider is not None, "publish": executor is not None}

    app.include_router(create_edit_router(EditApiDeps(git=provider, repo_url=content_repo_url)))
    app.include_router(create_publish_router(PublishApiDeps(git=provider, executor=executor)))
    return app
