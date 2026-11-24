<?php
// Vista previa de un material en el editor, en solo lectura (LIA-067).

/**
 * preview.php — botón «Vista previa» del catálogo de materiales.
 *
 * Emite en este momento (no al cargar el catálogo) un código de apertura de
 * solo lectura y redirige al editor (LIA-062). Requiere sesión de Moodle y
 * la capacidad ``viewcatalog`` en el curso. Siempre la versión oficial
 * (``main``).
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$courseid = required_param('id', PARAM_INT);
$file = required_param('file', PARAM_PATH);

$context = context_course::instance($courseid);
require_login($courseid);
require_capability('local/liamateriales:viewcatalog', $context);
local_liamateriales_validate_file($file);

if (local_liamateriales_editor_url() === '') {
    throw new \moodle_exception('editornotconfigured', 'local_liamateriales');
}

redirect(local_liamateriales_open_in_editor((int) $USER->id, $file, 'main', 0, true));
