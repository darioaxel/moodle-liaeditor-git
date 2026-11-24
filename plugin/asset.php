<?php
// Imágenes del material para el editor (docs/imagenes, fase 1).

/**
 * asset.php — sirve al editor una imagen del material que se está editando.
 *
 * El Markdown referencia las imágenes con rutas relativas
 * (``img/esquema.png``). El editor las pide aquí con ``POST {token, path}``
 * (el token nunca en la URL, LIA-062) y las muestra como ``blob:``. Vale
 * también el token de solo lectura (vista previa). El fichero y la rama
 * salen del token: solo se sirven imágenes de la carpeta de ese material
 * (lo comprueba el engine, que además verifica extensión y firma de imagen).
 *
 * Respuestas: 200 bytes de la imagen · 400 petición incompleta · 403 token
 * inválido · 404 imagen no encontrada · 429 límite de uso.
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- autenticación por token (D9).
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_liamateriales\local\engine_client;
use local_liamateriales\local\token_manager;

/** Tipos que se devuelven al navegador (nunca el que diga el cliente). */
const LOCAL_LIAMATERIALES_ASSET_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

local_liamateriales_api_preflight('POST');
local_liamateriales_api_guard_ip();

$body = json_decode(file_get_contents('php://input') ?: '');
if (!is_object($body) || empty($body->token) || !is_string($body->token)
        || empty($body->path) || !is_string($body->path) || strlen($body->path) > 255) {
    local_liamateriales_api_respond(400, ['status' => 'error', 'message' => 'petición incompleta']);
}

try {
    $tokendata = token_manager::lookup($body->token);
    $file = local_liamateriales_validate_file($tokendata->file);
} catch (\moodle_exception $e) {
    local_liamateriales_api_token_failed();
    local_liamateriales_api_respond(403, ['status' => 'error', 'message' => get_string('tokeninvalid', 'local_liamateriales')]);
}
local_liamateriales_api_limit('asset', (int) $tokendata->userid);

try {
    $result = engine_client::send(
        'GET',
        '/edit/asset',
        ['file' => $file, 'branch' => $tokendata->branch, 'path' => $body->path],
        null,
        'image/*'
    );
} catch (\moodle_exception $e) {
    local_liamateriales_api_internal_error('asset.php: ' . $e->getMessage());
}
if ($result->errno) {
    local_liamateriales_api_internal_error('asset.php: ' . $result->error);
}
if ($result->status === 404) {
    local_liamateriales_api_respond(404, ['status' => 'error', 'message' => get_string('assetnotfound', 'local_liamateriales')]);
}
$type = '';
foreach ($result->headers as $name => $value) {
    if (strcasecmp(trim((string) $name), 'content-type') === 0) {
        $type = strtolower(trim(explode(';', (string) $value)[0]));
    }
}
if ($result->status !== 200 || !in_array($type, LOCAL_LIAMATERIALES_ASSET_TYPES, true)) {
    local_liamateriales_api_internal_error("asset.php: engine HTTP {$result->status}, tipo «{$type}»");
}

http_response_code(200);
local_liamateriales_api_headers();
header('Content-Type: ' . $type);
header('Content-Length: ' . strlen($result->body));
// Si alguien abre la respuesta directamente, que no pueda ejecutar nada.
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
header('Content-Disposition: inline; filename="' . basename(clean_param($body->path, PARAM_FILE) ?: 'imagen') . '"');
echo $result->body;
