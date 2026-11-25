<?php
// Cadenas del plugin en español (paquete idioma base *en* por convención Moodle).

/**
 * Language strings.
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Materiales Editables';
$string['liamateriales:viewcatalog'] = 'Ver el catálogo de materiales editables';
$string['liamateriales:deploy'] = 'Publicar y actualizar materiales editables';
$string['liamateriales:edit'] = 'Editar materiales LiaScript en el LiaEditor';
$string['liamateriales:editmain'] = 'Editar materiales directamente en la rama principal';
$string['privacy:metadata:reason'] = 'El plugin solo almacena metadatos de edición y publicación de materiales.';
$string['servicenotconfigured'] = 'El engine no está configurado. Establece $CFG->local_liamateriales_service_url (ver README).';
$string['servicerror'] = 'El engine devolvió un error: {$a}';
$string['versionfooter'] = 'Materiales Editables {$a->release} ({$a->version}) · último cambio: {$a->date}';
$string['versionpending'] = 'actualización pendiente: instalada {$a}';

// Material states (by SHA comparison, §5.3).
$string['state_up_to_date'] = 'Actualizado';
$string['state_update_available'] = 'Actualización disponible';
$string['state_error'] = 'Error';

// LiaEditor: settings (LIA-012).
$string['settings_service_secret'] = 'Secreto compartido con el engine';
$string['settings_service_secret_desc'] = 'Firma de cada petición al engine (HMAC-SHA256). Debe coincidir con MATERIALES_LIASCRIPT_SHARED_SECRET del engine; sin él, el engine rechaza las peticiones (401). Genera uno largo y aleatorio (p. ej. openssl rand -hex 32). Si se define $CFG->local_liamateriales_service_secret en config.php, tiene prioridad sobre este ajuste.';
$string['settings_liaeditor_url'] = 'URL del LiaEditor';
$string['settings_liaeditor_url_desc'] = 'URL base de la instancia autoalojada de LiaEdit (p. ej. https://liascript-editor.example.com).';
$string['settings_legacy_open_url'] = 'Apertura antigua (token en la URL)';
$string['settings_legacy_open_url_desc'] = 'Solo para el fork de LiveEditor. Pone el token de edición en la URL del editor, donde queda en registros, historial y Referer. Desactivado, el plugin abre el editor con un código de un solo uso en el fragmento de la URL (editor Crepe).';
$string['settings_cors_origin'] = 'Origen CORS para el editor';
$string['settings_cors_origin_desc'] = 'Origen permitido en las respuestas de content.php. Si se deja vacío, se usará el origen de la URL del LiaEditor.';
$string['settings_token_ttl'] = 'Tiempo de vida del token de edición (segundos)';
$string['settings_token_ttl_desc'] = 'Tiempo de vida de un token de un solo uso para sesiones de edición. El editor lo renueva mientras trabaja; por defecto 900 (15 minutos).';
$string['settings_max_file_size'] = 'Tamaño máximo del material (bytes)';
$string['settings_max_file_size_desc'] = 'Archivos markdown mayores que este valor serán rechazados por save.php; por defecto 1048576 (1 MB).';

// LiaEditor: tokens and save flow (LIA-011/013/014/015).
$string['tokeninvalid'] = 'El token de edición no es válido o ha sido revocado. Vuelve a abrir el material desde Moodle.';
$string['tokenexpired'] = 'El token de edición ha caducado. Vuelve a abrir el material desde Moodle.';
$string['tokenreadonly'] = 'Este enlace es de vista previa (solo lectura): no permite guardar ni publicar.';
$string['tokenmismatch'] = 'El token de edición no corresponde a este fichero y rama.';
$string['editornotconfigured'] = 'El LiaEditor no está configurado. Establece la URL del LiaEditor en la configuración del plugin.';
$string['invalidfile'] = 'Ruta de material no válida: {$a}';
$string['savedok'] = 'Cambios guardados en tu rama de edición.';
$string['saveconflict'] = 'El material ha cambiado en el servidor mientras editabas. Tu trabajo está a salvo en una rama de resolución.';

// LiaEditor: materials page (LIA-016/018).
$string['materials'] = 'Edición Materiales v2';
$string['materials_catalog'] = 'Contenidos Master';
$string['materials_empty'] = 'No se han encontrado materiales LiaScript en el repositorio.';
$string['materials_minedit'] = 'Mis Contenidos';
$string['materials_minedit_empty'] = 'No tienes sesiones de edición activas.';
$string['materials_published'] = 'Publicados en este curso';
$string['materials_published_empty'] = 'Todavía no hay materiales publicados en este curso.';
$string['material_title'] = 'Título';
$string['material_file'] = 'Material';
$string['material_branch'] = 'Rama de edición';
$string['material_last_save'] = 'Último guardado';
$string['material_last_sha'] = 'SHA';
$string['material_module'] = 'Módulo';
$string['material_published_sha'] = 'SHA publicado';
$string['material_state'] = 'Estado';
$string['actions'] = 'Acciones';
$string['material_preview'] = 'Vista previa';
$string['material_edit'] = 'Editar';
$string['material_continue'] = 'Continuar editando';
$string['material_publish'] = 'Publicar';
$string['material_update'] = 'Actualizar';

// LiaEditor: publish page (LIA-017).
$string['publish_name'] = 'Nombre del módulo';
$string['publish_type'] = 'Tipo de artefacto';
$string['publish_section'] = 'Sección del curso';
$string['publish_file'] = 'Fichero (.h5p / .epub / .pdf)';
$string['publishupdate'] = 'Este material ya está publicado en el curso. Si lo publicas en el mismo formato se actualiza ese módulo; en otro formato se crea uno nuevo.';
$string['publishok'] = '«{$a}» publicado en el curso y vinculado a la versión del material.';
$string['publishnosha'] = 'No hay ninguna versión publicable de este material: guárdalo desde el editor o comprueba que existe en la versión oficial.';
$string['publishnofile'] = 'No se ha subido ningún artefacto o la subida ha fallado.';
$string['invalidartifacttype'] = 'Tipo de artefacto no válido: {$a}';
$string['publishnocap'] = 'No tienes permiso para publicar materiales en este curso.';
$string['invalidbranch'] = 'No puedes editar en esa rama.';
$string['publish_version'] = 'Versión a publicar';
$string['publish_version_branch'] = 'Última versión de tu rama de edición ({$a->branch}, {$a->date})';
$string['publish_version_main'] = 'Versión oficial (main, {$a->date})';
$string['publish_type_h5p'] = 'H5P (actividad interactiva)';
$string['publish_type_epub'] = 'ePub (libro electrónico descargable)';
$string['publish_type_pdf'] = 'PDF (documento descargable)';
$string['publish_type_scorm'] = 'SCORM 1.2 (paquete con seguimiento)';
$string['publish_type_web'] = 'Web (páginas HTML navegables, con autoevaluación)';
$string['publish_type_pdfcontenido'] = 'PDF de contenido (solo el contenido, sin preguntas)';
$string['publishwebinvalid'] = 'El paquete web no es válido: debe ser un .zip con index.html en la raíz.';
$string['publish_avisos'] = 'Publicado, pero hay partes del material que este formato no representa:';
$string['settings_web_heading'] = 'Publicación como web';
$string['settings_web_heading_desc'] = 'La web estática se publica como recurso «Archivo» con todas sus páginas (index.html como fichero principal).';
$string['settings_web_display'] = 'Mostrar la web';
$string['settings_web_display_desc'] = 'Cómo se abre la web en el curso. Por defecto, ventana emergente (como los sitios de eXeLearning).';
$string['settings_web_popupwidth'] = 'Ancho de la ventana emergente (píxeles)';
$string['settings_web_popupheight'] = 'Alto de la ventana emergente (píxeles)';
$string['publish_name_default'] = 'Por defecto, el nombre del módulo actual o el del material';
$string['publish_generate'] = 'Generar y publicar';
$string['publish_generate_hint'] = 'El servidor genera el fichero a partir del material (puede tardar hasta un minuto en PDF). «Generar y publicar» lo añade al curso; «Descargar sin publicar» te lo entrega para usarlo en otra plataforma.';
$string['publish_upload_alt'] = '¿Ya tienes el fichero generado? Súbelo tú';
$string['publish_upload_button'] = 'Subir y publicar';
$string['publish_download'] = 'Descargar sin publicar';
$string['publishgenerateerror'] = 'No se pudo generar el fichero: {$a}';
$string['progress_generate'] = 'Generando y publicando {fmt}…';
$string['progress_download'] = 'Generando {fmt} para descargar…';
$string['progress_upload'] = 'Subiendo y publicando {fmt}…';
$string['progress_elapsed'] = 'Tiempo transcurrido: {time}';
$string['progress_estimate'] = 'Suele tardar unos {s} segundos.';
$string['progress_slow'] = 'Está tardando más de lo habitual. El servidor sigue trabajando.';
$string['progress_keepopen'] = 'No cierres ni recargues esta página.';
$string['progress_published'] = 'Publicado. Abriendo la actividad…';
$string['progress_downloaded'] = 'Fichero generado. Se está descargando.';
$string['progress_error'] = 'No se pudo completar: {message}';
$string['progress_avisos'] = 'Fichero generado. Partes del material que este formato no representa:';
$string['progress_nofile'] = 'Selecciona primero el fichero que quieres subir.';

// Seguridad (fase S).
$string['cachedef_ratelimit'] = 'Contadores de los límites de uso y bloqueos por IP';
$string['ratelimited'] = 'Demasiadas peticiones. Espera {$a} segundos y vuelve a intentarlo.';
$string['publishbusy'] = 'Ya se está publicando este material. Espera a que termine.';
$string['settings_ratelimit_heading'] = 'Límites de uso';
$string['settings_ratelimit_heading_desc'] = 'Peticiones máximas por usuario desde el editor (0 = sin límite). Al superarlas, el editor recibe «Demasiadas peticiones» y no pierde el trabajo. Además, una IP con 20 tokens inválidos en 10 minutos queda bloqueada 15 minutos.';
$string['settings_ratelimit_window'] = 'Por usuario, en {$a} min.';
$string['settings_ratelimit_content'] = 'Aperturas y recargas del material';
$string['settings_ratelimit_save'] = 'Guardados';
$string['settings_ratelimit_token'] = 'Renovaciones del token';
$string['taskcleanup'] = 'Limpieza de tokens caducados y sesiones de edición inactivas';
$string['event_opened'] = 'Material abierto en el editor';
$string['event_saved'] = 'Material guardado';
$string['event_conflict'] = 'Conflicto al guardar un material';
$string['event_published'] = 'Material publicado en el curso';
$string['internalerror'] = 'No se pudo completar la operación por un error del servidor. Si se repite, avisa al administrador con la incidencia {$a}.';
$string['settings_ratelimit_asset'] = 'Imágenes que el editor pide para mostrarlas';
$string['assetnotfound'] = 'Imagen no encontrada.';
$string['imageinvalid'] = 'La imagen «{$a}» no es válida: se admiten PNG, JPEG y GIF de hasta 2 MB, en una ruta sin espacios.';
$string['imagestoomany'] = 'Demasiadas imágenes nuevas en un guardado (máximo {$a}). Guarda en varias veces.';
$string['imagestoobig'] = 'Las imágenes nuevas superan {$a} MB en un guardado. Guarda en varias veces.';
