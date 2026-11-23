# moodle-liascript-git — LiaEditor–Moodle

Edición de materiales docentes en **LiaScript Markdown** alojados en Git,
directamente desde Moodle, con **LiaEdit (LiveEditor)** self-hosted como
editor. **Git es la única fuente de verdad del contenido**; Moodle gestiona
la vinculación, el guardado transparente y la publicación de artefactos
(H5P, ePub, PDF) en los cursos.

- **Documento de arquitectura v1:** [docs/desarrollo-liaeditor-moodle-v1.md](docs/desarrollo-liaeditor-moodle-v1.md) (decisiones D1–D10)
- **Especificación del plugin `mod_liamd`:** [docs/cambio-curso-a-liascript.md](docs/cambio-curso-a-liascript.md)
- **Roadmap del fork LiveEditor:** [docs/roadmap-liveeditor-fork.md](docs/roadmap-liveeditor-fork.md)
- **Bitácora de desarrollo (estado actual):** [BITACORA.md](BITACORA.md)

> Este proyecto evoluciona del sistema "Moodle Course as Code" (Books en
> `mod_book`). Ese flujo se eliminó del código en noviembre de 2026; su
> documentación queda archivada en [docs/historico/](docs/historico/).

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
  (`mod_h5pactivity` / `mod_resource`) y registra
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
│  plugin local_liamateriales («Materiales Editables»)           │
│  Catálogo/UI · content.php (proxy md) · save.php / publish.php │
│  Paso "Publicar": mod_h5pactivity / mod_resource (H5P/ePub/PDF)│
│  + registro (courseid, file, sha, tipo, cmid)                  │
└──────────────────────────────┬─────────────────────────────────┘
                               │ server-to-server
┌──────────────────────────────▼─────────────────────────────────┐
│  ENGINE (FastAPI, Python)                                      │
│  Cliente Git (token servidor) · ramas edicion/* · conflictos   │
│  Endpoints: /edit/content, /edit/save, /publish                │
│  liaex (Docker) para H5P/ePub/PDF                              │
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
│   ├── src/materiales_liascript/  # app, api, edit, publish, git
│   └── tests/
├── plugin/                 # plugin Moodle «Materiales Editables» (local_liamateriales)
├── docs/                   # arquitectura v1, spec mod_liamd, roadmaps,
│                           # tasklist v1; historico/ = Course as Code
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
CONTENT_REPO=/ruta/al/clon make serve
```

Las credenciales nunca se commitean: el push del engine usa las del
`origin` del clon de contenidos. Detalle en [engine/README.md](engine/README.md).

## Documentación

- [Flujos de datos — plugin, engine y editor](docs/Flujos%20de%20datos%20—%20plugin,%20engine%20y%20editor.md) — diagramas de abrir, guardar y publicar.
- [Desarrollo LiaEditor–Moodle v1](docs/desarrollo-liaeditor-moodle-v1.md) — decisiones de arquitectura D1–D10 y fases.
- [Especificación `mod_liamd` v0.1.0](docs/cambio-curso-a-liascript.md) — requisitos funcionales, endpoints, BD, capacidades.
- [Roadmap del fork LiveEditor](docs/roadmap-liveeditor-fork.md) — cambios C1–C10 sobre el editor, hitos LIV-1–6.
- [Tasklist](docs/Tasklist%20—%20Desarrollo%20LiaEditor–Moodle%20v1.md) — trabajo pendiente (v2): seguridad, editor, robustez del backend y producción.
- [Bitácora](BITACORA.md) — progreso real y registro de sesiones.
- Documentación histórica (Course as Code, flujo eliminado): [docs/historico/](docs/historico/).

## Licencia

GPL-3.0-or-later (ver [LICENSE](LICENSE)).
