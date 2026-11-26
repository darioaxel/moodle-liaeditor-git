<?php
// Renovación del token de un solo uso (LIA-015, contrato C6).

/**
 * token.php — renueva el token de edición sin guardar.
 *
 * El editor hace ping periódico durante sesiones largas para que el token
 * no caduque (C6 del roadmap del fork). Revoca el token actual y emite uno
 * nuevo con el mismo usuario+fichero+rama.
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
if (!is_object($body) || empty($body->token) || empty($body->file) || empty($body->branch)) {
    local_liamateriales_api_respond(400, ['status' => 'error', 'message' => 'petición incompleta']);
}

try {
    $tokendata = token_manager::validate($body->token, $body->file, $body->branch);
} catch (\moodle_exception $e) {
    local_liamateriales_api_token_failed();
    local_liamateriales_api_respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
}
local_liamateriales_api_limit('token', (int) $tokendata->userid);
$newtoken = token_manager::refresh($body->token, $body->file, $body->branch);

local_liamateriales_api_respond(200, [
    'status' => 'ok',
    'token' => $newtoken->token,
    'expires' => $newtoken->expires,
]);
