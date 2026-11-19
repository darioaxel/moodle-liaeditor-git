# Tarea: Desarrollar `crepe-editor` — editor WYSIWYG de cursos LiaScript para Moodle

## 1. Contexto

Existe un sistema "Course as Code" (repo `moodle-liaeditor`) con:

- **Plugin Moodle** (`local_liamateriales`, PHP): catálogo de materiales,
  proxy de contenido (`content.php`), guardado (`save.php`), renovación
  de token (`token.php`), publicación (`publish.php`). Gestiona sesiones
  de edición y trazabilidad curso↔versión publicada.
- **Engine** (FastAPI): cliente Git (única fuente de verdad), ramas de
  edición por usuario, manejo de conflictos por SHA, y generación de
  artefactos con `liaex` (LiaScript-Exporter en Docker): `h5p`, `epub`,
  `pdf`.
- Los cursos son **Markdown LiaScript** en un repo Git.

Hoy la edición se hace con un fork de LiaEdit (LiveEditor). **Este
proyecto (`crepe-editor`) es su sustituto**: una app web estática propia
basada en **Crepe** (UI completa sobre Milkdown: ProseMirror + remark),
desplegada en subdominio propio tras HTTPS, que consume el backend
existente. El profesorado no debe necesitar escribir sintaxis Markdown
para el 90 % del contenido.

**Este repo contiene SOLO la app del editor.** El backend (plugin y
engine) ya existe y no se modifica desde aquí; el contrato HTTP de la
§4 es la frontera. Cualquier cambio de contrato se acuerda y se
implementa en el repo del backend.

## 2. Decisiones de alcance (fijas)

1. **Solo capa de edición**: la persistencia sigue siendo Markdown
   LiaScript en Git; la exportación (`h5p`/`epub`/`pdf`) la hace el
   backend con liaex. Nada de SCORM.
2. **Sin preview LiaScript**: la vista WYSIWYG del editor es la única
   preview. Los constructos propios de LiaScript (quizzes, ejecutables)
   se muestran como tarjetas/placeholders; su comportamiento real solo
   se comprueba en el artefacto publicado. No integrar el runtime
   LiaScript ni un iframe de preview.
3. **Sin colaboración multi-autor** (el aislamiento lo dan las ramas
   por usuario en el backend).
4. **Guardado explícito con validación síncrona pre-commit**: el botón
   Guardar envía el documento; el backend valida con LiaScript antes
   de commitear y puede responder "inválido" (ver §4). No hay
   autoguardado a Git; el autoguardado es solo snapshot local.
5. **i18n**: interfaz en español.

## 3. Stack

- **Vue 3 + Vite + TypeScript**.
- **Crepe** (Milkdown: ProseMirror + remark). Versiones fijadas con
  lockfile; los upgrades solo se aplican con la suite de round-trip en
  verde (ver §6).
- **Monaco** para el modo fuente (lazy-loaded, no en el bundle inicial).
- Features de Crepe activadas selectivamente: toolbar flotante, slash
  commands, tablas, imágenes, código con resaltado, LaTeX (KaTeX).
  **HTML embebido libre desactivado** (seguridad y fidelidad).
- Sin más dependencias pesadas sin justificar. CI mide y reporta el
  tamaño del bundle.

## 4. Contrato con el backend (ya implementado — no cambiar)

### 4.1 Apertura

El plugin redirige al editor así:

```
https://editor.dominio.com/?url=<https://moodle/.../content.php?file=…&branch=…&token=…>
```

El editor hace `fetch` de esa URL (CORS ya habilitado en el backend
para el origen del editor) y recibe el Markdown del curso con un bloque
de configuración inyectado en la cabecera, dentro del comentario
LiaScript inicial:

```yaml
liascript-lms:
  save_endpoint: https://moodle/.../save.php
  publish_endpoint: https://moodle/.../publish.php
  token_refresh_endpoint: https://moodle/.../token.php
  file: unidad-3.md
  branch: edicion/profesor-x/unidad-3
  sha: b2e4f6a...
  token: <un solo uso, corta duración>
  readonly: false
  return_url: https://moodle/.../catalog.php?id=...
```

El editor debe **extraer ese bloque y eliminarlo del documento** antes
de cargarlo en el editor (nunca se edita ni se devuelve; el backend lo
reinyecta en cada apertura).

### 4.2 Guardado

```
POST save_endpoint
{ "file", "branch", "original_sha", "content", "token" }
```

Respuestas:

- `200 { "new_sha", "token"? }` → guardado OK; actualizar SHA (y token
  si viene renovado) y mostrar confirmación.
- `422 { "status": "invalid", "errors": [{ "line", "message" }] }` →
  la **validación LiaScript pre-commit** falló: NO se ha tocado Git.
  Mostrar los errores con su línea, enlazando a esa posición en el
  editor (o en el modo fuente). El usuario corrige y reintenta.
- `409 { "resolution_branch", "diff_url", "current_sha" }` → conflicto
  (otro commit entró entre medias): mostrar modal con las opciones
  **Ver diferencias** (abre `diff_url`), **Recargar versión actual**
  (descarta cambios locales, re-fetch de la URL de apertura) y
  **Forzar mi versión** (reintento con `original_sha = current_sha`).
  El texto debe indicar que el trabajo quedó a salvo en
  `resolution_branch`.
- `401/403` → token inválido/caducado: mensaje claro + botón "Volver a
  abrir desde Moodle" (`return_url`). Nunca un error crudo.

Renovación de token en sesiones largas: si el backend declara
`token_refresh_endpoint`, hacer `POST {file, branch, token}` →
`{token}` periódicamente mientras haya actividad; además cada 200 de
guardado puede traer token renovado.

### 4.3 Publicación

```
POST publish_endpoint
{ "file", "sha", "format": "h5p" | "epub" | "pdf", "token" }
→ 200 { "cmid", "url" } | error
```

Botón Publicar con desplegable de los tres formatos; solo visible si el
documento declaró `publish_endpoint`. Tras el 200, mostrar enlace al
módulo creado en el curso (`url`).

### 4.4 Assets (fase M3)

- Subida: Crepe `onUpload` → `POST upload.php` (multipart, con token)
  → `200 { "draft_url", "draft_id" }`. El editor inserta la imagen con
  la `draft_url` temporal.
- Al guardar: el backend mueve las imágenes referenciadas a
  `assets/<hash>.<ext>` en el repo, reescribe las URLs en el Markdown
  y devuelve en el 200 un `"url_map": { "<draft_url>":
  "assets/<hash>.<ext>" }`. El editor aplica ese reemplazo en su
  documento para seguir editando con las rutas definitivas.
- Las imágenes con ruta `assets/...` se muestran en el editor
  resolviéndolas contra `asset.php` (endpoint del plugin con token;
  misma mecánica que `content.php`).

## 5. Requisitos funcionales

1. **Edición visual de prosa**: texto, encabezados, listas, tablas,
   imágenes (flujo §4.4), enlaces, código resaltado y LaTeX. Recorte de
   features a lo compatible con LiaScript; sin HTML libre.
2. **Nodos atómicos LiaScript** (nodeView propio + parse remark +
   stringifier, serialización exacta):
   - *Quizzes* (tarjeta + formulario; no se ejecutan): `[[?]]` opción
     única, `[(...)]` opción múltiple, `[[...]]` respuesta de texto,
     matrices y variantes con parámetros. Formulario con campos
     obligatorios: enunciado, opciones, respuesta correcta, pista.
   - *Cajas* `@nota`, `@aviso`, `@error`: renderizadas como cajas de
     color editables en el propio editor.
   - *Mermaid*: bloque con editor de código + render del diagrama.
   - *Ejecutables* (SQLite/Pyodide, código LiaScript con flags,
     Vega-Lite, llamadas a LLM): tarjeta placeholder de solo lectura
     con icono del tipo y parámetros clave.
   - *Fallback*: toda macro o constructo no reconocido se muestra como
     bloque atómico de solo lectura con su fuente Markdown intacta.
     **Nunca se reescribe ni se descarta**: el editor debe tolerar
     templates LiaScript futuros sin actualización.
3. **Cabecera del curso fuera del editor**: el comentario inicial
   `<!-- import: …, título, autor, idioma, voz TTS -->` se intercepta
   en el parse, se gestiona con un **panel de configuración**
   (formulario) y se regenera al guardar. Los imports de templates
   fijan versión (nunca `master`).
4. **Modo fuente**: toggle a vista Monaco (solo lectura por defecto;
   editable si el documento llega con un flag que lo permita). Al
   volver al modo visual, si la fuente no parsea, avisar y no perder
   cambios.
5. **Pegado**: HTML pegado (web/Word) → conversión a Markdown razonable.
6. **Guardado**: explícito (botón + Ctrl+S), con estados
   `idle → validating → saving → saved | invalid | conflict | error`;
   manejo de 422/409/401 según §4.2.
7. **Snapshot local**: autoguardado periódico del documento en
   IndexedDB; al reabrir la misma URL con un borrador más reciente que
   el contenido del servidor, ofrecer "Recuperar borrador" /
   "Descartar". Nunca se sube a Git automáticamente.
8. **Publicación**: según §4.3.
9. **i18n**: español (sin textos hardcodeados; estructura preparada
   para más idiomas).

## 6. Requisitos no funcionales

- **Fidelidad de round-trip (requisito crítico)**: para todo `.md`
  LiaScript válido, `parse → editar → serializar` debe producir
  Markdown que `liaex` acepta y que renderiza igual. En documentos no
  editados, `serialize(parse(x))` debe ser idempotente (sin diffs
  cosméticos de listas, encabezados o tablas que ensucien Git).
  **Suite de tests con fixtures reales** (`.md` de los cursos
  existentes, incluidos casos extremos: quizzes con parámetros,
  efectos `{{n}}`, comentarios de narración, cajas, imports) y diff
  automático en CI. Umbral: ≥ 95 % de fixtures sin diff semántico y
  100 % de constructos cubiertos por nodo propio o fallback (nada se
  pierde silenciosamente).
- **Rendimiento**: documentos largos (temas de 50+ páginas) editables
  sin degradación apreciable; lazy-loading de Mermaid/KaTeX/Monaco;
  medir (no estimar) bundle inicial y tiempo de carga con un tema real.
- **Seguridad**: la app se embebe en iframe sandboxed desde Moodle; sin
  HTML embebido libre; CSP estricta; ningún secret en el cliente (la
  autenticación es el token de un solo uso de la URL).
- **Accesibilidad**: navegación por teclado y ARIA en los componentes
  propios (tarjetas, formularios, modales).
- **Mantenibilidad**: los nodos LiaScript propios viven en módulos
  aislados (`src/liascript/`); versiones de Milkdown/Crepe fijadas; la
  suite de round-trip es la red de seguridad para upgrades.
- **Deploy**: `npm run build` → `dist/` estático; documentar en el
  README el despliegue tras Nginx con HTTPS.

## 7. Hitos

| Hito | Contenido | Criterio de salida |
|------|-----------|--------------------|
| M0 — PoC | Scaffold + Crepe mínimo + nodo quiz `[[?]]` + fallback + harness de round-trip con fixtures | Umbrales de §6 cumplidos; `liaex` acepta el serializado de todos los fixtures |
| M1 — Core | Apertura (§4.1), prosa, panel de cabecera, guardado completo (422/409/401), modo fuente, snapshot local | Ciclo editar → guardar → conflicto → resolver contra el backend real |
| M2 — Nodos | Todos los quizzes, cajas, Mermaid, ejecutables (placeholder), clasificación documentada | Unidad creada sin escribir sintaxis; artefacto publicado ejecuta los quizzes |
| M3 — Assets | Flujo §4.4 completo | Imagen pegada → guardado → visible en editor y en artefacto; un commit por guardado |
| M4 — Producto | Publicación (§4.3), i18n ES, accesibilidad, rendimiento, README de deploy | Publicación en un clic a h5p/epub/pdf desde un documento creado íntegramente en el editor |

M0 es un go/no-go: si los umbrales de round-trip no se cumplen, no
continuar (el sistema mantiene el editor actual).

## 8. Entregables

1. Código del editor en este repo (app `dist/` buildeable con
   `npm ci && npm run build`).
2. Suite de round-trip con fixtures y CI verde.
3. README: build, deploy (Nginx/HTTPS), configuración y contrato con el
   backend.
4. Informe de bundle y de rendimiento con documento largo.
5. Clasificación de macros LiaScript (presentacional / paramétrica /
   ejecutable / fallback) de los templates en uso.

## 9. Fuera de alcance

- Cualquier cambio en el backend (plugin Moodle, engine, liaex): vive
  en otro repo.
- Preview LiaScript embebida, colaboración multi-autor, SCORM.
- Feature de IA de Crepe (posible fase posterior, siempre contra proxy
  same-origin del servidor, nunca fetch directo del navegador).
