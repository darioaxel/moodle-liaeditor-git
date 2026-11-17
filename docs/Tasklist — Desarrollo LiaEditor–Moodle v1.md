# Tasklist — Desarrollo LiaEditor–Moodle v1

> Basado en `docs/desarrollo-liaeditor-moodle-v1.md` (decisiones D1–D10),
> `docs/cambio-curso-a-liascript.md` (spec `mod_liamd` v0.1.0) y
> `docs/roadmap-liveeditor-fork.md` (cambios C1–C10, hitos LIV-1–6).
> Cubre las **tres pistas**: engine Python, plugin Moodle (🔌) y fork de
> LiaEdit (🍴, <https://github.com/FPVirtual/LiveEditor>). IDs de tarea
> `LIA-nnn` para trazabilidad.
>
> Leyenda de estimación: **S** < 1 día · **M** 1–3 días · **L** 3–5 días · **XL** > 5 días.

---

## Convenciones transversales

- Engine: las convenciones vigentes (`mypy --strict`, ruff, pytest desde el
  primer commit, modelos pydantic). El código nuevo vive en un módulo
  `edit/` propio; el flujo legado `mod_book` (`deployment/`, renderer,
  planner) no se toca salvo que la decisión sobre books legados diga otra
  cosa (cuestión abierta nº 5 de la v1).
- Plugin: todo endpoint nuevo valida token de un solo uso + capacidad +
  sanitización de rutas (path traversal) antes de tocar Git o el engine.
- **El contrato editor↔plugin (roadmap §5) es la fuente de verdad**: clave
  genérica `liascript-lms` en el front matter; cualquier cambio se hace
  primero en plugin/engine y luego se adapta el fork.
- Cada fase termina con su "Definition of Done" (DoD) verificable.

## Decisiones pendientes que condicionan tareas

Resolver antes (o a más tardar al inicio de la tarea afectada):

1. **Botón Guardar en fase 1** (cuestión abierta nº 1 de la v1): userscript
   vs. adelantar el fork → afecta a LIA-022.
2. **Assets del editor en v1** (roadmap §7.1): solo `.md` principal o
   multipart → afecta a LIA-004/LIA-013.
3. **H5P vs `mod_h5pactivity`** (cuestión abierta nº 3): PoC previa en
   LIA-043 antes de soportar el formato.
4. **Books legados** (cuestión abierta nº 5): convivencia o migración →
   afecta a LIA-017 y al alcance de Fase 3.
5. **Colaboración WebRTC** (cuestión abierta nº 6): desactivada por
   defecto en el `.env` del fork (LIA-033).

---

## FASE 1 — MVP funcional (sin fork)

**Objetivo:** flujo completo editar → guardar → exportar en navegador →
publicar, con LiaEdit self-hosted **sin modificar** (D7 fase 1).

### Pista engine

- [x] **LIA-001** (M) Módulo `edit/` con modelos pydantic: `EditSession`
  (user, file, branch, base_sha), `SaveRequest` (file, branch,
  original_sha, content), `SaveResult` (ok con `new_sha` | conflict con
  `resolution_branch`, `diff_url`, `current_sha` | error). Límite 1 MB.
- [x] **LIA-002** (M) `GET /edit/content?file=&branch=`: contenido del
  fichero en la rama + SHA actual. 404 si no existe; validación de ruta.
- [x] **LIA-003** (L) Gestión de ramas de edición `edicion/<user>/<file>`
  (D4): crear desde `main` si no existe, reutilizar si existe, reset a
  `main`, sanitización del nombre (lowercase, sin espacios, máx 100),
  detección de rama *stale* si el fichero se renombra en `main`.
- [x] **LIA-004** (L) `POST /edit/save` (D5): si `current_sha ==
  original_sha` → commit con token de servidor y `200 {new_sha}`; si
  difiere → rama `resolucion/<user>/<timestamp>-<file>`, commit ahí y
  `409 {resolution_branch, diff_url, current_sha}`.
- [x] **LIA-005** (M) `GET /edit/state?course=&file=` (o ampliación del
  endpoint de status): estado por comparación de SHAs
  (`UP_TO_DATE`/`UPDATE_AVAILABLE`, §5.3 de la v1), sin planner.
- [x] **LIA-006** (M) Tests E2E sobre fixture Git: abrir → guardar →
  guardar de nuevo → conflicto con commit externo → rama de resolución.
  Contrato documentado en `moodle/functions.py` o módulo equivalente.

### Pista plugin 🔌

- [x] **LIA-010** (M) Tablas nuevas (`install.xml` + `upgrade.php`):
  despliegues simplificada (`courseid, source_file, git_commit,
  artifact_type, cmid, deployed_at, deployed_by, status`) y sesiones de
  edición (`user_id, file, branch, opened_at, last_save, closed_at,
  last_sha`).
- [x] **LIA-011** (M) Tokens de un solo uso (D9): tabla, generación ligada
  a usuario+fichero+rama, caducidad corta, validación, invalidación y
  renovación (contrato C6 del roadmap).
- [x] **LIA-012** (S) Settings del plugin: URL base del editor, origen
  CORS permitido, tamaño máximo de fichero, duración del token.
- [x] **LIA-013** (L) `content.php` (RF-03 de la spec): valida token →
  pide contenido al engine (`/edit/content`) → responde markdown con el
  bloque `liascript-lms` inyectado en el front matter. CORS restringido
  al dominio del editor, `Cache-Control: no-cache`, rate limiting
  60 req/min, sanitización de ruta, 403/404/409 según caso.
- [x] **LIA-014** (L) `save.php` (RF-04): valida token + capacidad →
  delega en `POST /edit/save` del engine → registra sesión de edición con
  el `new_sha` (D3: trazabilidad antes/después del commit) → propaga
  200/409/403 con los mensajes del engine.
- [x] **LIA-015** (S) `token.php`: endpoint de renovación de token sin
  guardar (ping del editor, contrato C6).
- [x] **LIA-016** (M) Capacidades `edit` / `editmain` y botón "Editar" en
  el catálogo: genera token, crea/reutiliza rama vía engine y redirige a
  `LIAEDIT_URL/?url=<content.php...&token=...>` en pestaña nueva.
- [x] **LIA-017** (L) Página "Publicar" (D6 fase 1): lista los ficheros
  editados por el usuario con su último `new_sha`; subida de artefacto
  (zip SCORM / h5p / epub / pdf); creación o actualización del módulo
  (`mod_scorm`, `mod_h5pactivity`, `mod_resource`); registro del
  despliegue `(courseid, file, sha, tipo, cmid)`.
- [x] **LIA-018** (M) Panel de curso adaptado: estado por SHA
  (UP_TO_DATE/UPDATE_AVAILABLE) consumiendo LIA-005; convive con el panel
  legado de Books mientras no se decida la migración (decisión nº 4).

### Pista infraestructura

- [ ] **LIA-020** (M) LiaEdit self-hosted sin modificar (D8): build
  `npm ci && npm run build` del fork → `dist/` tras Nginx con HTTPS en
  dominio propio; `.env` mínimo documentado.
- [ ] **LIA-021** (S) Repo de contenidos preparado para LiaScript:
  ficheros `.md` con cabecera LiaScript en `contenidos-test` (o repo
  nuevo) para las pruebas del flujo.
- [x] **LIA-022** (S) Resolver decisión nº 1 (Guardar en fase 1): PoC de
  userscript contra `save.php` **o** adelantar LIA-030/031. Documentar la
  elección en la bitácora.

**DoD Fase 1:** contra el Moodle de pruebas — un profesor pulsa "Editar"
en el catálogo, LiaEdit self-hosted abre el `.md` vía `content.php` con el
bloque `liascript-lms`; un guardado POST a `save.php` produce commit en
`edicion/<user>/<file>` y la sesión queda registrada con el SHA nuevo; un
commit externo provoca el 409 con rama `resolucion/...`; el profesor
exporta SCORM en el navegador y lo sube en "Publicar" → el módulo existe
en el curso y el despliegue queda registrado (curso↔SHA↔cmid); el panel
muestra `UP_TO_DATE` y pasa a `UPDATE_AVAILABLE` al avanzar el HEAD.

---

## FASE 2 — Un clic (fork 🍴 + `liaex` server-side)

**Objetivo:** botones Guardar/Publicar dentro del editor (D10) y
publicación sin descarga manual (D7 fase 2).

### Pista fork 🍴 (mapeo directo del roadmap)

- [x] **LIA-030** (M) **LIV-1** = C1+C2: ruta `?/open/file/:fileUrl`
  editable (vista `OpenFile.vue` + entrada en el router) y extracción del
  bloque `liascript-lms` a `meta.lms` (módulo `src/ts/lms/config.ts`, el
  bloque se elimina del documento antes de importarlo).
- [x] **LIA-031** (M) **LIV-2** = C3+C4: `src/ts/lms/LmsBridge.ts`
  (save/publish/409/refresh) y componente `LmsSaveBar.vue` con botón
  Guardar en la toolbar (solo si hay `meta.lms`), atajo Ctrl+S y badge de
  solo lectura.
- [x] **LIA-032** (M) **LIV-3** = C5+C6: modal de conflicto 409 (ver
  diff, recargar versión actual, forzar la mía) y renovación del token de
  un solo uso (por respuesta 200 y por ping a `token.php`).
- [x] **LIA-033** (S) **LIV-4** = C7+C8+C9: flag `LIAEDIT_HIDE_GIT` que
  oculta integraciones Git/OAuth, `.env.example` + branding FPVirtual,
  i18n `lms.*` en `en.json` y `es.json`.
- [x] **LIA-034** (S) **LIV-5** = C10: GitHub Action de build (`npm ci &&
  npm run build`, artefacto `dist/`) y estrategia de rebase documentada
  en el README del fork (tags `fpvirtual-vX.Y.Z`).

### Pista engine + plugin 🔌

- [ ] **LIA-040** (L) `POST /publish` en el engine: wrapper de `liaex`
  (Docker) para SCORM 2004, ePub y PDF; PDF con `--shm-size=1g` y cola
  con más recursos; timeouts (300 s/formato), reintentos y error parcial
  por formato.
- [ ] **LIA-041** (M) `publish.php` en el plugin: valida token → delega
  en `POST /publish` → crea/actualiza el módulo y registra el despliegue
  (misma lógica de LIA-017, sin subida manual).
- [ ] **LIA-042** (M) **LIV-6**: botón Publicar en el fork (desplegable
  de formatos, solo si `publish_endpoint` presente) contra
  `publish.php`.
- [ ] **LIA-043** (M) PoC H5P (decisión nº 3): generar paquete con el
  exporter y verificar tipo y tracking en `mod_h5pactivity` antes de
  habilitar el formato.

**DoD Fase 2:** editar → Guardar en la toolbar persiste en la rama y el
SHA visible se actualiza; un commit externo muestra el modal 409; una
sesión larga no caduca (token renovado); Publicar crea el módulo SCORM en
Moodle sin descarga manual y queda registrado; Action del fork en verde;
build sin opciones Git y textos en español.

---

## FASE 3 — Producción

- [ ] **LIA-050** (M) Monitorización y logs: registro de commits,
  guardados y publicaciones (tabla de log o auditoría del engine);
  notificación al admin si el token de Git caduca (modo solo lectura).
- [ ] **LIA-051** (S) Cron de limpieza de sesiones de edición
  abandonadas (`closed_at` null > 24 h) y degradación graceful con último
  contenido cacheado si Git no responde.
- [ ] **LIA-052** (M) Documentación de usuario: guía del profesor
  (editar, guardar, conflictos, publicar) y actualización de la Guía de
  operación (tokens, editor self-hosted, `liaex`).
- [ ] **LIA-053** (M) Pruebas de carga: 100 profesores simultáneos
  (spec §15); revisión de límites (1 MB, rate limiting, 50 ramas/usuario).
- [ ] **LIA-054** (S) Propuesta upstream del parche `liascript-lms`
  (roadmap C10): issue de diseño previo antes que PR directo.
- [ ] **LIA-055** (M) Decisión nº 4 resuelta y ejecutada: migración de
  books legados o convivencia documentada de ambos flujos.

**DoD Fase 3:** sistema en producción con monitorización activa, sesiones
limpiadas por cron, guía de profesor publicada, pruebas de carga
superadas y posición sobre upstream y legado tomada y documentada.
