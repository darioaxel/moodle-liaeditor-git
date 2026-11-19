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
  con su estado por comparación de SHAs (`GET /edit/state`).
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
     (server-to-server), p. ej. `http://courseascode:8000`. El navegador
     nunca habla con el engine: no necesita URL pública.
3. En *Administración del sitio → Plugins → Materiales Editables*: URL del
   editor LiaEdit self-hosted (obligatoria), origen CORS (opcional, se
   deriva del editor), TTL del token y tamaño máximo de material.
4. Si el engine corre en red interna/IP privada, revisar la seguridad cURL
   de Moodle (`curlsecurityallowedport`, `curlsecurityblockedhosts`) para
   permitir el host/puerto del engine.

## Arquitectura

El plugin es deliberadamente una fachada (D2): toda la lógica Git (ramas
`edicion/*`, conflictos optimistic-locking por SHA, commits, estado) vive
en el engine Python (`courseascode-engine`, contenedor `courseascode`).
El plugin gestiona tokens, sesiones, trazabilidad curso↔SHA↔módulo y la UI.

Contrato con el editor (fork `FPVirtual/LiveEditor`): bloque
`liascript-lms` inyectado en la cabecera del markdown — ver
`docs/roadmap-liveeditor-fork.md` §5.
