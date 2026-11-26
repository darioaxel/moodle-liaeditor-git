# Materiales Editables (local_liamateriales)

Plugin local de Moodle para la **edición de materiales LiaScript Markdown
alojados en Git directamente desde Moodle** (arquitectura
LiaEditor–Moodle v1, decisiones D1–D10 de `docs/desarrollo-liaeditor-moodle-v1.md`).

El material es un fichero `.md` LiaScript versionado en Git — **nunca** una
instancia `mod_book`. Se edita con el LiveEditor (LiaEdit) self-hosted y se
publica en el curso como **H5P, ePub o PDF**.

> **v2.1.0 (2026-11-19)**: el componente pasa de `local_courseascode` a
> **`local_liamateriales`** (sin migración de datos: hay que desinstalar el
> plugin antiguo e instalar este de cero) y se elimina por completo el flujo
> legado "Course as Code" (`mod_book`).

Pensado para **Moodle 5.3+** (`$plugin->requires = 2026100600`); verificado
en Moodle 6.0dev.

## Qué hace

- **Página "Materiales"** (`/local/liamateriales/materials.php`, entrada en la
  navegación del curso): catálogo master de materiales `.md` del repo
  (`GET /edit/files`), sesiones de edición del profesor y módulos publicados
  con su estado (`GET /edit/state`: hay actualización si cambió la carpeta
  del material en su rama). Si cambia `main`, los contenidos publicados
  desde `main` que quedan obsoletos muestran **«Actualizar todos los
  contenidos dependientes de esta versión»** (`update_dependents.php` +
  `js/update_dependents.js`): se regeneran desde `main` y sustituyen a los
  publicados en el mismo módulo (nombre, sección y visibilidad intactos).
- **Edición** (`edit.php` → LiaEdit self-hosted): asegura la rama
  `edicion/<user>/<file>` vía `POST /edit/open` del engine (D4), emite un
  **token de un solo uso** (D9, tabla `local_liamateriales_token`, gestionado
  por `local\token_manager`) y redirige al editor.
- **`content.php`** (proxy de contenido, patrón B): sirve el markdown al
  editor con el bloque `liascript-lms` inyectado (endpoints de guardado,
  SHA, token, readonly). Autenticación por token, CORS restringido al
  dominio del editor (settings).
- **`save.php`** (fachada de guardado, D3): valida el token, delega en
  `POST /edit/save` del engine, registra la sesión (`local_liamateriales_editsess`)
  con el nuevo SHA y devuelve token renovado (contrato C6). El 409 de
  conflicto llega al editor con su rama `resolucion/...` (D5).
- **`token.php`**: renovación del token sin guardar (ping del editor, C6).
- **Publicar** (`publish.php` + `local\publisher`, D6): subida del artefacto
  exportado en el navegador (**H5P / ePub / PDF**), creación/actualización
  del módulo (`mod_h5pactivity` / `mod_resource`) y registro del despliegue
  curso↔SHA↔cmid (`local_liamateriales_deploy`).
- **Capacidades**: `local/liamateriales:viewcatalog` (ver catálogo),
  `local/liamateriales:edit` (editingteacher), `local/liamateriales:editmain`
  (manager), `local/liamateriales:deploy` (publicar).
- **Settings** (`settings.php`): URL del editor, origen CORS, TTL del token
  y tamaño máximo de material.

## Instalación

1. Copiar (o montar) la carpeta `plugin/` del repositorio como
   `local/liamateriales` y correr el upgrade de Moodle
   (`php admin/cli/upgrade.php --non-interactive`).
2. Configurar en `config.php` (o equivalente):
   - `$CFG->local_liamateriales_service_url` — URL interna del engine
     (server-to-server), p. ej. `http://engine:8000`. El navegador
     nunca habla con el engine: no necesita URL pública.
   - `$CFG->local_liamateriales_service_secret` — secreto compartido con
     el engine (el mismo valor que su `MATERIALES_LIASCRIPT_SHARED_SECRET`;
     `openssl rand -hex 32`). Firma cada petición; sin él, el engine
     responde 401. También se puede poner en el ajuste «Secreto compartido
     con el engine», pero `config.php` lo deja fuera de la base de datos.
3. En *Administración del sitio → Plugins → Materiales Editables*: URL del
   editor (obligatoria), origen CORS (opcional, se deriva del editor), TTL
   del token, tamaño máximo de material, límites de uso por usuario y la
   apertura antigua (`legacy_open_url`, solo para el fork de LiveEditor).
4. Si el engine corre en red interna/IP privada, revisar la seguridad cURL
   de Moodle (`curlsecurityallowedport`, `curlsecurityblockedhosts`) para
   permitir el host/puerto del engine.

## Versión y seguimiento

Las páginas del plugin (Materiales Editables y Publicar) muestran abajo a la
derecha, en letra pequeña, la versión y la fecha del último cambio:
«Materiales Editables 2.7.1 (2026112502) · último cambio: 25 nov 2026». La
fecha sale del número de versión (`AAAAMMDDNN`), así que **cada cambio del
plugin sube `$plugin->version`** en `version.php` (y `release` si procede).
Si el código es más nuevo que lo instalado, el pie añade «actualización
pendiente: instalada …» (falta `admin/cli/upgrade.php`).

## Seguridad (fase S)

Superficie expuesta: cuatro scripts sin sesión de Moodle, que usa el
editor (`content.php`, `save.php`, `token.php`, `asset.php`). Publicar se
hace solo en la página Publicar, con sesión: `publish_api.php` (publicar
desde el editor) se retiró en 2.8.0.

- **Tokens fuera de las URL.** El editor se abre con un código de un solo
  uso y 60 s en el fragmento (`#open=…`), que canjea con
  `POST content.php`; el token de edición solo viaja en el cuerpo de las
  peticiones.
- **Cabeceras.** `Referrer-Policy: no-referrer`, `nosniff`, `no-store` y
  CORS limitado al origen del editor.
- **Límites.** Por usuario (ajustes; 429 con `Retry-After`), un bloqueo por
  IP tras 20 tokens inválidos en 10 min, y una publicación a la vez por
  usuario y material (409).
- **Errores.** El navegador recibe un mensaje genérico con un identificador
  de incidencia; el detalle queda en el log de PHP del servidor.
- **Auditoría.** Eventos «Material abierto / guardado / conflicto /
  publicado» en el registro de Moodle (informes del curso).
- **Limpieza.** Tarea programada diaria (tokens caducados, sesiones
  inactivas 7 días).
- **Engine.** Solo en la red interna y con firma HMAC (ver
  `engine/README.md`, «Superficie expuesta»).

### Imágenes (docs/imagenes)

- `asset.php`: `POST {token, path}` → bytes de una imagen de la carpeta del
  material del token (también con token de solo lectura). Tipos de una
  lista (PNG, JPEG, GIF, WebP), `nosniff`, CSP `default-src 'none'` y
  `sandbox`; límite «asset» (240/min).
- `save.php` acepta `images: [{path, content_base64}]`: PNG, JPEG o GIF,
  20 como máximo, 2 MB cada una y 10 MB en total; se valida la ruta, el
  base64 y el tipo real (`finfo`, `getimagesize`) antes de enviarlas al
  engine, que repite la validación. SVG nunca.
- PHP: `post_max_size` de al menos 16M para guardar con imágenes.

### Límite en el proxy (LIA-071)

Además de los límites por usuario del plugin, el proxy delante de Moodle
debe limitar el volumen por IP de los cuatro scripts (`asset.php` con un
margen mayor: pide una imagen por petición). Nginx:

```nginx
# En http { … }
limit_req_zone $binary_remote_addr zone=liamat:10m rate=60r/m;

# En el server { … } de Moodle
location ~ ^/local/liamateriales/(content|save|token)\.php$ {
    limit_req zone=liamat burst=20 nodelay;
    limit_req_status 429;
    # … lo mismo que el resto de PHP de Moodle (fastcgi_pass / proxy_pass)
}

# asset.php: una petición por imagen al abrir un material; zona propia más amplia.
# (en http { … }: limit_req_zone $binary_remote_addr zone=liamat_img:10m rate=600r/m;)
location ~ ^/local/liamateriales/asset\.php$ {
    limit_req zone=liamat_img burst=100 nodelay;
    limit_req_status 429;
    # … igual que arriba
}
```

Apache (con `mod_evasive` o `mod_security`), el equivalente sobre esas
cuatro rutas. Comprobación: más de 80 peticiones seguidas desde una IP a
`token.php` reciben 429 del proxy.

## Arquitectura

El plugin es deliberadamente una fachada (D2): toda la lógica Git (ramas
`edicion/*`, conflictos optimistic-locking por SHA, commits, estado) vive
en el engine Python (`materiales_liascript-git`, carpeta `engine/`).
El plugin gestiona tokens, sesiones, trazabilidad curso↔SHA↔módulo y la UI.

Contrato con el editor (fork `FPVirtual/LiveEditor`): bloque
`liascript-lms` inyectado en la cabecera del markdown — ver
`docs/roadmap-liveeditor-fork.md` §5.
