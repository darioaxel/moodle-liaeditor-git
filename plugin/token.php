<?php
// Renovación del token de un solo uso (LIA-015, contrato C6).

/**
 * token.php — renueva el token de edición sin guardar.
 *
 * El editor hace ping periódico durante sesiones largas para que el token
 * no caduque (C6 del roadmap del fork). Revoca el token actual y emite uno
 * nuevo con el mismo usuario+fichero+rama.
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
function token_respond(int $status, array $body): void {
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
if (!is_object($body) || empty($body->token) || empty($body->file) || empty($body->branch)) {
    token_respond(400, ['status' => 'error', 'message' => 'petición incompleta']);
}

try {
    $newtoken = token_manager::refresh($body->token, $body->file, $body->branch);
} catch (\moodle_exception $e) {
    token_respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
}

token_respond(200, [
    'status' => 'ok',
    'token' => $newtoken->token,
    'expires' => $newtoken->expires,
]);
