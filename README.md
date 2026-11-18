# LiaEditor–Moodle

Edición de materiales docentes en **LiaScript Markdown** alojados en Git,
directamente desde Moodle, con **LiaEdit (LiveEditor)** self-hosted como
editor. **Git es la única fuente de verdad del contenido**; Moodle gestiona
la vinculación, el guardado transparente y la publicación de artefactos
(SCORM, H5P, ePub, PDF) en los cursos.

- **Documento de arquitectura v1:** [docs/desarrollo-liaeditor-moodle-v1.md](docs/desarrollo-liaeditor-moodle-v1.md) (decisiones D1–D10)
- **Especificación del plugin `mod_liamd`:** [docs/cambio-curso-a-liascript.md](docs/cambio-curso-a-liascript.md)
- **Roadmap del fork LiveEditor:** [docs/roadmap-liveeditor-fork.md](docs/roadmap-liveeditor-fork.md)
- **Bitácora de desarrollo (estado actual):** [BITACORA.md](BITACORA.md)

> Este proyecto evoluciona del sistema "Moodle Course as Code"
> (especificación v0.2 en [docs/](docs/)). El engine Python y el plugin
> `local_courseascode` desarrollados para esa Beta se reutilizan como base:
> el engine sigue siendo el único que habla con Git, y el plugin se
> extiende con los endpoints de edición y publicación.

## Decisiones clave (D1–D10)

- **Git transparente para el profesor** (D1): commits con token de
  servidor, sin cuentas personales de GitHub/GitLab.
- **La lógica Git vive en el engine** (D2): no se duplica cliente Git ni
  credenciales en PHP.
- **Guardado vía `save.php` del plugin** (D3): fachada que delega en el
  engine → el plugin conoce el SHA resultante → trazabilidad
  curso↔versión↔módulo.
- **Ramas de edición personales** (D4): `edicion/<usuario>/<archivo>`,
  una por usuario y archivo; `main` protegido.
- **Optimistic locking por SHA** (D5): en conflicto, rama
  `resolucion/<user>/<timestamp>-<archivo>` y respuesta 409 — el último
  escritor no gana silenciosamente.
- **Paso "Publicar en el curso"** (D6): el plugin crea el módulo
  (`mod_scorm` / `mod_h5pactivity` / recurso) y registra
  `(courseid, file, sha, tipo, cmid)`.
- **Exportación** (D7): Fase 1 con el exporter en navegador (nativo de
  LiaEdit); Fase 2 con `liaex` server-side en el engine.
- **LiaEdit self-hosted** (D8) en dominio propio, sin dependencia del
  LiveEditor público.
- **Autenticación editor↔plugin con token de un solo uso** (D9), ligado
  a usuario+fichero+rama.
- **Fork con parche mínimo y genérico** (D10): "si el documento declara
  un endpoint de guardado, el editor muestra Guardar/Publicar contra ese
  endpoint" — válido para cualquier LMS y candidato a upstream.

## Arquitectura

```text
┌──────────────────────────── MOODLE ────────────────────────────┐
│  plugin (evolución de local_courseascode)                      │
│  Catálogo/UI · content.php (proxy md) · save.php / publish.php │
│  Paso "Publicar": mod_scorm / mod_h5pactivity / recurso        │
│  + registro (courseid, file, sha, tipo, cmid)                  │
└──────────────────────────────┬─────────────────────────────────┘
                               │ server-to-server
┌──────────────────────────────▼─────────────────────────────────┐
│  ENGINE (FastAPI, Python)                                      │
│  Cliente Git (token servidor) · ramas edicion/* · conflictos   │
│  Endpoints: /edit/content, /edit/save, /publish                │
│  Fase 2: liaex (Docker) para SCORM/H5P/ePub/PDF                │
└──────────────────────────────┬─────────────────────────────────┘
                               │ HTTPS API Git
┌──────────────────────────────▼─────────────────────────────────┐
│  GIT (GitHub/GitLab) — única fuente de verdad                  │
└────────────────────────────────────────────────────────────────┘
                               ▲
┌──────────────────────────────┴─────────────────────────────────┐
│  LIAEDIT SELF-HOSTED (fork FPVirtual/LiveEditor)               │
│  Fase 1: sin modificar (carga ?url=content.php, export nativo) │
│  Fase 2: parche mínimo → botones Guardar/Publicar              │
└────────────────────────────────────────────────────────────────┘
```

## Este repositorio

```text
├── engine/                 # engine Python 3.12 (FastAPI, src-layout)
│   ├── src/courseascode/   # domain, manifests, git, content, catalog,
│   │                       # deployment, moodle, api, web, cli
│   └── tests/
├── plugin/                 # plugin Moodle «Materiales Editables» (local_courseascode)
├── docs/                   # arquitectura v1, spec mod_liamd, roadmap fork,
│                           # guías, diagramas, tasklists
├── BITACORA.md             # estado del desarrollo (actualizar cada sesión)
└── .github/workflows/      # CI del engine (ruff + mypy strict + pytest)
```

Repositorios relacionados:

- **Fork del editor:** <https://github.com/FPVirtual/LiveEditor>
  (fork de <https://github.com/LiaScript/LiveEditor>) — parche de
  guardado/publicación descrito en el roadmap.
- **Contenidos de prueba:** `FPVirtual/contenidos-test`.

## Desarrollo del engine

Requisitos: Python 3.12. En `engine/`:

```bash
python3.12 -m venv .venv && source .venv/bin/activate
pip install -e .[dev]
make check          # ruff + ruff format --check + mypy --strict + pytest
courseascode --help
```

Las credenciales nunca se commitean; el CLI falla ruidosamente si faltan las
variables `MOODLE_URL`, `MOODLE_TOKEN`, `CONTENT_REPO_URL`, `CONTENT_REPO_KEY`.

## Documentación

- [Desarrollo LiaEditor–Moodle v1](docs/desarrollo-liaeditor-moodle-v1.md) — decisiones de arquitectura D1–D10 y fases.
- [Especificación `mod_liamd` v0.1.0](docs/cambio-curso-a-liascript.md) — requisitos funcionales, endpoints, BD, capacidades.
- [Roadmap del fork LiveEditor](docs/roadmap-liveeditor-fork.md) — cambios C1–C10 sobre el editor, hitos LIV-1–6.
- [Tasklist LiaEditor–Moodle v1](docs/Tasklist%20—%20Desarrollo%20LiaEditor–Moodle%20v1.md) — fases 1–3 con DoD, pistas engine/plugin/fork (LIA-001–055).
- [Guía de autor](docs/Guía%20de%20autor.md) y [Guía de operación](docs/Guía%20de%20operación.md).
- [Bitácora](BITACORA.md) — progreso real y registro de sesiones.
- Documentación histórica (Course as Code): [Especificación v0.2](docs/Especificación%20Moodle%20Course%20as%20Code%20v0.2.md), [diagramas de secuencia](docs/Diagramas%20de%20secuencia%20—%20Moodle%20Course%20as%20Code.md), [tasklist del engine](docs/Tasklist%20—%20Desarrollo%20courseascode-engine.md).

## Licencia

GPL-3.0-or-later (ver [LICENSE](LICENSE)).
