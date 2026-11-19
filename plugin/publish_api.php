<?php
// Publicación en un clic desde el editor LiaEdit (LIA-041, D6/D7 fase 2).

/**
 * publish_api.php — endpoint del contrato editor↔plugin para Publicar.
 *
 * El botón Publicar del fork envía ``{file, sha, format, token}`` (roadmap
 * §5). El plugin valida el token (que conoce el curso de origen, LIA-010),
 * pide el artefacto al engine (``POST /publish``, liaex server-side, D7),
 * crea/actualiza el módulo del curso (mod_h5pactivity / mod_resource) y
 * registra el despliegue curso↔SHA↔cmid (D6).
 *
 * Respuestas: 200 ``{status, cmid, url}`` · 403 token inválido · 400
 * petición incompleta · 502 error del engine.
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- autenticación por token (D9).
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_liamateriales\local\publisher;
use local_liamateriales\local\token_manager;

$origin = local_liamateriales_cors_origin();

/**
 * Respuesta JSON con CORS y cierre.
 */
function publish_respond(int $status, array $body): void {
    global $origin;
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
    }
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '');
if (!is_object($body) || empty($body->file) || empty($body->sha) || empty($body->format) || empty($body->token)) {
    publish_respond(400, ['status' => 'error', 'message' => 'petición incompleta']);
}
if (!in_array($body->format, ['h5p', 'epub', 'pdf'], true)) {
    publish_respond(400, ['status' => 'error', 'message' => 'formato no soportado: ' . $body->format]);
}

try {
    local_liamateriales_validate_file($body->file);
    $tokendata = token_manager::validate($body->token, $body->file, null, true);
} catch (\moodle_exception $e) {
    publish_respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
}
if ((int) $tokendata->courseid <= 0) {
    publish_respond(403, ['status' => 'error', 'message' => 'este token no permite publicar (sin curso de origen)']);
}
$user = \core_user::get_user($tokendata->userid, '*', MUST_EXIST);
$coursecontext = \context_course::instance((int) $tokendata->courseid);

// La petición llega sin sesión de Moodle (autenticación por token, D9): se
// actúa como el usuario del token. Sin esto, add_moduleinfo no encuentra el
// área de borrador (file_save_draft_area_files usa $USER) ni registra bien
// el autor de los eventos.
\core\session\manager::set_user($user);

// Las capacidades se comprueban al publicar, no solo al emitir el token.
if (!has_capability('local/liamateriales:deploy', $coursecontext, $user)
        || !has_capability('moodle/course:manageactivities', $coursecontext, $user)) {
    publish_respond(403, ['status' => 'error', 'message' => get_string('publishnocap', 'local_liamateriales')]);
}

// El artefacto es binario: petición directa, sin el wrapper JSON del cliente.
$curl = new \curl();
$curl->setHeader('Content-Type: application/json');
$curl->setHeader('Accept: application/octet-stream');
$response = $curl->post(
    local_liamateriales_service_url() . '/publish',
    json_encode(['file' => $body->file, 'sha' => $body->sha, 'format' => $body->format])
);
$status = (int) ($curl->get_info()['http_code'] ?? 0);
if ($curl->get_errno() || $status !== 200 || !is_string($response) || $response === '') {
    $detail = 'error del engine al generar el artefacto';
    $decoded = json_decode(is_string($response) ? $response : '');
    if (is_object($decoded) && !empty($decoded->detail)) {
        $detail = $decoded->detail;
    }
    publish_respond(502, ['status' => 'error', 'message' => $detail]);
}

// El binario pasa por un fichero temporal al área de borrador (contrato
// filemanager de add_moduleinfo/update_moduleinfo).
$tempfile = tempnam(sys_get_temp_dir(), 'lia');
file_put_contents($tempfile, $response);
$draftitemid = file_get_unused_draft_itemid();
$fs = get_file_storage();
$fs->create_file_from_pathname([
    'contextid' => \context_user::instance($user->id)->id,
    'component' => 'user',
    'filearea' => 'draft',
    'itemid' => $draftitemid,
    'filepath' => '/',
    'filename' => clean_filename(
        basename($body->file, '.md') . '@' . substr($body->sha, 0, 8) . '.' . $body->format
    ),
], $tempfile);
@unlink($tempfile);

// Solo se actualiza el módulo del mismo formato que siga existiendo; si no,
// se crea uno nuevo (antes se reutilizaba el último cmid fuera cual fuera su
// tipo, y fallaba si el profesor lo había borrado).
$existingcmid = publisher::reusable_cmid((int) $tokendata->courseid, $body->file, $body->format);
$cms = get_fast_modinfo((int) $tokendata->courseid)->get_cms();
$defaultname = $existingcmid && isset($cms[$existingcmid])
    ? $cms[$existingcmid]->name
    : basename($body->file, '.md') . ' (' . strtoupper($body->format) . ')';

try {
    $cmid = publisher::publish(
        (int) $tokendata->courseid,
        0,
        $body->file,
        $body->format,
        $body->sha,
        $draftitemid,
        $defaultname,
        $existingcmid,
        $user->username
    );
} catch (\Throwable $e) {
    publish_respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
}

publish_respond(200, [
    'status' => 'published',
    'cmid' => $cmid,
    // Enlace directo al módulo publicado (o al curso si no se puede resolver).
    'url' => ($cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING))
        ? (new \moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cmid]))->out(false)
        : (new \moodle_url('/course/view.php', ['id' => $tokendata->courseid]))->out(false),
]);
