<?php
// «Actualizar todos los contenidos dependientes de esta versión» (LIA-129).

/**
 * update_dependents.php — regenera desde la versión oficial (`main`) los
 * contenidos publicados en el curso desde `main` que un cambio posterior ha
 * dejado obsoletos, y sustituye cada uno en su mismo módulo (mismo formato,
 * nombre, sección y visibilidad).
 *
 * Solo POST con sesskey, `:deploy` y `moodle/course:manageactivities`.
 * - `deploy=<id>&ajax=1`: actualiza ese contenido y responde JSON
 *   (js/update_dependents.js los recorre uno a uno y muestra el progreso).
 * - Sin `deploy` (sin JavaScript): actualiza todos y vuelve a la lista.
 *
 * Qué depende de `main` lo decide el servidor (publisher::main_dependents),
 * nunca la petición: un id que no esté en esa lista no se toca.
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_liamateriales\local\publisher;

$courseid = required_param('id', PARAM_INT);
$context = context_course::instance($courseid);
require_login($courseid);
require_capability('local/liamateriales:deploy', $context);
require_capability('moodle/course:manageactivities', $context);
if (!data_submitted()) {
    throw new \moodle_exception('invalidrequest');
}
require_sesskey();

$deployid = optional_param('deploy', 0, PARAM_INT);
$ajax = optional_param('ajax', 0, PARAM_BOOL);
$returnurl = new moodle_url('/local/liamateriales/materials.php', ['id' => $courseid]);

/**
 * Respuesta JSON y fin (peticiones en segundo plano).
 *
 * @param int $status código HTTP
 * @param array $body cuerpo
 */
function local_liamateriales_dependents_json(int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body);
    die;
}

// Generar lleva de segundos a minutos por contenido (liaex, Pandoc).
\core_php_time_limit::raise($deployid ? 330 : 0);

$dependents = publisher::main_dependents($courseid, (int) $USER->id);

if ($deployid) {
    if (!isset($dependents[$deployid])) {
        // Ya actualizado (p. ej. otra pestaña) o no depende de main: nada que hacer.
        local_liamateriales_dependents_json(200, [
            'status' => 'skipped', 'message' => get_string('dependents_skipped', 'local_liamateriales'),
        ]);
    }
    try {
        $cmid = publisher::update_from_main($dependents[$deployid], $USER);
    } catch (\moodle_exception $e) {
        local_liamateriales_dependents_json(502, ['status' => 'error', 'message' => $e->getMessage()]);
    }
    $cm = get_coursemodule_from_id('', $cmid, $courseid, false, MUST_EXIST);
    local_liamateriales_dependents_json(200, [
        'status' => 'updated',
        'name' => format_string($cm->name),
        'url' => (new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cmid]))->out(false),
    ]);
}

// Sin JavaScript: todos en esta petición.
$ok = 0;
$failed = [];
foreach ($dependents as $deploy) {
    try {
        publisher::update_from_main($deploy, $USER);
        $ok++;
    } catch (\moodle_exception $e) {
        $failed[] = s($deploy->source_file) . ' (' . s(publisher::label($deploy->artifact_type)) . '): ' . s($e->getMessage());
    }
}
if ($failed) {
    \core\notification::error(get_string('dependents_failed', 'local_liamateriales')
        . html_writer::alist($failed));
}
redirect(
    $returnurl,
    get_string('dependents_done', 'local_liamateriales', $ok),
    null,
    $failed ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS
);
