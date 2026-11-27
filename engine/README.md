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
| `GET` | `/edit/content?file=&branch=` | Contenido + SHA del HEAD + `committed_at` (fecha del commit, ISO 8601 con zona) + `last_author` (autor Git del último commit que modificó el fichero: el usuario de Moodle en los guardados del editor) |
| `POST` | `/edit/open` | Asegura la rama `edicion/<user>/<file>` desde `main` (D4) |
| `POST` | `/edit/save` | Commit con optimistic locking: `200 {new_sha, committed_at}` o `409` con rama `resolucion/...` (D5). Acepta `images: [{path, content_base64}]` (PNG, JPEG, GIF; máx. 20, 2 MB cada una y 10 MB en total): Markdown e imágenes en un único commit |
| `GET` | `/edit/asset?file=&branch=&path=` | Imagen de la carpeta del material (ruta relativa a su `.md`; PNG, JPEG, GIF, WebP) para el editor; cualquier fallo, 404 |
| `GET` | `/edit/sync?file=&branch=` | ¿Trae `main` cambios del material que la rama no tiene? `up_to_date`, `clean` (con el texto actual y el fusionado), `conflict` (trozos con las tres versiones) o `conflict_other` (LIA-130) |
| `POST` | `/edit/merge` | Incorpora `main` a la rama: commit de fusión con el material resuelto si había conflicto; 409 si la rama o `main` cambiaron, 400 si quedan marcas |
| `GET` | `/edit/state?file=&sha=&branch=` | `update_available` si la carpeta del material cambió entre `sha` y el HEAD de la rama (`content_changed`); si no, `up_to_date`. Incluye `commits_ahead` |
| `POST` | `/publish` | Bytes del artefacto (`h5p`, `epub`, `pdf`, `web`, `pdfcontenido`) de un fichero en un SHA. Con `author` opcional, en los formatos de Pandoc (`web`, `pdfcontenido`) ese nombre sustituye al `author:` de la cabecera del material. Con `module` opcional, el PDF de contenido lleva en la portada el título del módulo y la unidad como subtítulo (sin él, la unidad como título). El PDF de contenido tiene portada (logo de la plantilla, título, subtítulo, autor y fecha de publicación), índice con la licencia debajo y, en cada página, la unidad a la izquierda de la cabecera y el autor a la derecha. El PDF de contenido convierte a PNG (Pillow) las imágenes WebP y GIF, que XeLaTeX no admite. Los formatos de liaex se generan desde una copia sin los otros `.md` de la carpeta del material (el H5P los empaquetaba) |
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
| `MATERIALES_LIASCRIPT_SHARED_SECRET` | sí | Secreto compartido con el plugin (ajuste «Secreto compartido con el engine» o `$CFG->local_liamateriales_service_secret`). Todas las rutas salvo `GET /health` exigen la firma HMAC-SHA256 del plugin (`X-Liamat-Timestamp`, `X-Liamat-Nonce`, `X-Liamat-Signature`; ±60 s, sin réplicas; el nonce aleatorio entra en la firma para que dos peticiones idénticas en el mismo segundo no se tomen por réplica); si no, 401. Sin él, el servicio no arranca salvo con `MATERIALES_LIASCRIPT_ALLOW_UNSIGNED=1` (solo desarrollo) |
| `MATERIALES_LIASCRIPT_WORKDIR` / `MATERIALES_LIASCRIPT_HOST_WORKDIR` | no | Traducción de rutas docker-in-docker para liaex (mismo bind mount visto desde el contenedor y desde el host) |
| `MATERIALES_LIASCRIPT_LIAEX_NETWORK` | no | Red del contenedor de liaex (defecto `none`). `bridge` si los materiales usan `import:` de plantillas, que liaex descarga al exportar |
| `MATERIALES_LIASCRIPT_THEME` | no | Tema de los contenidos exportados: `verde` (defecto, el del editor) o `none` (aspecto original de LiaScript). Las hojas están en `publish/themes/` y se generan desde el editor con `npm run theme:liascript` |
| `MATERIALES_LIASCRIPT_PANDOC_IMAGE` | no | Imagen de Pandoc de los formatos `web` y `pdfcontenido` (defecto `pandoc/extra:latest`; requiere Pandoc ≥ 3, XeLaTeX y `pandoc-latex-environment`) |
| `MATERIALES_LIASCRIPT_PDF_THEME_DIR` | no | Plantilla del PDF de contenido (`fpvirtual.latex`, `content-boxes.*`). Defecto: la copia incluida en `publish/pandoc/fpvirtual-theme/` |
| `MATERIALES_LIASCRIPT_LOG_FORMAT` | no | `console` (defecto) o `json` |
| `HOST` / `PORT` | no | Interfaz y puerto (defecto `0.0.0.0:8000`) |

## Docker

`Dockerfile` (python:3.12-slim, `git` + `docker-cli`, usuario no-root,
healthcheck en `/health`). Para publicar necesita el socket de Docker del
host montado (`/var/run/docker.sock`) y el grupo `docker` (`--group-add`).
El repo de contenidos se monta en `/content`.

### Superficie expuesta (LIA-060, LIA-061)

El navegador nunca habla con el engine: solo Moodle (servidor a servidor).
Como el engine tiene el socket de Docker (equivale a root en el host):

- **Sin puerto publicado.** El contenedor va solo en la red Docker de
  Moodle, sin `ports:` (o, como mucho, `127.0.0.1:8000:8000` para
  diagnóstico local). Moodle lo alcanza por el nombre del servicio:
  `$CFG->local_liamateriales_service_url = 'http://<servicio>:8000';`.
- **Firma obligatoria.** `MATERIALES_LIASCRIPT_SHARED_SECRET` en el engine y
  el mismo valor en Moodle (`$CFG->local_liamateriales_service_secret` en
  `config.php`, o el ajuste del plugin). Generarlo con `openssl rand -hex 32`.
  Los relojes de Moodle y del engine deben estar sincronizados (±60 s).

Comprobación: desde otra máquina, `curl http://<host>:<puerto>/health` no
conecta; desde el contenedor de Moodle, `curl http://<servicio>:8000/health`
responde y cualquier otra ruta sin firma da 401.

## Integración continua

`.github/workflows/ci.yml` en la raíz del repositorio: ruff, mypy strict y
pytest sobre `engine/`.
