<?php
// Orquestador de apertura en el editor LiaEdit (LIA-016, D4/D9).

/**
 * edit.php — "Editar" desde el catálogo de materiales.
 *
 * Flujo (§5.1 de la v1): verifica la capacidad ``edit`` en el curso, pide
 * al engine que asegure la rama ``edicion/<user>/<file>`` (POST /edit/open,
 * D4), emite un token de un solo uso (D9), registra la sesión de edición y
 * redirige al editor self-hosted con la URL de content.php.
 *
 * @package    local_courseascode
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_courseascode\local\token_manager;

$courseid = required_param('id', PARAM_INT);
$file = required_param('file', PARAM_PATH);
$branch = optional_param('branch', '', PARAM_RAW); // Rama explícita (p. ej. continuar sesión).

$context = context_course::instance($courseid);
require_login($courseid);
require_capability('local/courseascode:edit', $context);

local_courseascode_validate_file($file);

$editorurl = local_courseascode_editor_url();
if ($editorurl === '') {
    throw new \moodle_exception('editornotconfigured', 'local_courseascode');
}

if ($branch === '') {
    // Asegura la rama de edición del usuario (creación o reutilización, D4).
    try {
        $opened = local_courseascode_service_post('/edit/open', [
            'user' => $USER->username,
            'file' => $file,
        ]);
    } catch (\moodle_exception $e) {
        throw new \moodle_exception('servicerror', 'local_courseascode', '', $e->getMessage());
    }
    $branch = $opened->branch;
}

$token = token_manager::issue((int) $USER->id, $file, $branch);
local_courseascode_touch_session((int) $USER->id, $file, $branch);

$contenturl = (new \moodle_url('/local/courseascode/content.php', [
    'file' => $file,
    'branch' => $branch,
    'token' => $token->token,
]))->out(false);

redirect(local_courseascode_editor_open_url($contenturl, false));
