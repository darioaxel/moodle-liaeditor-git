# Flujos de datos — plugin, engine y editor

Cómo colaboran los tres componentes de *Materiales Editables* en cada
operación del profesorado: **abrir un material para editarlo**,
**guardarlo** y **publicarlo** en el curso.

Estado descrito: plugin `local_liamateriales` 2.2.1 (2026112300), engine
`materiales_liascript-git` 2.0.0 y editor `fpvirtual-crepe-editor` (M1).

## 1. Componentes y canales

```mermaid
flowchart LR
    subgraph NAV["Navegador del profesor"]
        UI["Páginas de Moodle<br/>materials.php, edit.php, publish.php"]
        ED["Editor Crepe (SPA)<br/>origen propio, p. ej. :8092"]
        IDB[("IndexedDB<br/>borradores locales")]
    end

    subgraph MOODLE["Moodle"]
        PL["Plugin local_liamateriales<br/>content.php, save.php, token.php,<br/>publish_api.php"]
        DB[("BD Moodle<br/>token, editsess, deploy")]
        MOD["Módulos del curso<br/>mod_h5pactivity / mod_resource"]
    end

    subgraph ENG["Engine (FastAPI)"]
        API["Endpoints<br/>/edit/* y /publish"]
        REPO[("Clon local del repo<br/>de contenidos")]
        LIAEX["liaex en Docker<br/>liascript/exporter"]
    end

    GH[("GitHub<br/>repo de contenidos")]

    UI -- "cookie de sesión Moodle" --> PL
    ED -- "token de edición + CORS" --> PL
    ED <--> IDB
    PL <--> DB
    PL --> MOD
    PL -- "HTTP servidor a servidor" --> API
    API <--> REPO
    API --> LIAEX
    REPO -- "git push con credenciales del servidor" --> GH
```

| Canal | Autenticación | Notas |
| --- | --- | --- |
| Páginas de Moodle → plugin | Sesión de Moodle (cookie) y capacidades `local/liamateriales:*` | `materials.php`, `edit.php`, `publish.php` |
| Editor → plugin | **Token** en el cuerpo o la URL (D9); CORS restringido al origen del editor | Las cookies de Moodle no viajan en un `fetch` entre orígenes |
| Plugin → engine | Ninguna hoy (red interna) | Pendiente: secreto compartido (ver §6) |
| Engine → GitHub | Credenciales del `origin` del clon (D1) | El profesorado no necesita cuenta de GitHub |

El **token** es una cadena aleatoria de 48 caracteres. En la BD solo se
guarda su `sha256`, ligado a usuario, fichero, rama, curso de origen y modo
(`readonly`). Caduca a los 15 minutos (ajuste `token_ttl`) y se renueva en
cada guardado y de forma periódica.

## 2. Abrir un material para editarlo

### 2.1 De la página de materiales al editor

```mermaid
sequenceDiagram
    autonumber
    actor P as Profesor
    participant M as Moodle (plugin)
    participant E as Engine
    participant G as Repo local / GitHub
    participant B as Editor (navegador)

    P->>M: GET materials.php?id=curso
    M->>E: GET /edit/files
    E->>G: lista los .md de main y lee su título
    E-->>M: files [{path, title}]
    M-->>P: Contenidos Master, Mis Contenidos, Publicados

    P->>M: Editar → GET edit.php?id=curso&file=...
    Note over M: require_login y capacidad :edit<br/>valida la ruta (.md, sin "..")
    M->>E: POST /edit/open {user, file}
    alt la rama edicion/usuario/fichero no existe
        E->>G: crea la rama desde main y hace push
    end
    E-->>M: {branch, sha, content}
    Note over M: emite el token (usuario, fichero, rama, curso)<br/>abre o actualiza la sesión en editsess
    M-->>P: 303 al editor ?/open/file/ + content.php?file&branch&token

    P->>B: el navegador carga el editor
    B->>M: GET content.php?file&branch&token (CORS)
    Note over M: valida el token<br/>el modo readonly lo decide el token
    M->>E: GET /edit/content?file&branch
    E->>G: lee el fichero y el HEAD de la rama
    E-->>M: {content, sha, committed_at}
    Note over M: inyecta el bloque liascript-lms<br/>justo antes del final de la cabecera
    M-->>B: Markdown + bloque liascript-lms
```

### 2.2 Qué hace el editor con lo que recibe

```mermaid
flowchart TD
    A["Markdown recibido de content.php"] --> B["splitDocument:<br/>separa la cabecera y extrae el bloque liascript-lms"]
    B --> C["LmsConfig<br/>save_endpoint, publish_endpoint, token_refresh_endpoint,<br/>file, branch, sha, committed_at, token, readonly, return_url"]
    B --> D["Cabecera sin el bloque<br/>se guarda aparte, literal"]
    B --> E["Cuerpo"]
    E --> F["Crepe / Milkdown<br/>transformer LiaScript: bloques protegidos con su fuente literal"]
    F --> G["SourcePreserver<br/>forma canónica de cada bloque original"]
    C --> H["LmsBridge<br/>guardar, renovar el token, publicar"]
    C --> I{"¿Hay un borrador en IndexedDB<br/>para fichero@rama?"}
    I -- "mismo SHA" --> J["Ofrece recuperar o descartar"]
    I -- "SHA distinto" --> K["Avisa: borrador de una versión anterior"]
    I -- "no" --> L["Editor listo"]
```

### 2.3 Fecha de la versión (`committed_at`)

El engine añade a `/edit/content`, `/edit/open` y `/edit/save` la fecha del
commit (committer date, ISO 8601 con zona, como `git log -1 --format=%cI`).
El plugin la pasa al bloque `liascript-lms` (justo después de `sha`) y a la
respuesta 200 de `save.php` solo si tiene ese formato; si no, omite la clave.
El editor muestra «rama · versión del dd/mm/aaaa, hh:mm» en lugar del SHA.

### 2.4 Vista previa (solo lectura)

La página de materiales emite para cada material un **token de solo
lectura** (`readonly = 1`, sin curso) sobre `main`. `content.php` inyecta
`readonly: true` y el editor oculta Guardar y Publicar. Aunque se
manipulara el editor, `save.php` y `publish_api.php` rechazan ese token con
403.

## 3. Guardar

### 3.1 Petición de guardado y respuestas

```mermaid
sequenceDiagram
    autonumber
    actor P as Profesor
    participant B as Editor
    participant M as Plugin (save.php)
    participant E as Engine
    participant G as Repo local / GitHub

    P->>B: Guardar o Ctrl+S
    Note over B: getMarkdown: los bloques sin editar<br/>salen con su texto original<br/>si no hay cambios, no se envía nada
    B->>M: POST save.php {file, branch, original_sha, content, token}
    Note over M: valida el token con permiso de escritura<br/>tamaño máximo (max_file_size)
    M->>E: POST /edit/save {user, file, branch, original_sha, content}

    alt el HEAD de la rama sigue siendo original_sha
        E->>G: commit del fichero (plumbing, compare-and-swap) y push
        E-->>M: 200 {new_sha, committed_at}
        Note over M: registra new_sha en editsess (D3)<br/>revoca el token y emite otro (mismo curso y modo)
        M-->>B: 200 {new_sha, token, committed_at}
        Note over B: adopta el SHA, el token y la fecha nuevos<br/>borra el borrador local
    else la rama avanzó (otro guardado entre medias)
        E->>G: crea resolucion/usuario/fecha-fichero, commit y push
        E-->>M: 409 {resolution_branch, current_sha, diff_url}
        M-->>B: 409 conflicto
        Note over B: diálogo: ver diferencias,<br/>recargar la versión actual o forzar la mía
    else token inválido, caducado o de solo lectura
        M-->>B: 403
        Note over B: aviso de sesión caducada y Volver a Moodle
    else error del engine o de Git
        M-->>B: 502 {message}
    end
```

### 3.2 Decisión dentro del engine (`POST /edit/save`)

```mermaid
flowchart TD
    A["POST /edit/save"] --> B{"¿Existe la rama?"}
    B -- no --> X["404"]
    B -- sí --> C{"HEAD == original_sha?"}
    C -- sí --> D["Destino: la propia rama de edición"]
    C -- no --> E["Destino: rama nueva resolucion/...<br/>creada desde el HEAD actual"]
    D --> F["hash-object, mktree, commit-tree<br/>autor: el profesor, committer: el engine"]
    E --> F
    F --> G{"update-ref con el valor esperado"}
    G -- "la rama se movió entre medias" --> C
    G -- ok --> H["git push origin rama"]
    H --> I{"¿Era conflicto?"}
    I -- no --> J["200 {new_sha}"]
    I -- sí --> K["409 {resolution_branch, current_sha, diff_url}"]
```

### 3.3 Salidas del conflicto (409)

| Opción del diálogo | Qué hace el editor |
| --- | --- |
| Ver diferencias | Abre `diff_url` (comparación en la forja) en otra pestaña |
| Recargar la versión actual | Vuelve a pedir `content.php` con el **token vigente** (el de la URL original ya está revocado) y descarta los cambios locales |
| Forzar mi versión | Repite el guardado con `original_sha = current_sha`; el contenido queda encima de la versión del servidor |

El trabajo del profesor no se pierde en ningún caso: el 409 ya lo dejó
commiteado en la rama `resolucion/...`.

### 3.4 Renovación del token y borrador local

```mermaid
sequenceDiagram
    participant B as Editor
    participant I as IndexedDB
    participant M as Plugin (token.php)

    loop cada 5 minutos con el editor abierto
        B->>M: POST token.php {file, branch, token}
        M-->>B: 200 {token, expires}
        Note over M: revoca el anterior, conserva curso y modo
    end

    loop al editar (Markdown distinto del último guardado)
        B->>I: tras 3 s sin cambios, guarda {fichero@rama, sha, markdown}
    end
    Note over B,I: nunca se sube a Git automáticamente
```

## 4. Publicar

### 4.1 Publicación en un clic desde el editor

```mermaid
sequenceDiagram
    autonumber
    actor P as Profesor
    participant B as Editor
    participant M as Plugin (publish_api.php)
    participant E as Engine
    participant D as Docker (liaex)
    participant C as Curso de Moodle

    P->>B: elige formato (H5P, ePub o PDF) y Publicar
    Note over B: si hay cambios sin guardar,<br/>pide guardar primero
    B->>M: POST publish_api.php {file, sha, format, token}
    Note over M: token de escritura con curso de origen<br/>actúa como el usuario del token<br/>comprueba :deploy y manageactivities
    M->>E: POST /publish {file, sha, format}
    E->>E: worktree temporal del SHA (checkout aislado)
    E->>D: docker run liascript/exporter liaex -i fichero -f formato
    Note over D: red none por defecto<br/>bridge si el material importa plantillas
    D-->>E: artefacto (.h5p / .epub / .pdf)
    E-->>M: 200 bytes del artefacto
    Note over M: guarda el fichero en el área de borrador del usuario
    alt ya hay un módulo de ese formato para el fichero y sigue existiendo
        M->>C: update_moduleinfo (actualiza el módulo)
    else primera vez, otro formato o módulo borrado
        M->>C: add_moduleinfo (crea mod_h5pactivity o mod_resource)
    end
    Note over M: inserta en deploy {curso, fichero, sha, formato, cmid}
    M-->>B: 200 {cmid, url del módulo}
    B-->>P: Publicado en el curso + enlace
```

### 4.2 Publicación manual (página Publicar del plugin)

Vía de respaldo sin editor: el profesor sube un artefacto generado por su
cuenta (por ejemplo, con el LiaScript Exporter en el navegador).

```mermaid
sequenceDiagram
    actor P as Profesor
    participant M as Plugin (publish.php)
    participant C as Curso de Moodle

    P->>M: GET publish.php?id=curso&file=...
    Note over M: sesión Moodle y capacidad :deploy<br/>propone el SHA del último despliegue<br/>o el último guardado (editsess)
    P->>M: POST formulario (tipo, nombre, sección, SHA, fichero) + sesskey
    M->>C: add_moduleinfo o update_moduleinfo
    Note over M: inserta en deploy {curso, fichero, sha, tipo, cmid}
    M-->>P: redirige con el aviso de publicación
```

### 4.3 Estado de lo publicado

`materials.php` consulta, para cada despliegue registrado,
`GET /edit/state?file&sha` del engine. El engine compara el SHA publicado
con el HEAD de `main` y responde `up_to_date` o `update_available`, con el
número de commits de diferencia.

## 5. Dónde vive cada dato

| Dato | Dónde | Quién lo escribe |
| --- | --- | --- |
| Contenido del material | Git: `main`, `edicion/*`, `resolucion/*` | Engine (commit y push) |
| Token de edición (hash) | `local_liamateriales_token` | Plugin (`edit.php`, `materials.php`, `save.php`, `token.php`) |
| Sesión de edición y último SHA guardado | `local_liamateriales_editsess` | Plugin (`edit.php`, `save.php`) |
| Publicaciones (curso ↔ fichero ↔ SHA ↔ cmid) | `local_liamateriales_deploy` | Plugin (`publish_api.php`, `publish.php`) |
| Artefacto publicado | Ficheros del módulo en Moodle | Plugin (`add_moduleinfo` / `update_moduleinfo`) |
| Borrador sin guardar | IndexedDB del navegador | Editor |
| Configuración LMS de la sesión | Memoria del editor (bloque `liascript-lms`) | Plugin la inyecta en cada apertura; nunca se guarda en Git |

## 6. Limitaciones conocidas de estos flujos

- **Plugin → engine sin autenticación**: cualquiera con acceso al puerto
  del engine puede llamar a `/edit/save` o `/publish`. Hay que añadir un
  secreto compartido y no publicar el puerto del engine.
- **El engine no hace `fetch`**: trabaja con las ramas locales del clon.
  Los cambios hechos directamente en GitHub no se ven hasta actualizar el
  clon, y un `push` rechazado deja el commit local hecho.
- **Publicación síncrona**: la petición espera a liaex (unos segundos;
  más en PDF). Con varios profesores a la vez convendría una cola.
- **Tokens en la URL de apertura**: el primer token viaja en la URL de
  `content.php` (registros de acceso e historial). Se revoca en el primer
  guardado o renovación.
