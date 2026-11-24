"""Arranque del servicio web.

Sirve la app FastAPI (edición + publicación) con uvicorn, configurada por
entorno:

- ``CONTENT_REPO`` (obligatoria): ruta al clon del repo de contenidos (con
  ``origin`` con credenciales de escritura para el push, D1).
- ``CONTENT_REPO_URL`` (opcional): URL del repo en la forja, para la
  ``diff_url`` de los conflictos.
- ``MATERIALES_LIASCRIPT_WORKDIR`` / ``MATERIALES_LIASCRIPT_HOST_WORKDIR`` (opcionales):
  traducción de rutas docker-in-docker para liaex.
- ``MATERIALES_LIASCRIPT_LIAEX_NETWORK`` (opcional, defecto ``none``): red del
  contenedor de liaex; ``bridge`` si los materiales importan plantillas.
- ``MATERIALES_LIASCRIPT_SCORM_VERSION`` (opcional, defecto ``1.2``): versión
  de SCORM del formato ``scorm`` (``1.2`` o ``2004``).
- ``MATERIALES_LIASCRIPT_THEME`` (opcional, defecto ``verde``): tema visual de
  los contenidos exportados (``publish/themes/<tema>.css``); ``none`` deja el
  aspecto original de LiaScript.
- ``MATERIALES_LIASCRIPT_SHARED_SECRET`` (obligatoria): secreto compartido
  con el plugin; el engine rechaza (401) las peticiones sin su firma HMAC
  (LIA-060). Solo para desarrollo, ``MATERIALES_LIASCRIPT_ALLOW_UNSIGNED=1``
  arranca sin él.
- ``MATERIALES_LIASCRIPT_LOG_FORMAT``: ``console`` (defecto) o ``json``.
- ``HOST`` / ``PORT``: interfaz y puerto de uvicorn (defaults 0.0.0.0:8000).

Uso local: ``CONTENT_REPO=/ruta/al/repo python -m materiales_liascript.serve``.
"""

from __future__ import annotations

import os
from pathlib import Path

import uvicorn
from fastapi import FastAPI

from materiales_liascript.app import create_app


def build_app() -> FastAPI:
    """Construye la app del servicio desde las variables de entorno."""
    content_repo = os.environ.get("CONTENT_REPO")
    if not content_repo:
        msg = "error: define CONTENT_REPO (ruta al repo de contenidos)"
        raise SystemExit(msg)
    secret = os.environ.get("MATERIALES_LIASCRIPT_SHARED_SECRET", "").strip()
    if not secret and os.environ.get("MATERIALES_LIASCRIPT_ALLOW_UNSIGNED") != "1":
        msg = (
            "error: define MATERIALES_LIASCRIPT_SHARED_SECRET (el mismo secreto que el "
            "ajuste «Secreto compartido con el engine» del plugin)"
        )
        raise SystemExit(msg)
    return create_app(
        Path(content_repo),
        content_repo_url=os.environ.get("CONTENT_REPO_URL"),
        shared_secret=secret or None,
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
