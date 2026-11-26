<?php
// Incorporar la versión oficial (main) a la rama de edición (LIA-130).

/**
 * sync.php — el editor pregunta si `main` trae cambios del material que su
 * rama de edición no tiene y los incorpora (fusión, con los conflictos
 * resueltos en el editor).
 *
 * Autenticación por token de edición en el cuerpo, como save.php (D9):
 * - `{action: "status", token, file, branch}` → `GET /edit/sync` del engine:
 *   `{status: up_to_date | clean | conflict | conflict_other, behind,
 *   head_sha, main_sha, conflicts, segments, ours, merged}`.
 * - `{action: "merge", token, file, branch, head_sha, main_sha, content?}` →
 *   `POST /edit/merge`: 200 como un guardado (nuevo SHA y token renovado),
 *   409 si la rama o `main` cambiaron desde la revisión o quedan conflictos,
 *   400 si el material resuelto conserva marcas de conflicto.
 *
 * Nunca sobre `main` (no hay nada que incorporar a la propia versión oficial).
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
$action = is_object($body) ? ($body->action ?? '') : '';
if (!is_object($body) || !in_array($action, ['status', 'merge'], true) || empty($body->file)
        || empty($body->branch) || empty($body->token)) {
    local_liamateriales_api_respond(400, ['status' => 'error', 'message' => 'petición incompleta']);
}
$file = $body->file;
$branch = $body->branch;

try {
    local_liamateriales_validate_file($file);
    // Token de escritura del fichero y la rama (no el de vista previa).
    $tokendata = token_manager::validate($body->token, $file, $branch, true);
} catch (\moodle_exception $e) {
    local_liamateriales_api_token_failed();
    local_liamateriales_api_respond(403, ['status' => 'error', 'message' => $e->getMessage()]);
}
local_liamateriales_api_limit('sync', (int) $tokendata->userid);
if ($branch === 'main') {
    local_liamateriales_api_respond(200, ['status' => 'not_applicable']);
}

if ($action === 'status') {
    try {
        $result = local_liamateriales_service_get('/edit/sync', ['file' => $file, 'branch' => $branch]);
    } catch (\moodle_exception $e) {
        local_liamateriales_api_internal_error('sync.php: ' . $e->getMessage());
    }
    local_liamateriales_api_respond(200, [
        'status' => (string) $result->status,
        'behind' => (int) ($result->behind ?? 0),
        'head_sha' => (string) $result->head_sha,
        'main_sha' => (string) $result->main_sha,
        'conflicts' => array_values((array) ($result->conflicts ?? [])),
        'segments' => array_values((array) ($result->segments ?? [])),
        'ours' => (string) ($result->ours ?? ''),
        'merged' => (string) ($result->merged ?? ''),
    ]);
}

// action = merge.
$sha = '/^[0-9a-f]{40}$/';
if (!is_string($body->head_sha ?? null) || !preg_match($sha, $body->head_sha)
        || !is_string($body->main_sha ?? null) || !preg_match($sha, $body->main_sha)) {
    local_liamateriales_api_respond(400, ['status' => 'error', 'message' => 'petición incompleta']);
}
$content = $body->content ?? null;
if ($content !== null && (!is_string($content)
        || strlen($content) > ((int) get_config('local_liamateriales', 'max_file_size') ?: 1048576))) {
    local_liamateriales_api_respond(413, ['status' => 'error', 'message' => 'archivo demasiado grande para edición online']);
}

$user = \core_user::get_user($tokendata->userid);
try {
    [$status, $result] = local_liamateriales_service_post_full('/edit/merge', [
        'user' => $user->username,
        'file' => $file,
        'branch' => $branch,
        'head_sha' => $body->head_sha,
        'main_sha' => $body->main_sha,
        'content' => $content,
    ]);
} catch (\moodle_exception $e) {
    local_liamateriales_api_internal_error('sync.php: ' . $e->getMessage());
}

if ($status === 200) {
    local_liamateriales_record_save((int) $user->id, $file, $branch, $result->new_sha);
    \local_liamateriales\event\material_saved::fire((int) $user->id, (int) $tokendata->courseid, [
        'file' => $file,
        'branch' => $branch,
        'sha' => $result->new_sha,
        'merged_main' => $body->main_sha,
    ]);
    $newtoken = token_manager::refresh($body->token, $file, $branch);
    local_liamateriales_api_respond(200, local_liamateriales_save_ok_body(
        $result,
        $branch,
        $newtoken->token,
        get_string('mergedok', 'local_liamateriales')
    ));
}
if ($status === 409 || $status === 400) {
    // Motivo del engine (rama o main movidas, conflictos sin resolver): es para el profesor.
    local_liamateriales_api_respond($status, [
        'status' => $status === 409 ? 'conflict' : 'error',
        'message' => is_string($result->detail ?? null) ? $result->detail : get_string('mergefailed', 'local_liamateriales'),
    ]);
}
local_liamateriales_api_internal_error(
    "sync.php: engine HTTP {$status}: " . json_encode($result->detail ?? $result),
    $status >= 400 && $status < 500 ? $status : 502
);
