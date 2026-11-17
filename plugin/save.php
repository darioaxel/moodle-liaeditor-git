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
 * Respuestas: 200 ``{status: ok, new_sha, token}`` · 409 ``{status:
 * conflict, resolution_branch, current_sha, diff_url}`` · 403 token
 * inválido · 502 error del engine.
 *
 * @package    local_courseascode
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- autenticación por token (D9).
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_courseascode\local\token_manager;

$origin = local_courseascode_cors_origin();

/**
 * Respuesta JSON con CORS y cierre.
 */
function save_respond(int $status, array $body): void {
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
if (!is_object($body) || empty($body->file) || empty($body->branch) || empty($body->original_sha)
        || !isset($body->content) || empty($body->token)) {
    save_respond(400, ['status' => 'error', 'message' => 'petición incompleta']);
}

$file = $body->file;
$branch = $body->branch;
$maxsize = (int) get_config('local_courseascode', 'max_file_size') ?: 1048576;
if (strlen($body->content) > $maxsize) {
    save_respond(413, ['status' => 'error', 'message' => 'archivo demasiado grande para edición online']);
}

try {
    local_courseascode_validate_file($file);
    $tokendata = token_manager::validate($body->token, $file, $branch);
} catch (\moodle_exception $e) {
    save_respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
}

$user = \core_user::get_user($tokendata->userid);

try {
    [$status, $result] = local_courseascode_service_post_full('/edit/save', [
        'user' => $user->username,
        'file' => $file,
        'branch' => $branch,
        'original_sha' => $body->original_sha,
        'content' => $body->content,
        'message' => $body->commit_message ?? null,
    ]);
} catch (\moodle_exception $e) {
    save_respond(502, ['status' => 'error', 'message' => $e->getMessage()]);
}

if ($status === 200) {
    // D3: el plugin registra la versión modificada después del commit.
    local_courseascode_record_save((int) $user->id, $file, $branch, $result->new_sha);
    // Contrato C6: token renovado en cada respuesta 200.
    $newtoken = token_manager::refresh($body->token, $file, $branch);
    save_respond(200, [
        'status' => 'ok',
        'new_sha' => $result->new_sha,
        'branch' => $branch,
        'token' => $newtoken->token,
        'message' => get_string('savedok', 'local_courseascode'),
    ]);
}

if ($status === 409) {
    save_respond(409, [
        'status' => 'conflict',
        'message' => get_string('saveconflict', 'local_courseascode'),
        'resolution_branch' => $result->resolution_branch,
        'current_sha' => $result->current_sha,
        'diff_url' => $result->diff_url ?? null,
    ]);
}

save_respond($status >= 400 ? $status : 502, [
    'status' => 'error',
    'message' => $result->detail ?? 'error inesperado del engine',
]);
