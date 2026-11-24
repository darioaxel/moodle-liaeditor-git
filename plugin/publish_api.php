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
 * Respuestas: 200 ``{status, cmid, url, avisos}`` (avisos del lector
 * LiaScript en web y PDF de contenido) · 403 token inválido · 400
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

local_liamateriales_api_preflight('POST');

$body = json_decode(file_get_contents('php://input') ?: '');
if (!is_object($body) || empty($body->file) || empty($body->sha) || empty($body->format) || empty($body->token)) {
    local_liamateriales_api_respond(400, ['status' => 'error', 'message' => 'petición incompleta']);
}
if (!in_array($body->format, publisher::types(), true)) {
    local_liamateriales_api_respond(400, ['status' => 'error', 'message' => 'formato no soportado: ' . $body->format]);
}

try {
    local_liamateriales_validate_file($body->file);
    $tokendata = token_manager::validate($body->token, $body->file, null, true);
} catch (\moodle_exception $e) {
    local_liamateriales_api_respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
}
if ((int) $tokendata->courseid <= 0) {
    local_liamateriales_api_respond(403, ['status' => 'error', 'message' => 'este token no permite publicar (sin curso de origen)']);
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
    local_liamateriales_api_respond(403, ['status' => 'error', 'message' => get_string('publishnocap', 'local_liamateriales')]);
}

// Genera el artefacto en el engine y crea o actualiza el módulo (mismo
// camino que la página Publicar del plugin).
try {
    $cmid = publisher::publish_generated(
        (int) $tokendata->courseid,
        0,
        $body->file,
        $body->format,
        $body->sha,
        null,
        $user
    );
} catch (\moodle_exception $e) {
    local_liamateriales_api_respond(502, ['status' => 'error', 'message' => $e->getMessage()]);
} catch (\Throwable $e) {
    local_liamateriales_api_respond(500, ['status' => 'error', 'message' => $e->getMessage()]);
}

local_liamateriales_api_respond(200, [
    'status' => 'published',
    'cmid' => $cmid,
    // Enlace directo al módulo publicado (o al curso si no se puede resolver).
    'url' => ($cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING))
        ? (new \moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cmid]))->out(false)
        : (new \moodle_url('/course/view.php', ['id' => $tokendata->courseid]))->out(false),
    'avisos' => publisher::last_avisos(),
]);
