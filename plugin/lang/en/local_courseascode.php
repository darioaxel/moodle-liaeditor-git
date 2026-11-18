<?php
// Cadenas del plugin en español (paquete idioma base *en* por convención Moodle).

/**
 * Language strings.
 *
 * @package    local_courseascode
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Materiales Editables';
$string['courseascode:viewcatalog'] = 'Ver el catálogo de materiales editables';
$string['courseascode:deploy'] = 'Publicar y actualizar materiales editables';
$string['courseascode:manage'] = 'Gestionar Materiales Editables (a nivel de sistema)';
$string['courseascode:edit'] = 'Editar materiales LiaScript en el LiaEditor';
$string['courseascode:editmain'] = 'Editar materiales directamente en la rama principal';
$string['book_not_found'] = 'El libro no existe';
$string['chapter_not_found'] = 'El capítulo no existe en este libro';
$string['invalid_course'] = 'El curso no existe';
$string['invalid_deployed_at'] = 'deployed_at no es una fecha ISO 8601 válida';
$string['privacy:metadata:reason'] = 'El plugin solo almacena metadatos de publicación; los datos personales se gestionan en el registro de auditoría del servicio courseascode.';
$string['servicenotconfigured'] = 'El servicio courseascode no está configurado. Establece $CFG->local_courseascode_service_url (ver README).';
$string['servicerror'] = 'El servicio courseascode devolvió un error: {$a}';

// Material states (by SHA comparison, §5.3).
$string['state_up_to_date'] = 'Actualizado';
$string['state_update_available'] = 'Actualización disponible';
$string['state_error'] = 'Error';

// LiaEditor: settings (LIA-012).
$string['settings_liaeditor_url'] = 'URL del LiaEditor';
$string['settings_liaeditor_url_desc'] = 'URL base de la instancia autoalojada de LiaEdit (p. ej. https://liascript-editor.example.com).';
$string['settings_cors_origin'] = 'Origen CORS para el editor';
$string['settings_cors_origin_desc'] = 'Origen permitido en las respuestas de content.php. Si se deja vacío, se usará el origen de la URL del LiaEditor.';
$string['settings_token_ttl'] = 'Tiempo de vida del token de edición (segundos)';
$string['settings_token_ttl_desc'] = 'Tiempo de vida de un token de un solo uso para sesiones de edición. El editor lo renueva mientras trabaja; por defecto 900 (15 minutos).';
$string['settings_max_file_size'] = 'Tamaño máximo del material (bytes)';
$string['settings_max_file_size_desc'] = 'Archivos markdown mayores que este valor serán rechazados por save.php; por defecto 1048576 (1 MB).';

// LiaEditor: tokens and save flow (LIA-011/013/014/015).
$string['tokeninvalid'] = 'El token de edición no es válido o ha sido revocado. Vuelve a abrir el material desde Moodle.';
$string['tokenexpired'] = 'El token de edición ha caducado. Vuelve a abrir el material desde Moodle.';
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
$string['publish_file'] = 'Fichero del artefacto (.h5p / .epub / .pdf)';
$string['publishupdate'] = 'Este material ya está publicado en el curso: al enviar se actualizará el módulo existente.';
$string['publishok'] = '«{$a}» publicado en el curso y vinculado a la versión del material.';
$string['publishnosha'] = 'No se conoce ninguna versión de este material: guárdalo primero desde el editor.';
$string['publishnofile'] = 'No se ha subido ningún artefacto o la subida ha fallado.';
$string['invalidartifacttype'] = 'Tipo de artefacto no válido: {$a}';
