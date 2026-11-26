<?php
// Orquestador de apertura en el editor LiaEdit (LIA-016, D4/D9).

/**
 * edit.php — "Editar" desde el catálogo de materiales.
 *
 * Flujo (§5.1 de la v1): verifica la capacidad ``edit`` en el curso, pide
 * al engine que asegure la rama ``edicion/<user>/<file>`` (POST /edit/open,
 * D4), registra la sesión de edición y redirige al editor self-hosted con
 * un código de apertura de un solo uso en el fragmento (LIA-062).
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$courseid = required_param('id', PARAM_INT);
$file = required_param('file', PARAM_PATH);
$branch = optional_param('branch', '', PARAM_RAW); // Rama explícita (p. ej. continuar sesión).

$context = context_course::instance($courseid);
require_login($courseid);
require_capability('local/liamateriales:edit', $context);

local_liamateriales_validate_file($file);

$editorurl = local_liamateriales_editor_url();
if ($editorurl === '') {
    throw new \moodle_exception('editornotconfigured', 'local_liamateriales');
}

if ($branch !== '') {
    // Continuar una sesión: solo una rama de una sesión propia de este
    // fichero, o `main` con la capacidad editmain. Nunca una rama ajena.
    $ownsession = $DB->record_exists('local_liamateriales_editsess', [
        'userid' => $USER->id,
        'file' => $file,
        'branch' => $branch,
    ]);
    $mainallowed = $branch === 'main' && has_capability('local/liamateriales:editmain', $context);
    if (!$ownsession && !$mainallowed) {
        throw new \moodle_exception('invalidbranch', 'local_liamateriales');
    }
}

if ($branch === '') {
    // Asegura la rama de edición del usuario (creación o reutilización, D4).
    try {
        $opened = local_liamateriales_service_post('/edit/open', [
            'user' => $USER->username,
            'file' => $file,
        ]);
    } catch (\moodle_exception $e) {
        throw new \moodle_exception('servicerror', 'local_liamateriales', '', $e->getMessage());
    }
    $branch = $opened->branch;
}

local_liamateriales_touch_session((int) $USER->id, $file, $branch);

// Código de apertura de un solo uso en el fragmento de la URL (LIA-062).
redirect(local_liamateriales_open_in_editor((int) $USER->id, $file, $branch, $courseid, false));
