# Tasklist — Desarrollo LiaEditor–Moodle (v2)

> Trabajo **pendiente** del sistema Materiales Editables: plugin
> `local_liamateriales` (🔌), engine `materiales_liascript-git` (⚙️) y
> editor `fpvirtual-crepe-editor` (✏️). Lo ya desarrollado no aparece aquí:
> está resumido en §1 y detallado en la [bitácora](../BITACORA.md) y en el
> historial de Git.
>
> Referencias: [arquitectura v1 (D1–D10)](desarrollo-liaeditor-moodle-v1.md) ·
> [roadmap del editor Crepe](roadmap-editor-crepe.md) ·
> [flujos de datos](Flujos%20de%20datos%20—%20plugin,%20engine%20y%20editor.md).
>
> Estimación: **S** < 1 día · **M** 1–3 días · **L** 3–5 días · **XL** > 5 días.
> Los IDs continúan la numeración anterior (`LIA-nnn`).

---

## 1. Punto de partida (ya hecho)

- **Flujo de edición completo** (antiguas fases 1 y 2, LIA-001…042):
  ramas `edicion/*`, guardado con conflicto 409 y rama `resolucion/*`,
  tokens de edición, `content.php`/`save.php`/`token.php`, publicación
  manual y en un clic (`publish_api.php` + `POST /publish` con liaex en
  Docker), estado por SHA en la página de materiales.
- **Flujo legado `mod_book` eliminado** (LIA-055, sesión 24).
- **Editor Crepe** (repo propio, sustituye al fork de LiveEditor): Fase 0
  con *go* condicionado y M1 casi completa (guardar, 409, sesión caducada,
  renovar token, publicar, borrador local). Desplegado en :8092.
- **Arreglos de plugin 2.2.0**: Publicar tras guardar (curso en el token),
  `publish_api.php` como el usuario del token y con capacidades, reutilizar
  solo el módulo del mismo formato, tokens de vista previa de solo lectura,
  `edit.php` sin ramas ajenas.

## 2. Cambios respecto a la versión anterior de este tasklist

- **Eliminadas** todas las tareas terminadas (LIA-001…042 y LIA-055).
- **LIA-013 se reabre en parte**: estaba marcada como hecha con «rate
  limiting 60 req/min», pero **ese límite nunca se implementó**. Pasa a
  LIA-064.
- **LIA-054 se retira**: proponía llevar a LiaScript el parche del fork de
  LiveEditor, y el fork se abandona en favor del editor Crepe.
- **LIA-050 y LIA-051 se absorben** en la fase de seguridad (auditoría con
  eventos de Moodle y tarea programada de limpieza).
- **Nuevas fases**: seguridad (S), editor (E) y robustez del backend (B),
  surgidas del análisis técnico (sesión 24), la Fase 0 del editor (sesión
  27) y la revisión de la superficie expuesta del plugin.

## 3. Decisiones pendientes

| # | Decisión | Afecta a |
| --- | --- | --- |
| 1 | **Vista del alumno en el editor** (revisar R4 del roadmap): runtime de LiaScript 2.7 (`@liascript/editor`, el mismo del H5P) en un origen separado | LIA-080…082 |
| 2 | **Nueva R7**: sustituir la «validación síncrona con liaex» (liaex no detecta errores) por una comprobación estructural (huella `fullJson` frente a la versión anterior) | LIA-085 |
| 3 | **Red de liaex en producción**: `none` (sin plantillas importadas) o salida HTTP con lista de dominios permitidos | LIA-097 |
| 4 | **H5P en `mod_h5pactivity`**: confirmar tipo `H5P.LiaScript` 2.7 y seguimiento de notas (xAPI) | LIA-043 |
| 5 | **Retirada del fork de LiveEditor** y periodo de convivencia (ajuste `liaeditor_url`, formato de URL antiguo) | LIA-062, LIA-099 |

---

## FASE S — Seguridad

**Objetivo:** reducir la superficie expuesta. El plugin publica cuatro
scripts sin sesión de Moodle (`content.php`, `save.php`, `token.php`,
`publish_api.php`) protegidos solo por el token de edición, y el engine
acepta cualquier petición mientras tiene acceso al socket de Docker.

**Orden:** LIA-060 → LIA-061 → LIA-062/063 → LIA-064…066 → resto. LIA-071
(infraestructura) puede pedirse ya, en paralelo.

### Canal plugin → engine

- [ ] **LIA-060** (M) 🔌⚙️ **Autenticación entre plugin y engine.**
  - *Motivo:* hoy cualquiera que alcance el puerto del engine puede hacer
    commits en cualquier rama (incluida `main`) o lanzar contenedores con
    `/publish`, y el engine tiene el socket de Docker (equivale a root en
    el host).
  - *Qué:* secreto compartido (ajuste del plugin y variable del engine).
    El plugin firma cada petición con HMAC-SHA256 sobre método, ruta,
    marca de tiempo y cuerpo (cabeceras `X-Liamat-Timestamp` y
    `X-Liamat-Signature`). El engine verifica con una dependencia FastAPI
    común a todos los routers, rechaza firmas inválidas y marcas de más de
    60 s, y compara en tiempo constante. Unificar los tres clientes HTTP
    del plugin (`service_request`, `service_post_full` y el `curl` de
    `publish_api.php`) en una sola clase `engine_client`.
  - *Hecho cuando:* una petición sin firma, con firma errónea o repetida
    pasados 60 s recibe 401; los tests del engine cubren los tres casos y
    el flujo completo sigue funcionando.
- [ ] **LIA-061** (S) ⚙️ **El engine solo en la red interna.**
  - *Motivo:* el puerto 8091 está publicado en la red del servidor, y el
    navegador nunca necesita hablar con el engine.
  - *Qué:* quitar la publicación del puerto (o atarla a `127.0.0.1`),
    dejarlo solo en la red Docker de Moodle y documentarlo en la guía de
    despliegue y en `docker-compose.yml`.
  - *Hecho cuando:* `curl http://<host>:8091/health` desde fuera del
    servidor no conecta y Moodle sigue llegando al engine.

### Token de edición

- [ ] **LIA-062** (M) 🔌✏️ **Código de apertura de un solo uso, en el
  fragmento de la URL.**
  - *Motivo:* el token de edición (válido 15 min para guardar y publicar)
    viaja hoy en la URL del editor: queda en los registros de acceso de
    Moodle y del servidor del editor, en el historial del navegador y en
    el Referer de los recursos externos que carga el material.
  - *Qué:*
    1. `edit.php` emite un **código de apertura** distinto del token:
       aleatorio, válido 60 s y de un solo uso (columna `kind` en la tabla
       de tokens, `open` o `edit`, y marca de usado).
    2. Redirige a `https://editor/#open=<código>&src=<url de content.php>`.
       El fragmento no se envía a ningún servidor.
    3. El editor lo lee, lo borra de la barra de direcciones
       (`history.replaceState`) y hace `POST content.php {code}`.
    4. `content.php` marca el código como gastado y devuelve el Markdown
       con el token de edición, que solo vive en la memoria del editor.
    5. La vista previa usa el mismo mecanismo (código de solo lectura).
    6. Compatibilidad: el formato antiguo `?/open/file/<url>` queda detrás
       de un ajuste (`legacy_open_url`, desactivado por defecto) mientras
       se use el fork.
  - *Hecho cuando:* ninguna URL registrada contiene un token válido; un
    código usado o caducado da 403; el e2e del editor pasa con el flujo
    nuevo.
- [ ] **LIA-063** (S) 🔌✏️ **Cabeceras de protección.**
  - *Motivo:* que la URL de trabajo no se filtre por Referer y limitar qué
    puede cargar la página del editor.
  - *Qué:* `Referrer-Policy: no-referrer` en el editor (meta y servidor
    web) y en las respuestas de los cuatro scripts del plugin; CSP del
    editor documentada en su README (ya hay una de ejemplo) y aplicada en
    el despliegue.
  - *Hecho cuando:* las cabeceras aparecen en las respuestas (comprobación
    con `curl -I`).

### Límites de uso

- [ ] **LIA-064** (M) 🔌✏️ **Límites por usuario en el plugin** (sustituye
  al límite nunca implementado de LIA-013).
  - *Motivo:* cada guardado es un commit y un push, y cada publicación un
    contenedor de liaex; sin límites, un bucle (error o abuso) satura Git
    o el servidor.
  - *Qué:* contadores en la caché de aplicación de Moodle (MUC, ventana
    deslizante) con límites configurables: `content.php` 30/min,
    `save.php` 20/min, `token.php` 6/min y `publish_api.php` 5 cada 10 min
    por usuario. Al superarlo, `429` con `Retry-After`; el editor muestra
    «Demasiadas peticiones, espera unos segundos» sin perder el trabajo.
  - *Hecho cuando:* los tests del plugin superan cada límite y reciben 429;
    el editor trata el 429 (test del puente y e2e).
- [ ] **LIA-065** (S) 🔌 **Una publicación a la vez por usuario y
  material.**
  - *Motivo:* dos clics seguidos en Publicar crean dos módulos o lanzan dos
    liaex en paralelo.
  - *Qué:* bloqueo con la API de Moodle (`\core\lock\lock_config`) por
    usuario y fichero durante `publish_api.php`; si está ocupado, `409`
    con mensaje «Ya se está publicando este material».
  - *Hecho cuando:* dos peticiones simultáneas producen un solo módulo.
- [ ] **LIA-066** (S) 🔌 **Bloqueo temporal por IP tras tokens inválidos.**
  - *Motivo:* adivinar un token de 48 caracteres es inviable, pero un
    barrido de peticiones inválidas carga Moodle y el engine.
  - *Qué:* contador de fallos de token por IP (MUC); tras 20 fallos en
    10 minutos, 429 durante 15 minutos para esa IP.
  - *Hecho cuando:* test que supera el umbral y queda bloqueado.

### Ciclo de vida de tokens y sesiones

- [ ] **LIA-067** (S) 🔌 **Tokens de vista previa bajo demanda.**
  - *Motivo:* `materials.php` emite hoy un token por material **en cada
    carga de la página** y los deja escritos en el HTML; la tabla crece
    sin control y los tokens aparecen en el código fuente de la página.
  - *Qué:* el botón Vista previa apunta a un `preview.php` (con sesión de
    Moodle y capacidad `viewcatalog`) que emite el código de solo lectura
    en ese momento y redirige al editor.
  - *Hecho cuando:* cargar la página de materiales no inserta filas en la
    tabla de tokens.
- [ ] **LIA-068** (S) 🔌 **Tarea programada de limpieza** (absorbe LIA-051).
  - *Qué:* `scheduled_task` diaria que borra tokens caducados o revocados
    de más de 24 h y cierra (`closed_at`) las sesiones de edición sin
    guardados en 7 días.
  - *Hecho cuando:* test de la tarea; aparece en Administración → Tareas
    programadas.

### Trazabilidad y mensajes

- [ ] **LIA-069** (M) 🔌 **Auditoría con eventos de Moodle** (absorbe la
  parte de registro de LIA-050).
  - *Motivo:* hoy no queda rastro en el registro de Moodle de quién abrió,
    guardó o publicó un material.
  - *Qué:* eventos `material_opened`, `material_saved`,
    `material_conflict` y `material_published` (con fichero, rama, SHA y
    cmid) en el registro estándar; visibles en los informes del curso.
  - *Hecho cuando:* cada acción del e2e real deja su evento en
    `logstore_standard_log`.
- [ ] **LIA-070** (S) 🔌 **Errores genéricos hacia el navegador.**
  - *Motivo:* algunos 502 devuelven el detalle interno del engine (rutas,
    mensajes de Git).
  - *Qué:* registrar el detalle en el log del servidor (`debugging` o
    evento) y responder un mensaje genérico con un identificador de
    incidencia.
  - *Hecho cuando:* ninguna respuesta de los cuatro scripts incluye texto
    del engine.

### Infraestructura

- [ ] **LIA-071** (S) 🌐 **Límite en el proxy delante de Moodle** (lo
  configuran los administradores).
  - *Qué:* límite por IP solo para `/local/liamateriales/(content|save|token|publish_api).php`,
    por ejemplo en Nginx `limit_req zone=liamat rate=60r/m burst=20`, o el
    equivalente en Apache. Es la capa de volumen; LIA-064 es la capa por
    usuario.
  - *Hecho cuando:* documentado en la guía de despliegue y aplicado en el
    entorno de pruebas.

**DoD Fase S:** el engine no es accesible desde fuera y rechaza peticiones
sin firma; ninguna URL contiene un token de edición válido; los cuatro
scripts aplican límites (429) y cabeceras de protección; las acciones dejan
eventos en el registro de Moodle; e2e del editor y tests del plugin y del
engine en verde.

---

## FASE E — Editor Crepe (✏️, repo `fpvirtual-crepe-editor`)

Continúa el [roadmap del editor](roadmap-editor-crepe.md). M1 está casi
completa; quedan:

- [ ] **LIA-080** (L) **Vista del alumno en directo** (decisión nº 1).
  Panel a la derecha (o pestaña en pantallas estrechas) con el runtime de
  LiaScript 2.7 servido desde un **origen separado** y comunicado solo por
  `postMessage` (los materiales pueden ejecutar scripts y el editor guarda
  el token). Actualización con retardo de unos 0,5 s y sincronización de
  sección en ambos sentidos. Medir con un tema largo.
- [ ] **LIA-081** (M) **Rediseño del área de edición**: índice lateral de
  secciones (cada `#` es una página del H5P), separadores de página,
  tipografía cercana a LiaScript y tarjetas que llevan a su posición en la
  vista del alumno.
- [ ] **LIA-082** (M) **«Comprobar el H5P exacto»** antes de publicar: el
  engine genera el H5P real y el editor lo reproduce con `h5p-standalone`.
- [ ] **LIA-083** (M) **Panel de cabecera**: formulario para título,
  autor, idioma, versión y comentario que edita las líneas de la cabecera
  sin tocar el resto (macros, imports).
- [ ] **LIA-084** (M) **Modo fuente** (Monaco, carga bajo demanda): solo
  lectura por defecto; editable con capacidad avanzada; al volver al modo
  visual, aviso si la fuente no se puede procesar, sin perder cambios.
- [ ] **LIA-085** (M) ⚙️✏️ **Comprobación estructural al guardar** (nueva
  R7, decisión nº 2): el engine compara la huella `liaex -f fullJson` con la
  de la versión anterior y el editor muestra los avisos (secciones o
  quizzes desaparecidos, cambios de tipo). Solo bloquea si la cabecera
  queda vacía.
- [ ] **LIA-086** (L) **Nodos LiaScript editables** (Fase 2 del roadmap):
  formularios para los quizzes, cajas `@nota/@aviso/@error` y contenido
  editable dentro de los bloques animados `{{n}}`/`****` (hoy en `ut05` el
  57 % del documento queda protegido por ese motivo).
- [ ] **LIA-087** (L) **Assets** (Fase 3 del roadmap): subida de imágenes
  (`upload.php`), commit único `.md` + assets y `asset.php` para verlas al
  editar.
- [ ] **LIA-089** (S) **Logo del centro en los contenidos exportados**: el
  tema ya da colores y tipografía del editor, pero `logo:` de LiaScript es
  la imagen de portada, no el icono de la barra superior. Sustituir el
  pájaro de LiaScript por CSS en `publish/themes/<tema>.css` y verificarlo
  en SCORM y H5P.
- [ ] **LIA-088** (S) **Corpus real**: añadir a los fixtures materiales de
  producción y un tema real de más de 50 páginas (condición del *go* de la
  Fase 0).

---

## FASE W — Generación web estática y PDF de contenido

Detallada en [docs/generacion_web/roadmap.md](generacion_web/roadmap.md).

- [x] **LIA-110** (L) Fase 0: prototipo del lector LiaScript para Pandoc,
  salida web y PDF de contenido (rama `feature/generacion-web`, sesión 36).
  Conformidad con liaex 4/4 y comportamiento en Chrome 12/12. Queda
  comprobar la web en `mod_resource` del Moodle de pruebas.
- [ ] **LIA-111** (L) Fase 1: lector completo, lenguaje intermedio
  especificado y conformidad con liaex en todo el corpus.
- [ ] **LIA-112** (L) Fase 2: salida web (plantilla, `liamat.js`,
  accesibilidad).
- [ ] **LIA-113** (M) Fase 3: PDF de contenido con la plantilla FP Virtual
  (sin quizzes ni estructuras dinámicas).
- [ ] **LIA-114** (M) Fase 4: formatos `web` y `pdf-contenido` en engine,
  plugin y editor.

---

## FASE B — Robustez del backend (⚙️🔌)

Problemas detectados en el análisis técnico (sesión 24) que siguen
abiertos.

- [ ] **LIA-090** (M) ⚙️ **Sincronizar con el remoto**: `fetch` antes de
  abrir o guardar y comprobación de que `main` local no va por detrás de
  `origin/main`. Hoy los cambios hechos directamente en GitHub no se ven.
- [ ] **LIA-091** (S) ⚙️ **Push fallido sin commit huérfano**: si el
  `push` falla tras el commit, devolver la rama local a su SHA anterior y
  responder error. Hoy queda el commit local y el siguiente guardado da un
  409 falso.
- [ ] **LIA-092** (S) ⚙️ **Bloqueo por repositorio** alrededor de commit y
  push (las peticiones concurrentes comparten el mismo clon) y límite de
  reintentos en `BranchMovedError` (hoy es recursivo sin límite).
- [ ] **LIA-093** (S) ⚙️ **Ramas sin colisiones**: el nombre de rama usa
  el `username` normalizado, así que `juan.perez` y `juan_perez` comparten
  rama. Añadir un sufijo estable (id de usuario o hash corto).
- [ ] **LIA-094** (S) ⚙️ **Timeouts de liaex controlados**: capturar
  `TimeoutExpired`, parar el contenedor y responder 504 con mensaje claro.
- [ ] **LIA-095** (S) ⚙️ **Fijar la imagen de liaex** por digest (hoy
  `liascript/exporter:latest`) y alinear su versión con la del runtime de
  la vista del alumno (LiaScript 2.7).
- [ ] **LIA-096** (M) 🔌 **Rendimiento de la página de materiales**: caché
  de títulos por SHA de `main` (hoy se lee cada `.md` en cada carga) y una
  sola llamada de estado para todos los materiales publicados (hoy, una
  por material).
- [ ] **LIA-097** (S) ⚙️ **Red de liaex en producción** (decisión nº 3):
  aplicar la política elegida (`MATERIALES_LIASCRIPT_LIAEX_NETWORK`, ya
  configurable).
- [ ] **LIA-098** (M) 🔌 **Privacy API real**: las tablas guardan
  `userid`/`username`, así que el *null provider* actual no cumple. Hace
  falta proveedor con metadatos, exportación y borrado.
- [ ] **LIA-099** (M) 🔌 **CI del plugin**: `moodle-plugin-ci` (phpcs,
  phplint) y PHPUnit para `token_manager`, `inject_lms_block`, `publisher`
  y los límites de LIA-064. Hoy el plugin no tiene ningún test automático.
- [ ] **LIA-100** (S) 🔌 **Publicación asíncrona** (si las pruebas de
  carga lo exigen): tarea ad hoc de Moodle y consulta de estado desde el
  editor, en lugar de esperar a liaex dentro de la petición.

---

## FASE P — Producción

- [ ] **LIA-043** (M) **PoC H5P en `mod_h5pactivity`** (decisión nº 4):
  publicar el H5P de liaex (`H5P.LiaScript` 2.7) y verificar visualización
  y registro de notas.
- [ ] **LIA-050** (M) **Monitorización** (la parte de auditoría pasa a
  LIA-069): alertas si el token de Git del engine caduca o el push falla
  de forma repetida; salud del engine y de liaex.
- [ ] **LIA-052** (M) **Documentación de usuario**: guía del profesor
  (editar, guardar, conflictos, publicar, vista del alumno) y guía de
  despliegue y operación (editor, engine, secretos, proxy, red de liaex).
- [ ] **LIA-053** (M) **Pruebas de carga**: 100 profesores simultáneos
  guardando y publicando; validar los límites de LIA-064 y decidir
  LIA-100.

**DoD de producción:** Fases S y P completas, LIA-090…099 resueltas, fork
de LiveEditor retirado y `liaeditor_url` apuntando al editor Crepe
definitivo con HTTPS.
