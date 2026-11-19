"""Arranque del servicio web.

Sirve la app FastAPI (edición + publicación) con uvicorn, configurada por
entorno:

- ``CONTENT_REPO`` (obligatoria): ruta al clon del repo de contenidos (con
  ``origin`` con credenciales de escritura para el push, D1).
- ``CONTENT_REPO_URL`` (opcional): URL del repo en la forja, para la
  ``diff_url`` de los conflictos.
- ``COURSEASCODE_WORKDIR`` / ``COURSEASCODE_HOST_WORKDIR`` (opcionales):
  traducción de rutas docker-in-docker para liaex.
- ``COURSEASCODE_LOG_FORMAT``: ``console`` (defecto) o ``json``.
- ``HOST`` / ``PORT``: interfaz y puerto de uvicorn (defaults 0.0.0.0:8000).

Uso local: ``CONTENT_REPO=/ruta/al/repo python -m courseascode.serve``.
"""

from __future__ import annotations

import os
from pathlib import Path

import uvicorn
from fastapi import FastAPI

from courseascode.app import create_app


def build_app() -> FastAPI:
    """Construye la app del servicio desde las variables de entorno."""
    content_repo = os.environ.get("CONTENT_REPO")
    if not content_repo:
        msg = "error: define CONTENT_REPO (ruta al repo de contenidos)"
        raise SystemExit(msg)
    return create_app(
        Path(content_repo),
        content_repo_url=os.environ.get("CONTENT_REPO_URL"),
    )


def main() -> None:
    """Sirve la app con uvicorn (host/puerto por entorno)."""
    uvicorn.run(
        build_app(),
        host=os.environ.get("HOST", "0.0.0.0"),
        port=int(os.environ.get("PORT", "8000")),
    )


if __name__ == "__main__":
    main()
