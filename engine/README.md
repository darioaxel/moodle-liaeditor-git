# materiales_liascript-git (engine)

Engine del sistema **LiaEditor–Moodle**: el único componente que habla con
Git (D2). El plugin Moodle «Materiales Editables» (`local_liamateriales`) lo
consume server-to-server para editar y publicar materiales LiaScript.

## Arquitectura

```text
src/materiales_liascript/
├── app.py            # create_app(): monta /edit/*, /publish y /health
├── serve.py          # arranque uvicorn configurado por entorno
├── logging_config.py # structlog + correlation id por petición
├── api/              # routers FastAPI (edit.py, publish.py)
├── edit/             # ramas edicion/<user>/<file>, guardado por SHA, conflictos
├── publish/          # artefactos H5P/ePub/PDF con liaex (Docker)
└── git/              # GitProvider + LocalGitProvider (plumbing, worktrees)
```

## Endpoints

| Método | Ruta | Uso |
| --- | --- | --- |
| `GET` | `/edit/files?branch=` | Materiales `.md` de la rama con su título LiaScript |
| `GET` | `/edit/content?file=&branch=` | Contenido + SHA del HEAD |
| `POST` | `/edit/open` | Asegura la rama `edicion/<user>/<file>` desde `main` (D4) |
| `POST` | `/edit/save` | Commit con optimistic locking: `200 {new_sha}` o `409` con rama `resolucion/...` (D5) |
| `GET` | `/edit/state?file=&sha=&branch=` | `up_to_date` / `update_available` por comparación de SHAs |
| `POST` | `/publish` | Bytes del artefacto (`h5p`, `epub`, `pdf`) de un fichero en un SHA |
| `GET` | `/health` | Healthcheck |

Sin repo Git, `/edit/*` y `/publish` responden 503; sin Docker en el host,
`/publish` responde 503.

## Desarrollo

Requisitos: Python 3.12 y `git`.

```bash
python3.12 -m venv .venv && source .venv/bin/activate
pip install -e .[dev]
make check          # ruff + ruff format --check + mypy --strict + pytest
CONTENT_REPO=/ruta/al/clon make serve
```

## Variables de entorno

| Variable | Obligatoria | Descripción |
| --- | --- | --- |
| `CONTENT_REPO` | sí | Clon del repo de contenidos; su `origin` necesita credenciales de escritura (push de ramas, D1) |
| `CONTENT_REPO_URL` | no | URL del repo en la forja, para la `diff_url` de los conflictos |
| `MATERIALES_LIASCRIPT_WORKDIR` / `MATERIALES_LIASCRIPT_HOST_WORKDIR` | no | Traducción de rutas docker-in-docker para liaex (mismo bind mount visto desde el contenedor y desde el host) |
| `MATERIALES_LIASCRIPT_LIAEX_NETWORK` | no | Red del contenedor de liaex (defecto `none`). `bridge` si los materiales usan `import:` de plantillas, que liaex descarga al exportar |
| `MATERIALES_LIASCRIPT_LOG_FORMAT` | no | `console` (defecto) o `json` |
| `HOST` / `PORT` | no | Interfaz y puerto (defecto `0.0.0.0:8000`) |

## Docker

`Dockerfile` (python:3.12-slim, `git` + `docker-cli`, usuario no-root,
healthcheck en `/health`). Para publicar necesita el socket de Docker del
host montado (`/var/run/docker.sock`) y el grupo `docker` (`--group-add`).
El repo de contenidos se monta en `/content`.

## Integración continua

`.github/workflows/ci.yml` en la raíz del repositorio: ruff, mypy strict y
pytest sobre `engine/`.
