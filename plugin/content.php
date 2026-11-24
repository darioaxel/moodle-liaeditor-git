<?php
// Proxy de contenido para el editor LiaEdit (LIA-013, patrón B, D9).

/**
 * content.php — sirve el markdown de un material al editor self-hosted.
 *
 * La autenticación es por token (D9): las cookies de sesión de Moodle no
 * viajan en el fetch cross-origin del editor. Ninguna URL lleva un token
 * válido (LIA-062):
 *
 * - ``POST {code}``: canjea el código de apertura (60 s, un solo uso) que
 *   edit.php o preview.php pusieron en el fragmento de la URL del editor, y
 *   devuelve el material con el token de edición en el bloque.
 * - ``POST {token}``: recarga con el token de edición vigente («Recargar la
 *   versión del servidor» del editor).
 * - ``GET ?file&branch&token``: formato antiguo (fork de LiveEditor), solo
 *   con el ajuste ``legacy_open_url``.
 *
 * Fichero, rama y modo (solo lectura) salen siempre del token.
 *
 * Responde el markdown con el bloque ``liascript-lms`` inyectado en la
 * cabecera (roadmap del fork §C2): endpoints de guardado/renovación, SHA,
 * token y modo solo lectura. CORS restringido al origen del editor.
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- autenticación por token (D9).
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_liamateriales\local\token_manager;

local_liamateriales_api_preflight('GET, POST');
local_liamateriales_api_guard_ip();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $request = json_decode(file_get_contents('php://input') ?: '');
        if (is_object($request) && !empty($request->code) && is_string($request->code)) {
            $tokendata = token_manager::redeem($request->code);
        } else if (is_object($request) && !empty($request->token) && is_string($request->token)) {
            $tokendata = token_manager::lookup($request->token);
            $tokendata->token = $request->token;
        } else {
            local_liamateriales_api_respond(400, ['status' => 'error', 'message' => 'petición incompleta']);
        }
    } else if (local_liamateriales_legacy_open()) {
        // Formato antiguo: los parámetros se leen tras el preflight.
        $token = required_param('token', PARAM_RAW);
        $tokendata = token_manager::validate($token, required_param('file', PARAM_PATH), required_param('branch', PARAM_RAW));
        $tokendata->token = $token;
    } else {
        local_liamateriales_api_token_failed();
        local_liamateriales_api_respond(403, ['status' => 'error', 'message' => get_string('tokeninvalid', 'local_liamateriales')]);
    }
    $file = local_liamateriales_validate_file($tokendata->file);
    $branch = $tokendata->branch;
    $token = $tokendata->token;
} catch (\moodle_exception $e) {
    local_liamateriales_api_token_failed();
    local_liamateriales_api_respond(403, ['status' => 'error', 'message' => get_string('tokeninvalid', 'local_liamateriales')]);
}
local_liamateriales_api_limit('content', (int) $tokendata->userid);
// El modo lo decide el token (vista previa = solo lectura), no la URL.
$readonly = !empty($tokendata->readonly);

try {
    $result = local_liamateriales_service_get('/edit/content', [
        'file' => $file,
        'branch' => $branch,
    ]);
} catch (\moodle_exception $e) {
    local_liamateriales_api_respond(502, ['status' => 'error', 'message' => $e->getMessage()]);
}

$baseurl = (new \moodle_url('/local/liamateriales/save.php'))->out(false);
$tokenurl = (new \moodle_url('/local/liamateriales/token.php'))->out(false);
$returnurl = (new \moodle_url('/my/courses.php'))->out(false);

// committed_at (fecha del commit sha) solo si el engine la da en ISO 8601.
$lmsconfig = local_liamateriales_lms_config([
    'save_endpoint' => $baseurl,
    'token_refresh_endpoint' => $tokenurl,
    'file' => $file,
    'branch' => $branch,
    'sha' => $result->sha,
    'token' => $token,
    'readonly' => (bool) $readonly,
    'return_url' => $returnurl,
], $result->committed_at ?? null);
// Publicación en un clic (D7 fase 2): solo si el token conoce el curso de
// origen — el editor muestra el botón Publicar solo si hay publish_endpoint.
if (!$readonly && (int) $tokendata->courseid > 0) {
    $lmsconfig['publish_endpoint'] = (new \moodle_url('/local/liamateriales/publish_api.php'))->out(false);
}

$markdown = local_liamateriales_inject_lms_block($result->content, $lmsconfig);

// Auditoría (LIA-069): quién abrió qué versión, y si fue en solo lectura.
\local_liamateriales\event\material_opened::fire((int) $tokendata->userid, (int) $tokendata->courseid, [
    'file' => $file,
    'branch' => $branch,
    'sha' => $result->sha,
    'readonly' => $readonly ? 1 : 0,
]);

http_response_code(200);
local_liamateriales_api_headers();
header('Content-Type: text/plain; charset=utf-8');
header('X-LiaMD-Branch: ' . $branch);
header('X-LiaMD-SHA: ' . $result->sha);
header('X-LiaMD-Readonly: ' . ($readonly ? 'true' : 'false'));
echo $markdown;
