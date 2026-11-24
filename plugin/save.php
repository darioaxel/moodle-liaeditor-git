<?php
// Fachada de guardado del editor LiaEdit (LIA-014, D3/D5).

/**
 * save.php — guarda el markdown del editor en Git vía el engine.
 *
 * Autenticación por token de un solo uso (D9, como content.php). El plugin
 * queda en medio del guardado (D3): valida el token, delega en el engine
 * (``POST /edit/save``), registra la sesión de edición con el SHA resultante
 * (trazabilidad antes/después del commit) y devuelve un token renovado en
 * cada 200 (contrato C6 del roadmap del fork).
 *
 * Respuestas: 200 ``{status: ok, new_sha, token, committed_at?}`` · 409 ``{status:
 * conflict, resolution_branch, current_sha, diff_url}`` · 403 token
 * inválido · 502 error del engine.
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- autenticación por token (D9).
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_liamateriales\local\token_manager;

local_liamateriales_api_preflight('POST');
local_liamateriales_api_guard_ip();

$body = json_decode(file_get_contents('php://input') ?: '');
if (!is_object($body) || empty($body->file) || empty($body->branch) || empty($body->original_sha)
        || !isset($body->content) || empty($body->token)) {
    local_liamateriales_api_respond(400, ['status' => 'error', 'message' => 'petición incompleta']);
}

$file = $body->file;
$branch = $body->branch;
$maxsize = (int) get_config('local_liamateriales', 'max_file_size') ?: 1048576;
if (strlen($body->content) > $maxsize) {
    local_liamateriales_api_respond(413, ['status' => 'error', 'message' => 'archivo demasiado grande para edición online']);
}

try {
    local_liamateriales_validate_file($file);
    $tokendata = token_manager::validate($body->token, $file, $branch, true);
} catch (\moodle_exception $e) {
    local_liamateriales_api_token_failed();
    local_liamateriales_api_respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
}
local_liamateriales_api_limit('save', (int) $tokendata->userid);

$user = \core_user::get_user($tokendata->userid);

try {
    [$status, $result] = local_liamateriales_service_post_full('/edit/save', [
        'user' => $user->username,
        'file' => $file,
        'branch' => $branch,
        'original_sha' => $body->original_sha,
        'content' => $body->content,
        'message' => $body->commit_message ?? null,
    ]);
} catch (\moodle_exception $e) {
    local_liamateriales_api_respond(502, ['status' => 'error', 'message' => $e->getMessage()]);
}

if ($status === 200) {
    // D3: el plugin registra la versión modificada después del commit.
    local_liamateriales_record_save((int) $user->id, $file, $branch, $result->new_sha);
    // Contrato C6: token renovado en cada respuesta 200.
    $newtoken = token_manager::refresh($body->token, $file, $branch);
    // committed_at del nuevo commit si el engine lo da (opcional en el contrato).
    local_liamateriales_api_respond(200, local_liamateriales_save_ok_body(
        $result,
        $branch,
        $newtoken->token,
        get_string('savedok', 'local_liamateriales')
    ));
}

if ($status === 409) {
    local_liamateriales_api_respond(409, [
        'status' => 'conflict',
        'message' => get_string('saveconflict', 'local_liamateriales'),
        'resolution_branch' => $result->resolution_branch,
        'current_sha' => $result->current_sha,
        'diff_url' => $result->diff_url ?? null,
    ]);
}

local_liamateriales_api_respond($status >= 400 ? $status : 502, [
    'status' => 'error',
    'message' => $result->detail ?? 'error inesperado del engine',
]);
