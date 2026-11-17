<?php
// Página "Materiales" del curso (LIA-016/018): catálogo, mis ediciones y publicados.

/**
 * materials.php — punto de entrada del profesor al flujo LiaEditor.
 *
 * Tres secciones (UX acordada):
 *
 * 1. **Catálogo master**: materiales ``.md`` de la rama principal del repo
 *    (GET /edit/files del engine), con Vista previa (editor solo lectura),
 *    Editar (rama personal, D4) y Publicar.
 * 2. **Mis ediciones**: sesiones de edición activas del profesor con su
 *    último SHA guardado; continuar editando o publicar.
 * 3. **Publicados en este curso**: módulos creados con su estado por
 *    comparación de SHAs (UP_TO_DATE / UPDATE_AVAILABLE, §5.3 de la v1).
 *
 * @package    local_courseascode
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$courseid = required_param('id', PARAM_INT);
$context = context_course::instance($courseid);
require_login($courseid);
require_capability('local/courseascode:viewcatalog', $context);

$canedit = has_capability('local/courseascode:edit', $context);
$candeploy = has_capability('local/courseascode:deploy', $context);

$PAGE->set_url(new moodle_url('/local/courseascode/materials.php', ['id' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('materials', 'local_courseascode'));
$PAGE->set_heading(format_string(get_course($courseid)->fullname));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('materials', 'local_courseascode'));

// -- 1. Catálogo master ------------------------------------------------------

echo $OUTPUT->heading(get_string('materials_catalog', 'local_courseascode'), 3);

try {
    $materials = local_courseascode_service_get('/edit/files');
} catch (\moodle_exception $e) {
    $materials = null;
    echo $OUTPUT->notification(
        get_string('servicerror', 'local_courseascode', $e->getMessage()),
        \core\output\notification::NOTIFY_ERROR
    );
}

if ($materials && !empty($materials->files)) {
    $table = new html_table();
    $table->head = [
        get_string('material_title', 'local_courseascode'),
        get_string('material_file', 'local_courseascode'),
        get_string('actions'),
    ];
    foreach ($materials->files as $material) {
        $file = $material->path;
        $actions = [];
        // Vista previa solo lectura con token de un solo uso.
        $previewtoken = \local_courseascode\local\token_manager::issue((int) $USER->id, $file, $materials->branch);
        $previewcontent = (new moodle_url('/local/courseascode/content.php', [
            'file' => $file,
            'branch' => $materials->branch,
            'token' => $previewtoken->token,
            'readonly' => 1,
        ]))->out(false);
        $actions[] = html_writer::link(
            local_courseascode_editor_open_url($previewcontent, true),
            get_string('material_preview', 'local_courseascode'),
            ['target' => '_blank', 'rel' => 'noopener noreferrer', 'class' => 'btn btn-secondary btn-sm']
        );
        if ($canedit) {
            $actions[] = html_writer::link(
                new moodle_url('/local/courseascode/edit.php', ['id' => $courseid, 'file' => $file]),
                get_string('material_edit', 'local_courseascode'),
                ['class' => 'btn btn-primary btn-sm']
            );
        }
        if ($candeploy) {
            $actions[] = html_writer::link(
                new moodle_url('/local/courseascode/publish.php', ['id' => $courseid, 'file' => $file]),
                get_string('material_publish', 'local_courseascode'),
                ['class' => 'btn btn-secondary btn-sm']
            );
        }
        $table->data[] = [
            format_string($material->title),
            html_writer::tag('code', s($file)),
            implode(' ', $actions),
        ];
    }
    echo html_writer::table($table);
} else if ($materials) {
    echo $OUTPUT->notification(get_string('materials_empty', 'local_courseascode'), \core\output\notification::NOTIFY_INFO);
}

// -- 2. Mis ediciones --------------------------------------------------------

echo $OUTPUT->heading(get_string('materials_minedit', 'local_courseascode'), 3);

$sessions = $DB->get_records('local_courseascode_editsess', ['userid' => $USER->id, 'closed_at' => null], 'opened_at DESC');
if ($sessions) {
    $table = new html_table();
    $table->head = [
        get_string('material_file', 'local_courseascode'),
        get_string('material_branch', 'local_courseascode'),
        get_string('material_last_save', 'local_courseascode'),
        get_string('material_last_sha', 'local_courseascode'),
        get_string('actions'),
    ];
    foreach ($sessions as $session) {
        $actions = [];
        if ($canedit) {
            $actions[] = html_writer::link(
                new moodle_url('/local/courseascode/edit.php', [
                    'id' => $courseid,
                    'file' => $session->file,
                    'branch' => $session->branch,
                ]),
                get_string('material_continue', 'local_courseascode'),
                ['class' => 'btn btn-primary btn-sm']
            );
        }
        if ($candeploy && $session->last_sha !== '') {
            $actions[] = html_writer::link(
                new moodle_url('/local/courseascode/publish.php', ['id' => $courseid, 'file' => $session->file]),
                get_string('material_publish', 'local_courseascode'),
                ['class' => 'btn btn-secondary btn-sm']
            );
        }
        $table->data[] = [
            html_writer::tag('code', s($session->file)),
            html_writer::tag('code', s($session->branch)),
            $session->last_save ? userdate($session->last_save) : '—',
            $session->last_sha !== '' ? html_writer::tag('code', substr($session->last_sha, 0, 8)) : '—',
            implode(' ', $actions),
        ];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('materials_minedit_empty', 'local_courseascode'), \core\output\notification::NOTIFY_INFO);
}

// -- 3. Publicados en este curso ----------------------------------------------

echo $OUTPUT->heading(get_string('materials_published', 'local_courseascode'), 3);

$deploys = $DB->get_records('local_courseascode_ldeploy', ['courseid' => $courseid], 'deployed_at DESC, id DESC');
// La fila más reciente por fichero (historial completo en el log de Moodle).
$latest = [];
foreach ($deploys as $deploy) {
    if (!isset($latest[$deploy->source_file])) {
        $latest[$deploy->source_file] = $deploy;
    }
}
if ($latest) {
    $table = new html_table();
    $table->head = [
        get_string('material_file', 'local_courseascode'),
        get_string('material_module', 'local_courseascode'),
        get_string('material_published_sha', 'local_courseascode'),
        get_string('material_state', 'local_courseascode'),
        get_string('actions'),
    ];
    foreach ($latest as $deploy) {
        try {
            $state = local_courseascode_service_get('/edit/state', [
                'file' => $deploy->source_file,
                'sha' => $deploy->git_commit,
            ]);
            $statekey = $state->state;
        } catch (\moodle_exception $e) {
            $statekey = 'ERROR';
        }
        $badge = html_writer::span(
            local_courseascode_state_label($statekey),
            'badge bg-' . local_courseascode_state_class($statekey)
        );
        $cmlink = '—';
        if ($cm = get_coursemodule_from_id('', $deploy->cmid, 0, false, IGNORE_MISSING)) {
            $modinfo = get_fast_modinfo($courseid);
            $cms = $modinfo->get_cms();
            $cmname = isset($cms[$deploy->cmid]) ? $cms[$deploy->cmid]->name : $deploy->source_file;
            $cmlink = html_writer::link(
                new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]),
                format_string($cmname)
            );
        }
        $actions = '';
        if ($candeploy && $statekey === 'update_available') {
            $actions = html_writer::link(
                new moodle_url('/local/courseascode/publish.php', [
                    'id' => $courseid,
                    'file' => $deploy->source_file,
                    'update' => 1,
                ]),
                get_string('material_update', 'local_courseascode'),
                ['class' => 'btn btn-warning btn-sm']
            );
        }
        $table->data[] = [
            html_writer::tag('code', s($deploy->source_file)),
            $cmlink,
            html_writer::tag('code', substr($deploy->git_commit, 0, 8)),
            $badge,
            $actions,
        ];
    }
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('materials_published_empty', 'local_courseascode'), \core\output\notification::NOTIFY_INFO);
}

echo $OUTPUT->footer();
