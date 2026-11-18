<?php
// Proxy de contenido para el editor LiaEdit (LIA-013, patrón B, D9).

/**
 * content.php — sirve el markdown de un material al editor self-hosted.
 *
 * La autenticación es el **token de un solo uso** (D9): las cookies de
 * sesión de Moodle no viajan en el fetch cross-origin del editor. El token
 * se emite en edit.php (con capacidad ya verificada) y va ligado a
 * usuario+fichero+rama con caducidad corta.
 *
 * Responde el markdown con el bloque ``liascript-lms`` inyectado en la
 * cabecera (roadmap del fork §C2): endpoints de guardado/renovación, SHA,
 * token y modo solo lectura. CORS restringido al origen del editor.
 *
 * @package    local_courseascode
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- autenticación por token (D9).
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_courseascode\local\token_manager;

$file = required_param('file', PARAM_PATH);
$branch = required_param('branch', PARAM_RAW);
$token = required_param('token', PARAM_RAW);
$readonly = optional_param('readonly', 0, PARAM_BOOL);

$origin = local_courseascode_cors_origin();

/**
 * Respuesta de error JSON con CORS (el editor la muestra como pantalla clara).
 */
function content_error(int $status, string $message): void {
    global $origin;
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
    }
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Preflight CORS del fetch del editor.
    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }
    exit;
}

try {
    local_courseascode_validate_file($file);
    $tokendata = token_manager::validate($token, $file, $branch);
} catch (\moodle_exception $e) {
    content_error(403, get_string('tokeninvalid', 'local_courseascode'));
}

try {
    $result = local_courseascode_service_get('/edit/content', [
        'file' => $file,
        'branch' => $branch,
    ]);
} catch (\moodle_exception $e) {
    content_error(502, $e->getMessage());
}

$baseurl = (new \moodle_url('/local/courseascode/save.php'))->out(false);
$tokenurl = (new \moodle_url('/local/courseascode/token.php'))->out(false);
$returnurl = (new \moodle_url('/my/courses.php'))->out(false);

$lmsconfig = [
    'save_endpoint' => $baseurl,
    'token_refresh_endpoint' => $tokenurl,
    'file' => $file,
    'branch' => $branch,
    'sha' => $result->sha,
    'token' => $token,
    'readonly' => (bool) $readonly,
    'return_url' => $returnurl,
];
// Publicación en un clic (D7 fase 2): solo si el token conoce el curso de
// origen — el editor muestra el botón Publicar solo si hay publish_endpoint.
if (!$readonly && (int) $tokendata->courseid > 0) {
    $lmsconfig['publish_endpoint'] = (new \moodle_url('/local/courseascode/publish_api.php'))->out(false);
}

$markdown = local_courseascode_inject_lms_block($result->content, $lmsconfig);

http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('X-LiaMD-Branch: ' . $branch);
header('X-LiaMD-SHA: ' . $result->sha);
header('X-LiaMD-Readonly: ' . ($readonly ? 'true' : 'false'));
echo $markdown;
