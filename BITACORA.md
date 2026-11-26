# Bitácora de desarrollo — LiaEditor–Moodle

> Estado del desarrollo en todo momento. Se actualiza **al final de cada sesión de
> trabajo** (humana o de agente). Regla: si una tarea no está reflejada aquí, no está hecha.
>
> Referencias: [Arquitectura v1 (D1–D10)](docs/desarrollo-liaeditor-moodle-v1.md) ·
> [Tasklist v1 (LIA-nnn)](docs/Tasklist%20—%20Desarrollo%20LiaEditor–Moodle%20v1.md) ·
> [Roadmap del fork](docs/roadmap-liveeditor-fork.md) ·
> [Servidor de pruebas](docs/Sistema-prueba.md)

## Estado resumido

- **Flujo vigente**: materiales LiaScript `.md` en Git → edición en LiaEdit
  self-hosted (fork) → guardado vía `save.php` → engine (ramas
  `edicion/*`, conflictos 409) → publicación H5P/ePub/PDF con liaex.
- **Plugin** «Materiales Editables» 2.1.0 (**`local_liamateriales`**,
  versión 2026111900; antes `local_courseascode`). **Engine**
  `materiales_liascript-git` (paquete `materiales_liascript`, antes
  `courseascode`). Repositorio: **`moodle-liascript-git`**. Engine 2.0.0: solo
  `/edit/*`, `/publish` y `/health`.
- **Flujo legado "Course as Code" (`mod_book`) eliminado del código** el
  2026-11-19 (sesión 24). Su progreso por fases (ENG-001–106) y su
  documentación están en [docs/historico/](docs/historico/).
- **Pendiente prioritario** (análisis de la sesión 24): autenticación
  plugin→engine, alcance/`readonly` en los tokens, restricción de ramas y
  `editmain`, y bugs de Publicar (token renovado sin `courseid`, `$USER`
  en `publish_api.php`, reutilización de cmid entre formatos).

## Registro de sesiones

### 2026-11-26 — Sesión 45: «Volver a Moodle» regresa al catálogo del curso

- El botón «Volver a Moodle» del editor llevaba siempre a `/my/courses.php`
  (`return_url` fijo en `content.php`). Ahora `content.php` toma el curso
  del token y devuelve `materials.php?id=<curso>`; sin curso (tokens
  antiguos), sigue yendo a «Mis cursos».
- `preview.php` emite el token de solo lectura con el curso (antes `0`)
  para que la vista previa también vuelva al catálogo. Publicar sigue
  vetado en solo lectura; los eventos de auditoría de la vista previa
  quedan ahora asociados al curso.
- `version.php` → 2026112600. Pendiente: probarlo en el Moodle de
  desarrollo (no hay PHP en el host para `php -l`).
- **Actividad borrada seguía «publicada»**: la pestaña «Publicados en este
  curso» listaba la tabla `local_liamateriales_deploy` sin comprobar si el
  módulo existía. Nuevo `publisher::live_deploys()`: el último despliegue
  por fichero **y formato** cuyo módulo sigue en el curso, es del tipo
  esperado y no está en borrado (`deletioninprogress`). Lo usan la
  pestaña de publicados, `latest_deploy()` (página Publicar: «SHA
  publicado» y aviso de actualización) y `reusable_cmid()`. El historial
  de la tabla se conserva (D6). Antes la pestaña mostraba una fila por
  fichero; ahora una por fichero y formato. Tests nuevos en
  `publisher_test.php` (sintaxis comprobada con `php -l`; falta lanzarlos
  en un Moodle). `version.php` → 2026112601.


### 2026-11-25 — Sesión 43: Borrado de imágenes, ancho, dos columnas e iconos de tema

- **Borrado** (LIA-126): en la prueba LIA-124 quedaron tres imágenes
  huérfanas en `img/` de ut03. El engine borra ahora, en el mismo commit,
  las que el material deja de usar (si ningún otro `.md` las usa). En el
  editor, borrar una imagen sola en su párrafo **no se guardaba nunca**: el
  bloque de imagen de Crepe no tenía posición y el preservador la
  recuperaba del hueco original. Corregido en el transformer LiaScript.
- **Ancho y columnas** (LIA-127): estudiado LiaScript (sin clases de
  columnas; estilo de `<div>` y comentario `<!-- style -->`), comprobado con
  liaex en Chrome. Engine: ancho → atributo `width` en el lector Pandoc;
  columnas → minipages en el PDF de contenido (fixture `ut07-columnas`).
  Editor: nodos `lia_columns`/`lia_column`, atributo `width` del bloque de
  imagen y barra *Ancho*, plantillas en el menú «/» y el panel.
- **Tema con iconos** (LIA-128): `AppIcon.vue` + grupo de opciones.
- **Pruebas**: engine 176 ✓ (ruff, mypy); editor 166 ✓ y e2e 42/42 (tema,
  columnas, ancho, borrar, insertar). Capturas en `reports/` del editor.
- **Pendiente**: imágenes de vista previa del panel para las plantillas
  nuevas (`npm run lia:previews`); LIA-124 completa en Moodle.

### 2026-11-25 — Sesión 42: Publicar solo desde el plugin; pie de versión; PDF sin código

- **Repos revisados**: `contenidos-test` con `main` por carpetas
  (`6d833bd`); fusión de `feature/generacion-web` en `feature/imagenes`.
- **PDF de contenido**: un material sin bloques de código fallaba
  («Undefined control sequence», `\RecustomVerbatimEnvironment`): se carga
  `fvextra` y `Shaded` solo se renueva si existe. Pandoc local 3.9 → 3.14
  (la imagen `pandoc/extra:latest` no está fijada: pendiente).
- **Plugin 2.7.1 (2026112502)**: pie con versión y fecha del último cambio
  en Materiales Editables y Publicar (la fecha sale de `$plugin->version`;
  avisa si falta el upgrade).
- **Plugin 2.8.0 (2026112503)**: publicar solo en `publish.php` (sesión).
  Retirados `publish_api.php` y el límite `ratelimit_publish` (el upgrade
  lo borra). `content.php` envía `publish_url` (sin token) si el usuario
  tiene `:deploy`. **Editor**: fuera el botón y el selector de formato;
  enlace «Publicar…» en otra pestaña, que avisa si hay cambios sin
  guardar; `publish_url` solo del mismo origen que Moodle.
- **Pruebas**: engine 163 ✓; editor 153 ✓ y e2e 31/31; plugin: lint (PHP
  8.3) y arnés del pie. Documento de seguridad (§4.1, §6.5, §7.3, T4, R13)
  y flujos de datos actualizados. LIA-125.
- **Pendiente**: LIA-124 (prueba en el Moodle de pruebas).

### 2026-11-25 — Sesión 41: Imágenes, fase 3, y contenidos-test por carpetas

- **contenidos-test** (rama `feature/imagenes`): un material por carpeta
  (`materiales/<material>/<material>.md` + `img/`) e imágenes de prueba en
  ut03, ut04, ut05 (PNG con transparencia) y la demo (JPEG con título, GIF
  animado en línea). Los fixtures `real/` del editor, actualizados.
- **Engine**: `isolate_material` quita los otros `.md` de la carpeta en la
  copia temporal antes de liaex (el H5P los empaquetaba). Amenaza T14 en el
  documento de seguridad.
- **Editor**: pegar y arrastrar con un uploader propio (el de Crepe dejaba
  el marcador colgado y perdía el pegado entero si fallaba una imagen);
  aviso de imágenes sin texto alternativo.
- **Git LFS**: descartado por ahora (D10 del roadmap).
- **Pruebas**: engine 163 ✓ (ruff, mypy); exportación real con liaex y
  Pandoc de ut05 y la demo en H5P, SCORM, web y PDF de contenido (cada
  paquete solo con su `.md` y sus imágenes); editor 151 ✓, round-trip de
  los materiales con imágenes y e2e 29/29 (pegar PNG + SVG).
- **Pendiente**: LIA-124 (prueba en el Moodle de pruebas). Al desplegar
  `contenidos-test` por carpetas cambian las rutas de los materiales: las
  publicaciones y ramas `edicion/*` anteriores apuntan a las rutas viejas.

### 2026-11-25 — Sesión 40: Imágenes en los materiales (fases 0, 1 y 2)

Rama `feature/imagenes` (sale de `feature/seguridad`) en este repo y en el
editor. Roadmap en `docs/imagenes/roadmap.md` (opción B: las imágenes
viajan con el guardado, un commit).

- **Fase 0**: material de prueba `ut06-imagenes` (PNG, JPEG, WebP, externa).
  Matriz por formato: el PDF de contenido fallaba con WebP y GIF (XeLaTeX
  no los admite) → copias PNG con Pillow; el H5P de liaex descarta las
  WebP y mete todos los `.md` de la carpeta del material (pendiente).
- **Fase 1**: `GET /edit/asset` (ruta en la carpeta del material, tipo y
  firma), `asset.php` (token en el cuerpo, límite propio), `ImageStore` en
  el editor (`proxyDomURL` → `blob:`). **Corregida una pérdida de datos**:
  el bloque de imagen de Crepe escribía la proporción en el texto
  alternativo (`![1.00](…)`).
- **Fase 2**: `commit_files` (texto y binario, un commit con CAS),
  `images` en el guardado validadas en plugin y engine; en el editor,
  reducir a 1920 px, recodificar a JPEG/PNG (sin EXIF), pendientes en
  memoria y en el borrador, y solo las referenciadas en el guardado.
- **Pruebas**: engine 160 ✓ (ruff, mypy); editor 148 ✓ y e2e 25/25 (con
  una subida real de 2600 px); plugin: lint y arnés de la validación.
- **Pendiente**: fase 3 y prueba en el Moodle de pruebas.

### 2026-11-24 — Sesión 39: Fase S — Seguridad

Rama `feature/seguridad` (sale de `feature/generacion-web`) en este repo y
en `fpvirtual-crepe-editor`.

- **LIA-060** Firma HMAC-SHA256 plugin → engine: middleware ASGI
  (`materiales_liascript.auth`; ±60 s, sin réplicas, `/health` libre),
  `serve.py` exige `MATERIALES_LIASCRIPT_SHARED_SECRET`; en el plugin,
  `engine_client` (único cliente HTTP) y el secreto en `config.php` o en
  el ajuste nuevo. Vectores de prueba idénticos en Python y PHP.
- **LIA-061** Documentado (engine sin puerto publicado); falta aplicarlo
  en el servidor.
- **LIA-062/067** Código de apertura de 60 s y un solo uso en el fragmento
  (`#open=…&src=…`); `content.php` lo canjea por POST y recarga con
  `POST {token}`; `preview.php` emite el código al pulsar (el catálogo ya
  no crea tokens al cargarse). Formato antiguo tras `legacy_open_url`.
- **LIA-063** Cabeceras (`no-referrer`, `nosniff`, `no-store`, CORS con
  `Vary`) unificadas en `lib.php`; `meta referrer` y CSP en el editor.
- **LIA-064/065/066** `rate_limiter` (MUC): límites por usuario
  configurables (429 + `Retry-After`), bloqueo por IP tras 20 tokens
  inválidos, una publicación a la vez (409). El editor trata el 429 sin
  perder el trabajo.
- **LIA-068** Tarea diaria de limpieza; **LIA-069** eventos
  `material_opened/saved/conflict/published`; **LIA-070** errores
  genéricos con identificador de incidencia.
- **LIA-071** Documentado en el README del plugin (Nginx `limit_req`).
- **Pruebas**: engine 113 ✓ (ruff, mypy); editor 127 ✓, typecheck, build,
  e2e 18/18; plugin: lint y arnés mínimo (rate_limiter, firma); los tests
  PHPUnit nuevos (`engine_client`, `token_manager`, `rate_limiter`,
  `cleanup_task`, `events`) se ejecutan en el Moodle de pruebas.
- **Pendiente**: desplegar, quitar el puerto del engine, límite del
  proxy, e2e real.

### 2026-11-24 — Sesión 38: Web y editor con la estética de vuepress-theme-hope

- **Web** (`liamat.css`, `web.html`, `liamat.js`, `web.lua`): barra superior
  y lateral, migas, información de la página, índice «En esta página»,
  contenedores de aviso con título e icono, código con lenguaje,
  navegación en tarjetas, portada y modo claro/oscuro. Colores de avisos y
  resaltado tomados de la paleta del editor (AA).
- **Editor**: cajas `@nota/@aviso/…` como contenedores de aviso, quizzes
  como en la web y token `code-block-bg`; mismo aspecto que la web.

### 2026-11-23 — Sesión 38 bis: Pruebas de la Fase 4 en el Moodle de pruebas y correcciones

- **Despliegue** de `feature/generacion-web` (`42c4e2a`) en el servidor de
  pruebas: plugin 2.5.0 en Moodle 8088, engine 8091 reconstruido, imagen
  `pandoc/extra:latest` (Pandoc 3.14) descargada en el host. `web` y
  `pdfcontenido` funcionan con los 5 materiales; «Descargar sin publicar»
  en web probado desde la página Publicar.
- **Engine — web con imágenes sin URL**: el editor Crepe guarda un bloque de
  imagen vacío como `![1.00]()` y `chunkedhtml` fallaba («File '.' not found
  in resource path»). `web.lua` omite esas imágenes con aviso del lector y
  quita la figura vacía. `--mathml` → `--math-method=mathml`.
- **Engine — PDF de contenido**: bloques de código en caja (fondo y borde,
  aspecto Eisvogel, `tcolorbox` en `content-boxes.tex`) conservando el
  resaltado de Pandoc, sin `listings` (descoloca los multibyte). Fuente de
  código Source Code Pro (`content-boxes.yaml`): Latin Modern Mono no tenía
  `─ │ └ ▶` y XeLaTeX los omitía sin error (172 caracteres en la demo).
- **Plugin — Publicar**: botón «Regresar a Edición Materiales v2» bajo el
  título (antes solo había «Cancelar» al final del formulario).
- **Roadmap** de generación web 0.3: §3.4 (código), riesgos nuevos y §10
  (estado de las pruebas).
- **Pendiente**: publicar (crear la actividad) `web` y `pdfcontenido` desde
  Publicar y desde el editor; corregir en el editor el bloque de imagen
  vacío; `①` `②` en cursiva sin glifo; separador inicial en los avisos sin
  línea.

### 2026-11-23 — Sesión 37: Generación web y PDF de contenido — Fase 4 (integración)

- **Engine**: formatos `web` (zip) y `pdfcontenido` (PDF) en `POST /publish`
  con `PandocExporter`. Avisos del lector en la cabecera `X-Liamat-Avisos`
  (lista JSON). Imagen por defecto pública `pandoc/extra:latest`
  (`MATERIALES_LIASCRIPT_PANDOC_IMAGE`) y plantilla FP Virtual incluida en
  `publish/pandoc/fpvirtual-theme/` (copia de `fpvirtual-markdown-a-pdf`
  2f39291; otra con `MATERIALES_LIASCRIPT_PDF_THEME_DIR`). `/health` informa
  de `pandoc`. Sin Docker, 503 en esos formatos.
- **Plugin 2.5.0** (2026112305): `publisher` admite `web` (mod_resource con
  todos los ficheros del zip, extraídos con `zip_packer`, e `index.html` como
  principal; visualización de los ajustes nuevos, por defecto ventana
  emergente 1200×600 como eXeLearning) y `pdfcontenido` (mod_resource).
  Avisos del lector en la actividad publicada, en la descarga (página
  Publicar) y en la respuesta de `publish_api.php`.
- **Editor** (rama `feature/generacion-web`): formatos nuevos en Publicar y
  avisos en el banner.
- **Tests**: engine 102 ✓ (ruff, mypy); editor 114 ✓ y build; plugin: lint y
  funciones puras (ajustes web, avisos); `stage_web` tiene test PHPUnit que
  se ejecuta en el Moodle de pruebas.
- **Pendiente**: probar en el Moodle de pruebas (criterio de salida de la
  Fase 4); decisiones de aspecto y visualización.

### 2026-11-23 — Sesión 36: Generación web y PDF de contenido — Fase 0 (prototipo)

- **Rama `feature/generacion-web`** (se unirá a `main` más adelante).
- **Extensión Pandoc** en `engine/.../publish/pandoc/`: lector LiaScript en
  Lua (cabecera y macros, quizzes de todos los tipos con pistas y solución,
  encuestas, cajas, fragmentos, narración, multimedia, código ejecutable;
  avisos de lo no convertido), filtros `web.lua` y `contenido.lua`,
  plantilla `web.html` y `liamat.js`/`liamat.css` (tema verde).
  `PandocExporter` (`publish/pandoc_export.py`) la ejecuta en Docker con la
  imagen `fpvirtual-pandoc`, como liaex (copia temporal, docker-in-docker).
- **Resultados**: conformidad con `liaex -f fullJson` 4/4 materiales;
  web de `ut05` 17 KB en 0,6 s (liaex `web`: 24 MB); PDF de contenido con
  la plantilla FP Virtual sin quizzes, encuestas ni sus enunciados;
  comportamiento en Chrome 12/12 sin errores de JavaScript.
- **Hallazgos y arreglos**: Pandoc escribe `type` sin `data-` (atributo HTML
  válido), así que el lenguaje intermedio usa `tipo`; identificadores ASCII
  para los nombres de página; el CSS del resaltado se añade a la plantilla.
- **Tests**: `tests/test_pandoc_export.py` (12, de integración: se saltan
  sin Docker o sin imágenes; el PDF requiere
  `MATERIALES_LIASCRIPT_PDF_THEME_DIR`) y `tests/web/liamat_e2e.mjs`.
  Engine 99 tests ✓, ruff ✓, mypy ✓.
- **Pendiente**: web dentro de `mod_resource` en el Moodle de pruebas;
  decisiones de aspecto y visualización; Fases 1–4 del roadmap.

### 2026-11-23 — Sesión 35: Tema del editor en los contenidos exportados

- **Revisión**: los contenidos (SCORM, H5P, ePub, PDF) usaban el tema por
  defecto de LiaScript (turquesa, títulos con serifa Source Serif, pájaro
  de LiaScript) y no el del editor (`verde`, fuentes del sistema): nada del
  tema del editor llegaba a la exportación.
- **Prueba** con el tema `verde` (capturas de SCORM, PDF y ePub): `link:`
  en la cabecera funciona en SCORM (la empaqueta), H5P (la incrusta como
  `data:text/css`) y PDF; **el ePub lo ignora**; `logo:` no cambia el icono
  de la barra superior (es la portada).
- **Editor**: `src/theme/liascript.ts` genera la hoja de LiaScript desde
  los tokens del tema (acento `primary-fill` #2a7d57, AA con texto blanco,
  y no el #3eaf7c decorativo; fuentes con valores literales, válidas en
  ePub; rechaza temas oscuros). `npm run theme:liascript`. 113 tests ✓.
- **Engine**: `publish/themes/verde.css` (generada, incluida en el
  paquete) y `publish/theme.py`. Al exportar añade `link:` y la hoja en la
  copia temporal y en ePub la añade a su `style.css` (conserva `mimetype`
  primero y sin comprimir). El material se restaura byte a byte incluso si
  liaex falla. `MATERIALES_LIASCRIPT_THEME` (defecto `verde`; `none` para
  el aspecto original). 87 tests ✓. Verificado con liaex real en los
  cuatro formatos (`ut05`).
- **Pendiente**: logo del centro en lugar del pájaro de LiaScript
  (LIA-089); comprobar el H5P dentro de Moodle y el ePub en lectores reales.

### 2026-11-23 — Sesión 34: SCORM 1.2 como contenido y fix de H5P en la página Publicar

- **Fallo reportado**: publicar en H5P desde la página Publicar daba
  «Tipo de artefacto no válido: hp». Causa: `artifact_type` se leía con
  `PARAM_ALPHA`, que solo conserva letras (`h5p` → `hp`). Ahora
  `PARAM_ALPHANUM`. La prueba del navegador no lo detectó porque su
  servidor simulado no limpia parámetros como Moodle.
- **SCORM se veía como actividad con intentos y no a pantalla completa**:
  es el comportamiento por defecto de `mod_scorm` (página de entrada con
  intentos, índice y navegación de SCORM, marco con el alto de la
  configuración). El plugin fija ahora, sobre los valores del sitio:
  `skipview=2` (entra directo), `displayattemptstatus=0`, `hidetoc=3` (sin
  índice), `nav=0`, `displaycoursestructure=0`, `hidebrowse=1`,
  `displayactivityname=0`, ventana actual al 100 % (mod_scorm ajusta el
  alto al de la ventana), intentos ilimitados. Constantes comprobadas en
  el código de Moodle (`mod/scorm/lib.php` y `locallib.php`). Los intentos
  siguen existiendo internamente (notas y finalización), pero no se ven.
- **SCORM 1.2 por defecto** (decisión del usuario, compatibilidad): engine
  `scorm_version="1.2"` (configurable a 2004). Verificado con liaex real:
  `imsmanifest.xml` con `schemaversion` 1.2. Engine 79 tests ✓; plugin 8
  tests (37 aserciones) con el arnés mínimo.
- **Plugin 2.4.2 (2026112304)**. Los SCORM ya publicados toman los ajustes
  nuevos al volver a publicarlos (`update_moduleinfo`).
- **No verificado** en Moodle real.

### 2026-11-23 — Sesión 33: Progreso visible al publicar desde el plugin

- **Problema reportado**: al publicar desde la página Publicar el navegador
  parecía bloqueado (POST síncrono; PDF/ePub ~18–20 s).
- **Plugin 2.4.1 (2026112303)**: `js/publish_progress.js` (JS plano, sin
  compilación AMD) envía el formulario en segundo plano (`fetch`, `ajax=1`)
  y muestra un diálogo con spinner, tiempo transcurrido, duración habitual
  del formato y barra **aproximada** (liaex no informa de su avance), con
  aviso si supera 1,5 × lo habitual. Publicar navega a la actividad;
  Descargar entrega el fichero sin recargar; los errores salen en la
  página sin perder lo elegido; controles bloqueados y aviso al salir
  mientras trabaja. `publish.php` responde JSON cuando `ajax=1`. Sin JS,
  todo funciona como antes.
- **Verificado** en Chrome headless contra un servidor simulado
  (`plugin/tests/js/publish_progress_e2e.mjs`, 14/14). No verificado en
  Moodle real (estilos Bootstrap de Moodle y `send_file` con `fetch`).

### 2026-11-23 — Sesión 32: SCORM 2004 y «Descargar sin publicar»

- **Decisiones del usuario**: SCORM **2004** (no 1.2) y versión = **la
  última de la rama de edición del usuario** (HEAD de `edicion/*`, no el
  último SHA anotado en `editsess`). R2 del roadmap revisada.
- **Hallazgo**: los presets de liaex probados para Moodle 3/4/5 usan SCORM
  1.2 con `--scorm-embed`; Moodle soporta SCORM 2004 solo en parte. Por eso
  la versión queda configurable (`MATERIALES_LIASCRIPT_SCORM_VERSION`).
- **Engine**: formato `scorm` en `/publish` → `liaex --format scorm2004
  --scorm-embed`, salida `.zip` (tabla de extensiones por formato).
  Verificado con liaex real: SCORM 2004 4th Edition, 7,6 MB, con
  `imsmanifest.xml`. 79 tests ✓.
- **Plugin 2.4.0 (2026112302)**: `mod_scorm` con `scormtype=local` y los
  ajustes por defecto del sitio (como su formulario); `publish_api.php`
  acepta `scorm`; página Publicar con SCORM, versión = HEAD de la rama del
  usuario (con fecha del commit) o `main`, y botón **«Descargar sin
  publicar»** (`send_file`, sin módulo ni registro). Tests: 7 (28
  aserciones) con el arnés mínimo.
- **Editor**: opción SCORM 2004 en el desplegable de Publicar.
- **No verificado** en Moodle real: la creación de `mod_scorm` (nunca se
  ha probado en este proyecto) y que el seguimiento de SCORM 2004 funcione
  en Moodle 6.

### 2026-11-23 — Sesión 31: Publicar en PDF/H5P roto + página Publicar con generación automática

- **Fallo reportado**: publicar en PDF desde el editor no daba resultado.
  Reproducido contra el engine del servidor: ePub 200 (1,4 MB, 17,6 s),
  **PDF 500** sin mensaje.
- **Causa**: liaex añade la extensión al nombre de salida en PDF y H5P
  (`--output artifact.pdf` → `artifact.pdf.pdf`; en ePub no), así que el
  engine no encontraba el fichero y lanzaba una excepción no controlada.
  **PDF y H5P nunca habían funcionado**; solo se había probado ePub.
- **Engine**: nombre base sin extensión (`--output /work/artifact`, liaex
  genera `artifact.<formato>` en los tres), búsqueda de respaldo
  `artifact*.<formato>` y 502 con mensaje si no hay fichero; limpieza de
  restos. Verificado con liaex real: PDF 161 KB (17,7 s), H5P 7,5 MB
  (3,3 s), ePub 1,4 MB (18 s). 76 tests ✓.
- **Plugin 2.3.0 (2026112301)**: la página Publicar pasa a **generar y
  publicar** desde el Markdown (versión guardada o `main`, el SHA lo
  resuelve el servidor); subir un fichero propio queda como alternativa
  plegada. Generación común en `publisher::publish_generated()`, que
  también usa `publish_api.php`, con tiempo de espera de 330 s y límite de
  ejecución PHP elevado (PDF tarda ~20 s). La subida manual ya no reutiliza
  el módulo de otro formato.
- **No verificado** en el Moodle de pruebas (requiere desplegar plugin y
  engine).

### 2026-11-23 — Sesión 30: `committed_at` (fecha de la versión) para el editor

- **Contrato** (opcional y compatible hacia atrás): `committed_at`, fecha
  del commit en ISO 8601 con zona, en el bloque `liascript-lms` (tras `sha`)
  y en la respuesta 200 de `save.php`. El editor la muestra en la barra en
  lugar del SHA.
- **Engine**: `GitProvider.commit_date()` (`git show -s --format=%cI`,
  `None` si falla o el formato no es válido); `EditContent` y `SaveOk` la
  incluyen. 6 tests nuevos → 74 ✓, ruff ✓, mypy ✓.
- **Plugin 2.2.1 (2026112300)**: `local_liamateriales_commit_date()` (solo
  ISO 8601 con zona en una línea; evita saltos de línea en el bloque YAML),
  `local_liamateriales_lms_config()` y `local_liamateriales_save_ok_body()`.
  Sin cambios de BD. Primer test PHPUnit del plugin (`tests/lib_test.php`,
  5 tests): ejecutado con un arnés mínimo en php:8.3-cli (23 aserciones ✓),
  pendiente de pasar en `moodle-plugin-ci` (LIA-099).
- **No verificado** contra el Moodle de pruebas (requiere despliegue): la
  comprobación manual de la barra del editor.

### 2026-11-20 — Sesión 29: Flujos de datos y tasklist v2 (seguridad)

- **Documento nuevo**: `docs/Flujos de datos — plugin, engine y editor.md`,
  con 8 diagramas Mermaid de abrir, guardar y publicar, más canales,
  almacenamiento de datos y limitaciones. Sin validar el renderizado (se
  bloqueó mermaid-cli): revisado a mano.
- **Superficie expuesta del plugin** revisada: sin web services, cuatro
  scripts sin sesión protegidos por token (`content.php`, `save.php`,
  `token.php`, `publish_api.php`) y una llamada saliente al engine.
- **Tasklist reescrito (v2)**: quitadas las tareas hechas; nueva **Fase S
  de seguridad** (LIA-060…071: HMAC plugin→engine, engine solo interno,
  código de apertura en el fragmento de la URL, cabeceras, límites 429,
  bloqueo de publicación, limpieza, auditoría); Fase E del editor
  (LIA-080…088); Fase B de robustez (LIA-090…100) y Fase P de producción.
  Se detecta que el «rate limiting» de LIA-013 constaba como hecho y nunca
  se implementó; LIA-054 (parche upstream del fork) se retira.

### 2026-11-19 — Sesión 28: M1 del editor Crepe + arreglos de Publicar y alcance del token

- **Editor** (`fpvirtual-crepe-editor`, `main`): guardar contra `save.php`
  (botón y Ctrl+S, 409 con diálogo ver/recargar/forzar, sesión caducada),
  renovación del token, Publicar H5P/ePub/PDF, borrador en IndexedDB y
  aviso al salir. Verificado con e2e en Chrome contra un backend simulado
  (13/13). Desplegado por el usuario en `http://192.168.2.253:8092`; el
  plugin ya apunta ahí (CORS comprobado).
- **Plugin 2.2.0 (2026111901)**:
  - B1: `token_manager::refresh` conserva el curso (y el modo); antes,
    tras el primer guardado, Publicar daba 403.
  - B2: `publish_api.php` actúa como el usuario del token
    (`session\manager::set_user`), necesario para el área de borrador de
    `add_moduleinfo`, y comprueba `:deploy` y `manageactivities` al publicar.
  - B3: solo reutiliza el módulo del mismo formato si sigue existiendo
    (`publisher::reusable_cmid`); devuelve el enlace al módulo.
  - Token con alcance: campo `readonly` (upgrade). La vista previa emite
    tokens de solo lectura, y `save.php`/`publish_api.php` los rechazan
    (antes la vista previa permitía guardar en `main`). `content.php` toma
    el modo del token.
  - `edit.php`: el parámetro `branch` solo acepta una rama de una sesión
    propia del fichero, o `main` con `editmain`.
  - `content.php` atiende el preflight `OPTIONS` antes de leer parámetros
    (antes devolvía 404).
- **Engine**: `MATERIALES_LIASCRIPT_LIAEX_NETWORK` (defecto `none`) para dar
  red a liaex cuando los materiales importan plantillas. 68 tests ✓.
- **Verificado**: `php -l` sin errores, `install.xml` válido, `make check`
  del engine. **No verificado** contra el Moodle de pruebas: hay que
  desplegar el plugin (upgrade a 2026111901), el editor nuevo y, para
  publicar materiales con `import:`, el engine con
  `MATERIALES_LIASCRIPT_LIAEX_NETWORK=bridge`.

### 2026-11-19 — Sesión 27: Fase 0 del editor Crepe (PoC) — go condicionado

- **Repo del editor**: `FPVirtual/fpvirtual-crepe-editor`, rama `poc/m0`
  (Vue 3 + Vite + TS, `@milkdown/crepe` 7.23.0 fijado). Dialecto LiaScript
  en `src/liascript/`: cabecera y bloque LMS fuera del editor, transformer
  remark que protege como nodo atómico (fuente literal) quizzes, efectos,
  narración, macros, HTML, código con flags, marcas inline, enlaces por
  referencia y encabezados setext, agrupando bloques adyacentes y `****`;
  serialización que preserva la fuente por nodo de nivel superior.
- **Resultados M0** (informe en `docs/informe-m0.md` del editor y §9 del
  roadmap): 8/8 fixtures idempotentes byte a byte, sin diff semántico, con
  LiaScript literal y edición local; 8/8 equivalentes según
  `liaex -f fullJson`; bundle 1,50 MB (472 kB gzip); tema de 3107 líneas:
  apertura 1,6 s, guardado ≈ 70 ms.
- **Hallazgos**: liaex no valida (R7 hay que redefinirla); liaex necesita
  red con `import:` (el engine usa `--network none`, así que publicar falla
  con templates); errores de quiz en `demo-liascript.md`.
- **Backend (rama `feature/editor-crepe`)**: inyección del bloque
  `liascript-lms` reversible y sin el fallo de `$1`/`\\` en
  `preg_replace`; roadmap actualizado (repo del editor, §9).
- **Pendiente**: materiales reales para el corpus; decidir el nuevo R7 y la
  red de liaex; M1 (LmsBridge, guardado, panel de cabecera).

### 2026-11-19 — Sesión 26: Renombrado del engine a materiales_liascript-git

- **Engine**: distribución `materiales_liascript-git` (pyproject) y paquete
  importable **`materiales_liascript`** (Python no admite guiones en
  nombres importables). Arranque: `python -m materiales_liascript.serve`.
- **Variables de entorno**: `COURSEASCODE_LOG_FORMAT/WORKDIR/HOST_WORKDIR`
  → `MATERIALES_LIASCRIPT_*`.
- **Identidad Git del committer**: `materiales-liascript-git
  <engine@materiales-liascript.local>` (los commits nuevos de profesores
  llevan este committer; el autor sigue siendo el profesor).
- **Dockerfile**: `WORKDIR /srv/materiales-liascript`, usuario `liascript`.
- **Verificado**: `.venv` regenerado en la ruta actual (el anterior
  apuntaba a `moodle-book-git`); `make check` verde (ruff ✓, mypy strict
  ✓, 66 tests ✓); `docker build` OK y `GET /health` →
  `{"ok":true,"git":true,"publish":true}` con un repo montado.
- **Pendiente en el servidor de pruebas**: reconstruir la imagen y
  renombrar en el env las variables `COURSEASCODE_*` (ver
  `docs/Sistema-prueba.md`).

### 2026-11-19 — Sesión 25: Renombrado del plugin y del repositorio

- **Plugin**: componente `local_courseascode` → **`local_liamateriales`**
  (el nombre pedido, `local_materiales_liascript-git`, no es válido:
  Moodle no admite guiones y el prefijo dejaría sin sitio a los nombres de
  tabla, limitados a 28 caracteres). Renombrados namespace, funciones
  `local_liamateriales_*`, capabilities `local/liamateriales:*`, URLs
  `/local/liamateriales/*`, `$CFG->local_liamateriales_service_url`,
  config del plugin, fichero de idioma y tablas
  `local_liamateriales_deploy` (antes `ldeploy`), `_editsess` y `_token`.
  Nombre visible sin cambios: «Materiales Editables».
- **Instalación desde cero** (decisión del usuario, sin migración de
  datos): `upgrade.php` arranca vacío en 2026111900.
- **Repositorio**: nuevo nombre `moodle-liascript-git` en README y docs.
  El engine conserva su paquete `courseascode` y las variables
  `COURSEASCODE_*` (fuera del alcance del cambio).
- **Verificado**: `php -l` sin errores (php:8.3-cli en Docker),
  `install.xml` válido, nombres de tabla ≤ 28 caracteres.
- **Pendiente en el servidor de pruebas**: desinstalar
  `local_courseascode` (Administración → Plugins → Desinstalar, o
  `admin/cli/uninstall_plugins.php --plugins=local_courseascode --run`),
  montar `plugin/` en `public/local/liamateriales`, instalar, mover
  `$CFG->local_courseascode_service_url` a
  `$CFG->local_liamateriales_service_url` en `config.php`, reconfigurar
  los ajustes (URL del editor, CORS, TTL) y reasignar capabilities.
  Renombrar el repo en GitHub y actualizar `origin` en los clones.

### 2026-11-19 — Sesión 24: Eliminación de la deuda del flujo legado mod_book

- **Análisis técnico** del proyecto (seguridad, bugs, robustez, docs); sus
  hallazgos prioritarios quedan en el estado resumido.
- **Engine 2.0.0**: eliminados `catalog/`, `content/` (renderer, temas,
  caché), `deployment/`, `domain/`, `manifests/`, `moodle/`, `releases/`,
  `cli/`, `settings.py`, `web/` (preview) y los routers de deployment,
  hooks y releases. `GitProvider` reducido a lo que usa el flujo nuevo
  (sin `get_ref`, `list_tags`, `diff`, `merge_base`, `current_branch`;
  fuera `git/diff.py` y `git/versioning.py`). Nueva `courseascode/app.py`
  (`/edit/*`, `/publish`, `GET /health`). Dependencias reducidas a
  GitPython, fastapi, pydantic, structlog y uvicorn (fuera bleach, jinja2,
  pygments, markdown-it, mdit-py-plugins, tinycss2, sqlmodel, pyyaml,
  pydantic-settings, typer, httpx→dev). Dockerfile sin BD/volumen y con
  healthcheck en `/health`. Fixture Git de tests simplificado
  (`tests/fixtures/contenidos/materiales/...`, sin manifests ni tags).
- **Plugin 2.1.0** (2026111900): eliminados los 9 web services
  `local_courseascode_*`, `classes/external/`, `book_helper`,
  `db/services.php`, tablas `local_courseascode_deploy`/`_chapter`
  (upgrade las borra), área de ficheros `asset` + `pluginfile` (upgrade
  borra los ficheros), capability `:manage`, cabecera
  `X-CourseAsCode-User`, `service_public_url` y cadenas muertas.
  **Fix**: el badge de estado de `materials.php` comparaba con
  `UP_TO_DATE` en mayúsculas y el engine devuelve `up_to_date` → salía
  siempre gris.
- **Docs**: documentación de Course as Code movida a `docs/historico/`
  (spec v0.2, diagramas, tasklists ENG/PRU, guías de autor/operación,
  prompt inicial, progreso por fases de esta bitácora). README raíz,
  README del engine y del plugin y `docker-compose.yml` actualizados
  (plugin montado, socket Docker, sin variables del flujo antiguo).
- **Verificado**: engine `ruff` ✓, `ruff format` ✓, `mypy --strict` ✓,
  **66 tests** ✓ (antes 351: el resto cubría el flujo eliminado). Plugin:
  `php -l` (php:8.3-cli en Docker) sin errores en todos los ficheros;
  `install.xml` XML válido.
- **No verificado**: el upgrade 2026111900 contra el Moodle de pruebas
  (borrado de tablas y ficheros) — ejecutar
  `php admin/cli/upgrade.php --non-interactive` tras desplegar. Tras el
  upgrade, el servicio externo/token de web services que usaba el engine
  antiguo en Moodle puede borrarse a mano. El `.venv` local del engine
  apunta a la ruta antigua `moodle-book-git`: regenerarlo.

### 2026-11-18 — Sesión 23: Publicación en un clic E2E + fix crítico de URL del editor

- **LIA-040/041/042 implementados**: engine `POST /publish` (módulo
  `publish/`, `ArtifactExecutor` inyectable + `LiaexDockerExecutor`),
  `publish_api.php` en el plugin (contrato `{file, sha, format, token}` →
  artefacto → módulo), token con `courseid` (upgrade 2026111801) y
  `publish_endpoint` inyectado en el bloque `liascript-lms` cuando el token
  conoce el curso. 8 tests nuevos → **351 tests** ✓, ruff ✓, mypy ✓.
- **E2E real de liaex verificado** en el servidor: EPUB de
  `demo-liascript.md` generado (1,4 MB) vía `POST /publish`. Tres bugs de
  despliegue resueltos: Debian 13 separa `docker-cli` de `docker.io`
  (imagen del engine actualizada); permisos del directorio compartido
  `engine-tmp` (chown 1000); y **docker-in-docker**: el demonio del host no
  ve las rutas del contenedor engine → `LiaexDockerExecutor` con
  `COURSEASCODE_WORKDIR`/`HOST_WORKDIR` que traduce el mount `-v` y worktrees
  creados bajo `TMPDIR=/work-tmp` (bind mount host↔engine).
- **Plugin desinstalado y reinstalado de cero** (a petición del usuario):
  `uninstall_plugins.php` borra tablas y config; dos fallos de instalación
  limpia en Moodle 6 corregidos en la tabla legada deploy (`source`
  char(1333)→133 e índice compuesto >333 chars → índice simple, versión
  2026111802). Settings reconfigurados tras el wipe.
- **Fix crítico de UX del editor** (reportado por el usuario): el router
  del fork captura `:fileUrl` de la query **sin decodeURIComponent**; el
  plugin enviaba la URL codificada → fetch relativo → el SPA devolvía su
  propio index.html como documento ("Mis cursos / Sin título" mostrando
  HTML). Ahora la URL se envuelve en `?%2F...` (el fork decodifica una
  vez). Además, la vista previa usa la ruta `open` con badge "Solo lectura"
  en vez de `show` (que renderiza sin mostrar el fuente).
- **Pendiente**: verificación UI completa del flujo Editar → Guardar por el
  usuario; prueba real del botón Publicar desde el editor.

### 2026-11-18 — Sesión 22: Limpieza del curso de pruebas + materiales LiaScript de calidad

- **Book legado eliminado** del curso CAC-TEST (cmid 533, vía
  `course_delete_module` por CLI) y purgadas las tablas legadas
  `local_courseascode_deploy` y `local_courseascode_chapter`. El curso
  queda solo con contenido del flujo nuevo.
- **Ramas de prueba eliminadas** de `contenidos-test` en GitHub
  (`edicion/profesor-test/*`, `resolucion/*`).
- **Materiales v2 sembrados** (commit `128afcb`): `ut03-mvc.md` reescrito
  como unidad completa (objetivos, tabla de capas, diagrama ASCII, código
  comentado, autoevaluación con pistas y respuesta corta) y
  `demo-liascript.md` nuevo — escaparate de capacidades para el
  profesorado (tablas, KaTeX, quizzes única/múltiple/corta, encuestas,
  notas al pie, diagramas). Verificado en `GET /edit/files` del engine con
  los títulos extraídos de la cabecera.

### 2026-11-18 — Sesión 21: Materiales Editables v2.0 — retirada de la UI legado mod_book

- **Renombrado visible del plugin**: `pluginname` → **Materiales
  Editables**, release **2.0.0** (version 2026111800). El componente interno
  sigue siendo `local_courseascode` (carpeta/tablas): renombrarlo forzaría
  reinstalación y pérdida de datos sin ganancia.
- **Retirada la UI del flujo legado "Course as Code"** (mod_book):
  eliminadas `catalog.php`, `bookversion.php`, `admin.php`, `deploy.php`,
  los 3 templates Mustache y el hook de inyección CSS en `mod-book-view`.
  La navegación del curso queda solo con la entrada **Materiales
  Editables** → `materials.php`. Eliminados helpers muertos de `lib.php`
  (`preview_url`, `course_deploys`, `book_state`) y las cadenas de idioma
  del flujo viejo. Los web services `local_courseascode_*` del contrato
  antiguo se conservan temporalmente (compatibilidad engine), sin UI.
- **Formatos de publicación fijados**: solo **H5P, ePub y PDF** (markdown
  LiaScript transformado por el exporter). Eliminado SCORM de
  `publisher::TYPES` y del formulario de Publicar.
- **Contexto de arquitectura**: el editor se alojará en OVH (HTTPS); en el
  Moodle seguro solo va el plugin PHP + una llamada HTTPS saliente al
  engine (email de requisitos preparado para los administradores). Los
  contenedores están casi descartados en ese entorno → la opción A (engine
  en OVH) no los necesita en el servidor Moodle.
- **Docs**: `docs/Roadmap — Migración del engine a PHP.md` (análisis de
  coste de revertir D2: 6–8 semanas solo flujo LiaEditor; recomendación de
  no migrar salvo que el entorno lo fuerce).

### 2026-11-17 — Sesión 20: Despliegue completo en el servidor de pruebas + E2E del flujo de edición 🎉

**El flujo LiaEditor queda montado y verificado extremo a extremo contra el
Moodle de pruebas (8088, Moodle 6.0dev) y el editor self-hosted (8090).**

- **Rescate de hotfixes vivos del servidor** (sin commit desde la sesión 15):
  namespacing `\core_external\*` en las 9 clases external + `book_helper`
  (Moodle 6) y `local_courseascode_pluginfile` (serving de assets). Portados
  a main (`2b564ab`) antes de actualizar el clon del servidor (con backup en
  `/tmp/plugin-live-backup` y stash).
- **Plugin 0.3.0 desplegado** en el bind mount (clon
  `/apps/8088-moodle-v5.3-beta-test/moodle-book-git`, remote actualizado a
  `moodle-liaeditor-git`). `php -l` verde en todos los ficheros. Upgrade OK
  tras corregir un índice compuesto demasiado largo en `l_deploy...token`
  (límite XMLDB 333 chars: `userid,file,branch` → índice simple por
  `userid`, `7639133`). El primer intento de upgrade quedó colgado
  (upgrade.php es interactivo: usar `--non-interactive`).
- **Engine redesplegado** (el usuario lo había borrado): imagen reconstruida
  en el servidor, contenedor `courseascode` en la red
  `..._moodle-internal`, puerto público **8091** (8090 es ahora el editor),
  `MOODLE_CONNECT_URL=http://moodle53beta-moodle:80` (el forwarder socat ya
  no existe), `CONTENT_REPO_URL` añadida al env. El clon
  `contenidos-test` tiene ahora origin con credenciales de escritura (D1:
  push de commits y ramas verificado contra GitHub).
- **Config Moodle**: `service_public_url` → 8091; settings del plugin
  (`liaeditor_url=http://192.168.2.253:8090`, CORS derivado). **Seguridad
  cURL**: el helper de Moodle bloqueaba las llamadas al engine (puerto 8000
  no permitido + 192.168/16 bloqueada por defecto) →
  `curlsecurityallowedport` = `443,80,8000` y `curlsecurityblockedhosts`
  sin `192.168.0.0/16` (instancia de pruebas; en producción afinar).
- **E2E verificado** (curl contra los endpoints reales):
  1. `GET /edit/files` → materiales con título LiaScript ✓
  2. `POST /edit/open` → rama `edicion/profesor-test/materiales-ut03-mvc`
     creada desde main ✓
  3. `POST /edit/save` (engine) → commit + **push a GitHub verificado** ✓
  4. Guardado con SHA viejo → **409** con rama `resolucion/...` y diff_url ✓
  5. `content.php` → 200, CORS al editor, headers X-LiaMD-*, bloque
     `liascript-lms` inyectado en índice 0 con los 8 campos del contrato ✓
  6. `save.php` (con token en BD) → 200 `{new_sha, token renovado}`, sesión
     registrada con SHA nuevo ✓; `token.php` renueva ✓; 409 también a
     través de la fachada ✓.
- **Bugs encontrados y corregidos en caliente** (commiteados):
  `Class "curl" not found` en Moodle 6 → require explícito de
  `lib/filelib.php` en `lib.php`; `last_save` quedaba a 0 en sesiones
  creadas desde `record_save` → fix en `touch_session` (verificado
  `saved_ok=1`).
- **Pendiente para la próxima sesión:** prueba UI real con profesor
  (Materiales → Editar → LiaEdit → Guardar; ojo al Service Worker por
  HTTP), Publicar con un artefacto real (SCORM exportado), y limpiar las
  ramas de prueba `edicion/profesor-test/*` y `resolucion/*` del repo.

### 2026-11-17 — Sesión 19: Fork LiveEditor verificado — LIV-1–5 ya implementados

- **Estado real del fork `FPVirtual/LiveEditor`** (HEAD `c905d08`, antes se
  creía sin cambios): los 10 cambios del roadmap están implementados desde
  el 2026-11-13 — C1+C2 (`OpenFile.vue`, `lms/config.ts`), C3+C4
  (`LmsBridge.ts`, `LmsSaveBar.vue`), C5+C6 (`LmsConflictModal.vue` +
  refresh de token), C7+C8+C9 (Git oculto, `.env`, i18n ES) y C10 (CI con
  build y deploy a GitHub Pages). Tasklist actualizada: LIA-030–034 ✅ y
  **LIA-022 resuelta** (no hace falta userscript: el fork ya tiene Guardar).
- **Contrato editor↔plugin cotejado línea a línea** (`config.ts` +
  `LmsBridge.ts` del fork vs. `content.php`/`save.php`/`token.php`): body de
  save, respuestas 200 (`new_sha`, `token`), 409 (`resolution_branch`,
  `diff_url`, `current_sha`), 401/403 → auth_error, refresh `{token}` y la
  ruta `?/open/file/:fileUrl` — **todo coincide**. Detalle clave verificado:
  el fork solo lee la cabecera si el comentario `<!--` empieza en el índice
  0 del documento; nuestra inyección lo garantiza (tanto al crear como al
  reemplazar el bloque).
- **Editor desplegado por el usuario** en `http://192.168.2.253:8090` (mismo
  servidor que el Moodle de pruebas, que está en `:8088`). Verificado por
  HTTP: responde 200 y sirve el build del fork (los chunks incluyen
  `OpenFile` y `lms/config`). Queda configurar `liaeditor_url` +
  `cors_origin` (se deriva solo del editor) en el plugin y probar E2E.
  ⚠️ Servido por HTTP: si el Service Worker de la PWA no registra en algún
  navegador, poner Nginx con certificado delante.
- **Pendiente para la próxima sesión:** LIV-6 / LIA-041–042 (publish.php +
  `POST /publish` con liaex, fase 2), despliegue del plugin 0.3.0 en el
  Moodle de pruebas y verificación E2E del flujo completo editar→guardar.

### 2026-11-17 — Sesión 18: Fase 1 pista plugin 🔌 (LIA-010–018) + catálogo master en el engine

- **Engine — `GET /edit/files`**: listado de materiales `.md` de una rama
  con el título extraído de la cabecera LiaScript (`title:` del comentario
  `<!-- ... -->`, fallback al nombre de fichero; excluye el README raíz).
  Alimenta el catálogo master del plugin (acordado en la UX del profesor).
  3 tests nuevos → **343 tests** ✓, ruff ✓, mypy strict ✓.
- **BD del plugin (LIA-010)**: tres tablas nuevas en `install.xml` +
  `upgrade.php` (2026111700, release 0.3.0): `ldeploy` (curso↔fichero↔SHA↔
  tipo↔cmid, D6), `editsess` (sesiones de edición con último SHA, D3) y
  `token` (hash sha256, nunca el token en claro).
- **Tokens de un solo uso (LIA-011, D9)**: `local\token_manager` —
  emisión ligada a usuario+fichero+rama, validación (caducidad, revocación,
  match exacto), renovación revocando el anterior (contrato C6) y revocación
  por fichero. TTL configurable.
- **Settings (LIA-012)**: `settings.php` con URL del editor, origen CORS
  (default: origen del editor), TTL del token (900 s) y tamaño máximo (1 MB).
- **Endpoints del flujo (LIA-013/014/015)**: `content.php` (proxy markdown
  con bloque `liascript-lms` inyectado en la cabecera LiaScript, CORS,
  no-cache, headers X-LiaMD-*), `save.php` (fachada D3: valida token →
  `POST /edit/save` del engine → registra sesión con el SHA nuevo → token
  renovado en cada 200; propaga el 409 con rama de resolución y diff_url) y
  `token.php` (renovación sin guardar). Autenticación por token en los tres
  (las cookies Moodle no viajan cross-origin, D9). Helper nuevo
  `service_post_full` en `lib.php` para propagar 4xx con body.
- **Página "Materiales" (LIA-016/018)**: `materials.php` con las tres
  secciones de la UX acordada — catálogo master (preview solo lectura con
  token readonly, Editar, Publicar), mis ediciones (continuar/publicar) y
  publicados en el curso (badge UP_TO_DATE/UPDATE_AVAILABLE vía
  `/edit/state`, enlace al módulo, botón Actualizar). Entrada en la
  navegación del curso. `edit.php` asegura la rama vía `POST /edit/open`,
  emite token, abre sesión y redirige al editor (`/?/open/file/<url>`,
  ruta C1 del fork; preview usa `/?/show/file/` de upstream).
- **Publicar (LIA-017)**: `publish.php` + `local\publisher` — subida del
  artefacto al área de borrador, creación/actualización del módulo
  (`mod_scorm` / `mod_h5pactivity` / `mod_resource` vía
  add/update_moduleinfo) y registro del despliegue curso↔SHA↔cmid.
- **Capacidades**: `local/courseascode:edit` (editingteacher) y
  `editmain` (manager). README del plugin actualizado con la sección v1.
- **No verificable en este entorno** (sin PHP): `php -l`, upgrade de BD,
  smoke de los endpoints y creación real de módulos quedan para el Moodle
  de pruebas. Revisión estática hecha a mano: corregidos
  `random_string_emulate` → `random_string(48)` y dos usos de `$cm->name`
  (course_modules no tiene `name`; se resuelve vía `get_fast_modinfo`).
- **Pendiente para la próxima sesión:** decisión LIA-022 (Guardar en fase
  1) y pista fork 🍴 (LIA-030–034) o despliegue/verificación del plugin en
  el Moodle de pruebas (PRU-001+ para el flujo nuevo).

### 2026-11-17 — Sesión 17: Fase 1 pista engine completa (LIA-001–006)

- **Flujo de edición LiaEditor implementado en el engine** siguiendo la
  tasklist v1 y la propuesta de UX del profesor (catálogo / mis ediciones /
  publicados + notificaciones, acordada en la conversación previa).
- **`GitProvider` extendido con escritura** (`git/provider.py`): `branch_exists`,
  `create_branch`, `commit_file` (plumbing puro: `hash-object` + `mktree`
  recursivo + `commit-tree` + `update-ref` CAS — **nunca toca el working
  tree ni el índice**, a diferencia de un checkout), `push_branch` (no-op
  sin remoto). `BranchMovedError` nuevo para la carrera del CAS. Errores de
  GitPython (`GitCommandError` en `merge_base`/`commits_ahead`/`is_ancestor`)
  envueltos en `GitProviderError` (los SHA de 40 hex no validan existencia).
- **Módulo `edit/`** (LIA-001/003): modelos (`EditContent`, `SaveRequest`
  con límite 1 MB, `SaveOk`/`SaveConflict`, `EditState`), `branches.py`
  (sanitización de nombres máx 100 chars, `resolucion/<user>/<ts>-<file>`,
  validación anti path-traversal solo `.md`) y `EditService`
  (open/content/save/state + `build_compare_url` GitHub/GitLab, credenciales
  https eliminadas de la URL).
- **Endpoints** (`api/edit.py`, montados en `web/app.py`, `serve.py` pasa
  `CONTENT_REPO_URL`): `GET /edit/content`, `POST /edit/open` (crea la rama
  desde `main` si no existe, marca `stale` si el fichero desapareció de
  main), `POST /edit/save` (200 `{new_sha}` o 409 con rama de resolución +
  `diff_url` + `current_sha`), `GET /edit/state` (up_to_date /
  update_available + commits_ahead).
- **Verificado**: 47 tests nuevos (branches, servicio, API con TestClient).
  `ruff` ✓ · `ruff format` ✓ · mypy strict ✓ · **340 tests** ✓ (14 skipped
  integration). Incidencia: el venv de `engine/` quedó roto por el
  renombrado del directorio del proyecto (shebangs a `moodle-book-git`);
  reinstalado con `pip install -e .[dev]`.
- **Pendiente para la próxima sesión:** pista plugin 🔌 (LIA-010–018):
  tablas, tokens de un solo uso, `content.php`/`save.php`/`token.php`,
  botón Editar en el catálogo y página Publicar. Y la decisión LIA-022
  (Guardar en fase 1: userscript vs. adelantar el fork).

### 2026-11-16 — Sesión 16: Tasklist LiaEditor–Moodle v1 + README renombrado

- **README reescrito** con la identidad nueva del proyecto
  (LiaEditor–Moodle, decisiones D1–D10, arquitectura v1) y subido a
  `origin/main` (commit `b5c4119`).
- **Revisión de estado** (docs + roadmap): sistema Course as Code cerrado
  (E2E real 2026-11-09, 8 PRU pendientes); pivote LiaEditor con los 3 docs
  base escritos (spec mod_liamd, arquitectura v1, roadmap fork) y **cero
  código implementado** — el fork `FPVirtual/LiveEditor` ya existe, sin
  cambios propios todavía.
- **Creada `docs/Tasklist — Desarrollo LiaEditor–Moodle v1.md`** (formato
  ENG/PRU): 3 fases con DoD — Fase 1 MVP sin fork (engine `edit/`,
  `content.php`/`save.php`/tokens/página Publicar 🔌, LiaEdit
  self-hosted), Fase 2 un clic (fork 🍴 C1–C10 mapeado a LIV-1–6, `liaex`
  server-side), Fase 3 producción. Cinco decisiones pendientes recogidas
  con la tarea que condiciona cada una.
- **Pendiente para la próxima sesión:** resolver la decisión nº 1
  (Guardar en fase 1: userscript vs. adelantar el fork) y arrancar LIA-001.

### 2026-11-09 — Sesión 15: Ciclo E2E completo contra Moodle real 🎉

**Primera verificación extremo a extremo del sistema contra el Moodle de
pruebas (PRU-006/014/030/031/032).**

- **Engine reconstruido con `MOODLE_CONNECT_URL`**: alcanza al Moodle vía el
  forwarder socat con Host = wwwroot. Suite local verde antes del despliegue
  (297 tests, ruff ✓, mypy strict ✓).
- **Cadena engine→plugin verificada**. Errores encontrados y resueltos:
  1. `svc-courseascode` "no completamente configurado": le faltaban los
     campos de perfil obligatorios del sitio (`cod_centro`, `centro`) —
     rellenados con valores de prueba.
  2. El servicio externo `courseascode` no incluía `core_course_get_courses`
     (la usa el engine, §5.3b) — añadida al servicio.
  3. **Bug plugin vs Moodle 6.0**: `add_module`/`update_module` ya no existen
     → migrado a `add_moduleinfo`/`update_moduleinfo` en `book_helper`.
  4. **Bug plugin**: en el update hay que pasar el intro como `introeditor`
     (Moodle 6 lo pisa a null si viene como `intro` plano y el UPDATE falla).
  5. **Bug plugin vs contrato**: `get_book` lanzaba excepción si el bookid no
     existe; el contrato dice "null" (lo usan planner y drift §19.1) — ahora
     devuelve estructura vacía, patrón de `get_deploy`.
  6. Registro de deploy envenenado (`bookid=0`) del primer intento fallido —
     eliminado.
- **Ciclo §32 contra Moodle real**: importación v1.0.0 → `UP_TO_DATE` (Book
  visible en sección 2 de CAC-TEST, 5 capítulos mapeados); redeploy
  idempotente (0 acciones); v1.1.0 publicada en `contenidos-test` →
  `UPDATE_AVAILABLE` ("disponible: 1.1.0"); plan muestra el diff §22
  (`~ 02-mvc.md`, `+ 06-recursos.md`); update aplica UPDATE+CREATE; rollback
  a v1.0.0 omite el DELETE sin confirmación (warning §17.2) y lo aplica con
  `confirmed_deletions`; `where_deployed` → `[2]`. Las **9 funciones** del
  contrato ejercitadas en vivo.
- **Pendiente**: probar la UI del plugin con un profesor real (PRU-020,
  esperando datos), hotfix critical/recall (PRU-033), drift (PRU-034),
  export (PRU-035) y `make test-integration` contra este Moodle (PRU-036).

### 2026-11-06 — Sesión 14: Despliegue alpha en el Moodle de pruebas (primera aproximación)

**Hecho y verificado:**

- Acceso SSH root a `192.168.2.253`. Moodle = contenedor `moodle53beta-moodle`
  (¡es **6.0dev** Build 20261021, no 5.3!) + MariaDB; red interna docker
  `8088-...moodle-internal`. Curso existente: `CAC-TEST` (id 2); usuarios:
  solo `admin` y `svc-courseascode` (los datos los carga el equipo).
- **Plugin instalado y actualizado** a 2026110600 (rsync al bind mount +
  `php -l` verde + `admin/cli/upgrade.php --allow-unstable`). `config.php`
  configurado con `local_courseascode_service_url` (interna,
  `http://courseascode:8000`) y `service_public_url` (`http://192.168.2.253:8090`).
  ⚠️ `config.php` no es bind mount: efímero si se recrea el contenedor.
- **Web services**: ya existían (REST + servicio `courseascode` 9 funciones +
  token de `svc-courseascode`); rol `courseascodesvc` completado con
  `webservice/rest:use`, `course:view/update/manageactivities`,
  `mod/book:read/edit`.
- **Engine desplegado**: contenedor `courseascode-engine` (imagen construida
  en el servidor), volumen `courseascode-data`, clon de `contenidos-test` en
  `/content`. `GET /catalog` responde con `@dwes/ut03-mvc` v1.0.0 ✅.

**Bugs reales encontrados y corregidos (engine):**

1. `default_db_url()` no aceptaba rutas peladas (`/data/courseascode.db`, el
   default del propio Dockerfile) → SQLAlchemy `ArgumentError`. Ahora convierte
   ruta → `sqlite:///`.
2. Dockerfile sin `git` (LocalGitProvider usa `git worktree` por CLI) y sin
   `safe.directory` para `/content` → `dubious ownership` y luego
   `Permission denied` en `.git/worktrees` (resuelto además con chown 1000 del
   clon en el host; los fetch de tags deben hacerse **dentro** del contenedor).
3. El plugin enlazaba `/static/theme-default.css` para inyectar el CSS en
   `mod-book-view` y **el endpoint no existía** → añadido
   `GET /static/{tema}.css` (+2 tests).
4. Hairpin NAT roto en el host (los contenedores no alcanzan los puertos
   publicados del propio host) + Moodle exige Host = `$CFG->wwwroot` (303 si
   no) → `MoodleRestClient` admite ahora `connect_url` (`MOODLE_CONNECT_URL`)
   que conecta al forwarder `cac-moodle-fwd` (socat 192.168.96.10:8088) con
   header Host = wwwroot público (+2 tests). **Rebuild del engine en el
   servidor PENDIENTE** (interrumpido antes de redesplegar; suite local:
   ruff ✓, mypy ✓, tests del cliente REST ✓; suite completa por re-correr).

**Incidencia aislada (NO fue el proyecto):** caída del Moodle (500 en
login/admin) causada por `local/educaaragon` — copiado al contenedor el mismo
día 12:24 por el equipo — que redeclara `clean_string()` del core
(`weblib.php:775`). Fix en caliente en el contenedor (renombrada a
`educaaragon_clean_string`, declaración + 6 llamadas) → login 200 de nuevo.
⚠️ Efímero: educaaragon no es bind mount; el fix debe subir a su repo.

**Diagnóstico:** con debugdisplay los errores llegan a la página; se dejó
`debug=32767`/`debugdisplay=1` en la BD del Moodle (útil en alpha; el aviso de
deprecación del hook legacy `before_standard_html_head` del plugin saldrá en
pantalla — migración al nuevo hook de Moodle 6 pendiente).

### 2026-11-06 — Sesión 13: Pruebas alpha — preparación del entorno

- Estudiado `docs/Sistema-prueba.md` y creada la tasklist
  `docs/Tasklist — Pruebas alpha.md` (PRU-001–036): infraestructura,
  contenidos, usuarios/roles y ciclo E2E §32 contra Moodle real.
- **Servidor Moodle localizado**: `http://192.168.2.253:8088` (responde por
  **http**, no https como decía el documento; https no escucha). Sitio
  "Moodle53Beta" operativo (login 200).
- **Repo `FPVirtual/contenidos-test` sembrado** (estaba vacío): `course.yml`
  (dwes) + book `ut03-mvc` (5 capítulos, SVG, admonitions, enlaces internos)
  + README. `courseascode validate` en verde; tag `book/ut03-mvc/v1.0.0`
  publicado y pusheado; `courseascode info` resuelve `@dwes/ut03-mvc` v1.0.0.
  Clon local en `~/Proyectos/contenidos-test`.
- **Bloqueante (PRU-001)**: sin credenciales de administrador del Moodle no se
  puede instalar el plugin ni comprobar los profesores del curso DWES.

### 2026-11-06 — Sesión 12: Pista 🔌 — páginas UI del plugin completas

- **Catálogo (`catalog.php`, §12.2)**: tarjetas por fuente con título,
  summary, tags, audience, nº de capítulos, última versión, preview
  (ENG-044, mismo renderer que el deploy) y "Añadir al curso" solo con
  `:deploy` + `moodle/course:manageactivities` (§12.3). Corregido el campo
  del JSON: el catálogo expone `source`, no `name`.
- **Página de versión (`bookversion.php`, §21/§22)**: estado §19 con badge
  de severidad (§11), metadata Git solo para profesores (capability
  `viewcatalog`), binding local (`deployed_at`, `deployed_by`, commit),
  lista de versiones desc con preview por tag, y "Ver cambios" →
  `POST /deployment/plan`: diff estructural §17.1, avisos, eliminaciones
  con checkbox de confirmación explícita (§17.2) y formulario de deploy.
- **`deploy.php` nuevo (§12.3 paso 4)**: solo POST (`data_submitted()`),
  capabilities `:deploy` + `manageactivities`, `POST /deploy` del engine y
  redirect con notificación de éxito/error (mensajes accionables del
  engine; 409 RECALLED se muestra tal cual — el `force` queda en la CLI
  del coordinador, documentado en el README).
- **Panel del curso (`admin.php`, §23.1)**: tabla Book / Published /
  Available / Estado / Acciones desde el binding local + `GET
  /deployment/status` por Book (tolerante a fallos por Book), enlace "Ver
  en el curso" vía `book_helper::coursemodule_id` y botón "Actualizar"
  directo al plan cuando hay `UPDATE_AVAILABLE`.
- **`lib.php`**: `service_request` genérico con chequeo de status HTTP
  (los errores del engine llegan como `{"detail": ...}`), `service_post`,
  `preview_url`, `course_deploys` (último binding por Book), `book_state`
  tolerante a fallos y helpers de badge `state_label`/`state_class`.
  Entrada del panel en la navegación del curso para deployers.
- Templates Mustache reescritos (Bootstrap 5: `badge bg-*`), 30 strings
  nuevos en `lang/en`, version bump a 2026110600 (release 0.2.0).
- **No verificable en este entorno** (sin PHP): `php -l` y el smoke E2E
  contra moodle-docker siguen pendientes — incluye renderizado real de los
  templates.

### 2026-10-23 — Sesión 11: Pista 🔌 — esqueleto del plugin `local_courseascode` (Moodle 5.3)

- **Plugin construido a mano en `plugin/`** (a instalar como `local/courseascode`):
  `version.php` (requires 2026100600 ≈ Moodle 5.3.0, dependencia `mod_book`),
  `db/install.xml` (tabla `local_courseascode_deploy` + mapping
  `local_courseascode_chapter` UNIQUE `bookid+stable_id`), `db/services.php`
  con 9 funciones y `db/access.php` con capabilities `:deploy`,
  `:viewcatalog` (curso) y `:manage` (sistema).
- **9 clases external** en `classes/external/` con helpers en
  `classes/local/book_helper.php` (instancia mod_book idempotente por nombre,
  mapping stable_id↔chapterid, serialización `deployed_at` epoch↔ISO 8601) y
  `classes/privacy/provider.php` (null_provider).
- **Contrato cotejado con el engine** (`functions.py` + `rest_client.py`):
  nombres 9/9 coincidentes. Correcciones de la verificación estática:
  `get_deploy` devuelve la fila en el nivel raíz y `null` cuando no hay
  despliegue (el parser del engine trata `None`/`{}`/`[]` como "sin
  resultado" y valida el resto como `DeployRecord`); `upload_asset` borra
  solo el fichero del mismo nombre (antes purgaba todos los assets del
  Book); `upsert_chapter`/`delete_chapter` devuelven `external_single_structure`
  vacío en vez de `external_function_parameters`.
- **Capa de visualización**: `catalog.php`, `bookversion.php`, `admin.php`
  con templates Mustache (🟡 esqueletos), CSS inyectado solo en
  `mod-book-view` y navegación de curso vía `lib.php`.
- **No verificable en este entorno** (sin PHP): `php -l`, instalación y
  smoke E2E contra moodle-docker quedan pendientes para el entorno con
  Moodle. El hito crítico (tabla + upsert + capabilities) queda cubierto
  del lado engine-del-contrato.

### 2026-10-23 — Sesión 10: Fase 9 completa — Beta del engine terminada

- **ENG-100 a ENG-106 implementados**: `export --course` (YAML estable),
  logging structlog con correlation id, errores de despliegue recuperables
  (registro `status="error"` + mensaje accionable + reejecución idempotente),
  `RenderCache` por sha inmutable, suite de aceptación §32 bajo mark
  `integration`, Dockerfile + docker-compose + `python -m courseascode.serve`,
  y documentación completa (README del engine, Guía de autor, Guía de
  operación).
- Cierres hechos a mano tras la interrupción del agente: tests de logging/
  error-recovery/render-cache (14), bug del caché descartado por falsedad en
  `create_app`, tipado de `serve.py`, lint y docs.
- **DoD verificado**: `make check` verde (ruff ✓, mypy strict ✓, **293 tests**
  ✓); integración colecciona y skipea sin moodle-docker. **M4 cerrado — Beta
  del engine completa (ENG-001–106).**
- **Pendiente fuera del engine:** pista 🔌 del plugin `local_courseascode`
  (tabla, web services, páginas) y la verificación E2E real contra
  moodle-docker cuando el plugin exista.

### 2026-10-19 — Sesión 9: Fase 8 completa (releases, severidad y notificaciones)

- **ENG-090 a ENG-095 implementados**: `ReleaseStore` SQLite + SQLModel
  (`Release`, `AuditEvent` append-only), `SqlReleasePort` cerrando el circuito
  con planner/estados, CLI `release mark/list` con token de coordinador,
  webhook `POST /hooks/git` con firma HMAC-SHA256, `GET /releases/affected` y
  auditoría (`release.mark` como invariante del store; lectura admin en
  `GET /audit`). 38 tests nuevos.
- Incidentes de cierre: la auditoría del mark se centralizó en el store
  (el CLI y los helpers de test duplicaban el evento → 2 tests en rojo hasta
  eliminar los `record_event` redundantes).
- **DoD verificado**: smoke E2E del hotfix crítico de §11.1 (badge crítico,
  recall bloquea, `--force` avisa, `where` afectados, auditoría completa).
  `make check` verde: ruff ✓, mypy strict ✓, **274 tests** ✓.
- **Pendiente para la próxima sesión:** Fase 9 (ENG-100–106, última): `export
  --course`, logging structlog, errores recuperables, caché de checkouts/
  renders, suite E2E con moodle-docker 🔌 (mark `integration`), Dockerfile +
  docker-compose y documentación (guía de autor, guía de operación). Deps
  previstas: structlog.

### 2026-10-15 — Sesión 8: Fase 7 completa (deployment engine)

- **ENG-070 a ENG-080 implementados**: `Planner` (diff contra HTML real de
  Moodle), `Executor` idempotente con resolución de placeholders en caliente,
  servicio de pipeline §17 (dos pasadas de assets), estados (§19) con drift,
  bloqueo RECALLED con `--force`, CLI `deploy`/`status`, 4 endpoints de
  deployment y 68 tests nuevos con E2E completo contra el fake.
- Decisiones: `content_hash` = JSON `{stable_id: sha256}`; `ReleasePort` con
  `NullReleasePort` (Fase 8 lo vuelca a SQLite); 8ª/9ª funciones asumidas del
  plugin (`get_book`, `where_deployed`) documentadas en `functions.py`; URL de
  asset del fake corregida a `pluginfile.php` real.
- **DoD verificado**: §32 (ciclos básico/update/rollback) contra el fake; smoke
  E2E real con rollback byte-exacto. `make check` verde: ruff ✓, mypy strict ✓,
  **236 tests** ✓. **M3 (deploy + rollback) cerrado contra el fake.**
- **Pendiente para la próxima sesión:** Fase 8 (ENG-090–095): persistencia
  SQLite + SQLModel (`Release`, `AuditEvent`), `release mark/list` real
  (sustituye `NullReleasePort`), webhook `POST /hooks/git` con firma, endpoint
  de afectados 🔌 y auditoría. Deps nuevas previstas: sqlmodel.

### 2026-10-09 — Sesión 7: Fase 6 completa (Moodle adapter)

- **ENG-060 a ENG-064 implementados**: `MoodleProvider` (ABC), `MoodleRestClient`
  (httpx, retries con backoff solo en red), `PLUGIN_FUNCTIONS` como fuente de
  verdad del contrato con el plugin 🔌, `FakeMoodleProvider` determinista y
  tests de contrato parametrizados fake/Moodle real (mark `integration`).
- Decisiones: `DeployRecord` y `MoodleCourse` en `moodle/` (no en `domain/`);
  cliente httpx inyectable; `RetryPolicy` desactivable en tests; asunciones del
  contrato documentadas en `functions.py` (`get_deploy` con `source`, 7ª
  función `list_deploys`, assets base64, `deployed_at` ISO 8601).
- Tooling: `httpx` a dependencies; marker `integration` registrado con target
  `make test-integration`; pin de ruff en pre-commit alineado al venv.
- **DoD verificado**: contrato corre contra fake; integración colecciona y
  skipea sin Moodle. `make check` verde: ruff ✓, mypy strict ✓, **168 tests** ✓
  (+10 skipped). **M2 (catálogo + adapter) cerrado.**
- **Pendiente para la próxima sesión:** Fase 7 (ENG-070–080): `Planner`
  (diff → plan CREATE/UPDATE/DELETE/UPLOAD), assets en dos pasadas, `Executor`
  idempotente con `content_hash`, estados, comandos `deploy`/`status`,
  endpoints `/deploy`… y tests E2E contra el fake. Es la fase más larga del
  proyecto junto a F3 — vigilar especialmente ENG-072 (dos pasadas).

### 2026-10-07 — Sesión 6: Fase 5 completa (catálogo)

- **ENG-050 a ENG-054 implementados**: `CatalogBuilder` + modelos pydantic,
  `SourceRegistry` con ACL, endpoints `GET /catalog` y
  `/catalog/{source}/{book}/versions` en la app FastAPI (header opcional
  `X-CourseAsCode-User`; auth real pendiente del plugin 🔌) y comandos CLI
  `info` (formato §29) y `build` (render a disco con tema, principio 5).
- Decisiones: lista de books del `course.yml` del working tree; metadatos y
  capítulos siempre del último tag; versiones ordenadas asc con `latest`
  explícito; namespace `@dwes` = fuente oficial del registry (presentación).
- **DoD verificado**: `GET /catalog` con JSON completo y versiones ordenadas;
  smoke test real de `info`/`build`. `make check` verde: ruff ✓, mypy strict ✓,
  **140 tests** ✓. Sin deps nuevas.
- **Pendiente para la próxima sesión:** Fase 6 (ENG-060–064): interfaz
  `MoodleProvider`, `MoodleRestClient` (httpx), `FakeMoodleProvider` y tests de
  contrato. Prerequisito: añadir `httpx` a dependencies (ya está en dev) y
  acordar con el equipo del plugin los 6 contratos `local_courseascode_*` 🔌.
  **Hito crítico plugin:** tabla + `upsert_book/chapter` + token antes de
  terminar F6.

### 2026-10-07 — Sesión 5: Fase 4 completa (preview web)

- **ENG-040 a ENG-044 implementados**: app FastAPI (`web/app.py`) con índice de
  books, página de capítulo con TOC + selector de ref/tema, assets servidos
  blindados contra traversal, comando `preview [--ref] [--port]` y endpoint
  público `GET /catalog/{source}/{book}/preview?ref=` (latest tag por defecto
  vía `VersionResolver`).
- **Decisión clave — watch**: re-render por petición sin caché en vez de
  `watchfiles`; guardar + refrescar cumple el DoD sin dependencias extra.
  Documentada en la docstring de `web/app.py` y en la entrada de fase.
- Incidentes resueltos al verificar: `FileResponse` leía el asset en lazy tras
  eliminar el worktree del ref (se lee dentro del checkout); un test CLI
  arrancaba uvicorn real y colgaba la suite (mock añadido).
- Deps nuevas: fastapi, uvicorn (+ httpx en dev). **M1 (validate + preview) cerrado.**
- **DoD verificado**: smoke test real con `courseascode preview` (capítulo, asset
  200, edición en caliente sin reiniciar). `make check` verde: ruff ✓,
  mypy strict ✓, **113 tests** ✓.
- **Pendiente para la próxima sesión:** Fase 5 (ENG-050–054): `CatalogBuilder`
  (del último tag, nunca HEAD), `SourceRegistry` con ACL, endpoints
  `GET /catalog` y `/catalog/{source}/{book}/versions`, comandos `info` y `build`.

### 2026-10-01 — Sesión 1: arranque del proyecto

- Estudiada la documentación en `docs/` (spec v0.2, diagramas S1–S10, tasklist, prompt 0.1 histórico).
- Decisión de estructura: monorepo; el engine vive en `engine/` con src-layout
  (hatchling). Podrá extraerse a repo propio sin cambios.
- **Fase 0 completa** (ENG-001 a ENG-006) con DoD verificado en local:
  27 tests, ruff y mypy `--strict` en verde, `courseascode --help` operativo.
- Añadidos `py.typed` (mypy strict) y hooks pydantic en `SemVer`/`ContentId`.
- Creada esta bitácora y README del proyecto con diagramas.
- **Pendiente para la próxima sesión:** Fase 1 (ENG-010 a ENG-016). Prerequisitos:
  añadir dependencias PyYAML + GitPython al `pyproject.toml` al empezar ENG-010/020.

### 2026-10-01 — Sesión 2: Fase 1 completa (manifests y validación)

- **ENG-010 a ENG-016 implementados**: esquemas pydantic (`CourseManifest`,
  `BookManifest`), `ManifestParser` con errores localizados, `Validator` con las
  reglas de §20, salida ✓/✗ con exit 0/1, comando `validate [--ref]` (worktree
  temporal provisional en `git/worktree.py`) y fixtures (1 válido + 10 inválidos).
- Dependencias añadidas: `pyyaml`, `markdown-it-py`, `types-PyYAML`.
- **DoD verificado**: fixture válido → `Validation successful.`; los 10 inválidos
  fallan con su error específico; `--ref` valida el tag, no el disco.
  `make check` verde: ruff ✓, mypy strict ✓, **48 tests** ✓.
- **Pendiente para la próxima sesión:** Fase 2 (ENG-020–026). Prerequisitos:
  añadir `GitPython` al `pyproject.toml`; al formalizar `GitProvider`, migrar
  `git/worktree.py` (ENG-015) a la interfaz nueva.

### 2026-10-01 — Sesión 3: Fase 2 completa (Git adapter)

- **ENG-020 a ENG-026 implementados**: interfaz `GitProvider` (10 operaciones),
  `LocalGitProvider` (GitPython + worktrees por CLI), `Diff`/`BookDiff`
  clasificado, `VersionResolver` (tags y versión derivada `1.2.0+juan.3`),
  `detect_source`, fixture Git en `tests/conftest.py` y 21 tests nuevos.
- Migrado el worktree provisional de ENG-015 al provider (el CLI `validate --ref`
  ya no usa `git/worktree.py`, eliminado).
- Dep añadida: `GitPython>=3.1.43`.
- **DoD verificado**: rama `juan` → `1.2.0+juan.3` (3 commits propios); `main` →
  `1.2.0+main.0`. `make check` verde: ruff ✓, mypy strict ✓, **69 tests** ✓.
- **Pendiente para la próxima sesión:** Fase 3 (ENG-030–037, renderer). Prerequisitos:
  añadir `Pygments`, `Jinja2` y `bleach` (o allowlist propia) al `pyproject.toml`.
  Tareas de mayor riesgo según tasklist: ENG-034 (link resolver) y ENG-035 (asset resolver).

### 2026-10-02 — Sesión 4: Fase 3 completa (content engine / renderer)

- **ENG-030 a ENG-037 implementados**: pipeline Markdown único (gfm-like +
  admonitions `:::` + Pygments inline), sanitización bleach con CSSSanitizer,
  tema `theme-default` (CSS + plantilla Jinja2), link/asset resolvers con
  adapters preview/Moodle, API `render_book` y tests golden-file
  (`tests/fixtures/golden/components.{md,html}`).
- Decisiones y hallazgos: el plugin container de mdit-py-plugins exige el
  marcador `":"` (3 repeticiones = `:::`); el PhpLexer solo resalta dentro de
  `<?php`; linkify desactivado (evita dep linkify-it-py); overrides mypy para
  bleach/mdit_py_plugins; deps nuevas: pygments, jinja2, bleach, tinycss2,
  mdit-py-plugins, types-Pygments.
- **DoD verificado**: corpus completo renderizado; mismos adapters cambian URLs
  sin tocar el renderer. `make check` verde: ruff ✓, mypy strict ✓, **88 tests** ✓.
- **Pendiente para la próxima sesión:** Fase 4 (ENG-040–044): app FastAPI de
  preview, comando `preview` con watch y endpoint público para el plugin.
  Prerequisito: añadir `fastapi` + `uvicorn` al pyproject.

## Cómo actualizar esta bitácora

1. Al terminar una tarea, márcala `[x]` y anota en el registro de sesiones qué se
   hizo, qué se verificó (comandos y resultado) y qué queda pendiente.
2. Al cerrar una fase, verifica su Definition of Done contra la tasklist y consigna
   la fecha.
3. Si una decisión contradice la arquitectura v1 (D1–D10), **no la pongas en práctica**: anótala aquí
   como "decisión pendiente de validar" y consúltala.
