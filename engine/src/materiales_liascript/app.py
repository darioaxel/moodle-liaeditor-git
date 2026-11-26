"""App FastAPI del engine LiaEditor-Moodle.

Monta los dos routers que consume el plugin «Materiales Editables»:

- ``/edit/*`` (``materiales_liascript.api.edit``): ramas ``edicion/*``, contenido,
  guardado con optimistic locking por SHA y estado (D2-D5).
- ``/publish`` (``materiales_liascript.api.publish``): artefactos H5P/ePub/PDF con
  liaex (D7 fase 2).

Más ``GET /health`` para el healthcheck del contenedor.

Con secreto compartido, todas las rutas salvo ``/health`` exigen la firma
HMAC del plugin (``materiales_liascript.auth``, LIA-060).
"""

from __future__ import annotations

import os
import shutil
from pathlib import Path

from fastapi import FastAPI, Request
from fastapi.responses import Response
from starlette.middleware.base import RequestResponseEndpoint

from materiales_liascript.api.edit import EditApiDeps, create_edit_router
from materiales_liascript.api.publish import PublishApiDeps, create_publish_router
from materiales_liascript.auth import SignatureMiddleware
from materiales_liascript.git.local_provider import LocalGitProvider
from materiales_liascript.git.provider import GitProvider, GitProviderError
from materiales_liascript.logging_config import bound_correlation, configure_logging
from materiales_liascript.publish.pandoc_export import PandocExporter
from materiales_liascript.publish.service import ArtifactExecutor, LiaexDockerExecutor
from materiales_liascript.publish.theme import load_theme_css


def create_app(  # noqa: PLR0913 - dependencias inyectables, todas con defecto
    content_root: Path,
    *,
    provider: GitProvider | None = None,
    content_repo_url: str | None = None,
    executor: ArtifactExecutor | None = None,
    pandoc: PandocExporter | None = None,
    shared_secret: str | None = None,
) -> FastAPI:
    """Crea la app sobre el repo de contenidos ``content_root``.

    Args:
        content_root: clon del repo de contenidos.
        provider: provider Git; si es ``None`` se intenta ``LocalGitProvider``
            y, si ``content_root`` no es un repo Git, los endpoints responden 503.
        content_repo_url: URL del repo en la forja (``CONTENT_REPO_URL``) para
            construir la ``diff_url`` de los conflictos 409.
        pandoc: generador de la web estática y del PDF de contenido; ``None``
            → ``PandocExporter`` si hay Docker en el host.
        executor: ejecutor de artefactos para ``POST /publish``; ``None`` →
            ``LiaexDockerExecutor`` si hay Docker en el host, si no 503.
        shared_secret: secreto compartido con el plugin; si se da, todas las
            rutas salvo ``/health`` exigen su firma HMAC (401 si falta o no vale).
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
            theme_css=load_theme_css(os.environ.get("MATERIALES_LIASCRIPT_THEME", "verde")),
        )
        executor = docker_executor if docker_executor.available() else None
    if pandoc is None and shutil.which("docker"):
        # Web estática y PDF de contenido (docs/generacion_web).
        theme_dir = os.environ.get("MATERIALES_LIASCRIPT_PDF_THEME_DIR")
        pandoc = PandocExporter(
            os.environ.get("MATERIALES_LIASCRIPT_PANDOC_IMAGE", "pandoc/extra:latest"),
            theme_dir=Path(theme_dir) if theme_dir else None,
            workdir=os.environ.get("MATERIALES_LIASCRIPT_WORKDIR"),
            host_workdir=os.environ.get("MATERIALES_LIASCRIPT_HOST_WORKDIR"),
        )

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
        return {
            "ok": True,
            "git": provider is not None,
            "publish": executor is not None,
            "pandoc": pandoc is not None,
        }

    app.include_router(create_edit_router(EditApiDeps(git=provider, repo_url=content_repo_url)))
    app.include_router(
        create_publish_router(PublishApiDeps(git=provider, executor=executor, pandoc=pandoc))
    )
    if shared_secret:
        app.add_middleware(SignatureMiddleware, secret=shared_secret)
    return app
