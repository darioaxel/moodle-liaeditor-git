<?php
// Página "Materiales" del curso (LIA-016/018): catálogo, mis contenidos y publicados.

/**
 * materials.php — punto de entrada del profesor al flujo LiaEditor.
 *
 * Tres pestañas (UX acordada):
 *
 * 1. **Contenidos Master**: materiales ``.md`` de la rama principal del repo
 *    (GET /edit/files del engine), con Vista previa (editor solo lectura),
 *    Editar (rama personal) y Publicar.
 * 2. **Mis Contenidos**: sesiones de edición activas del profesor con su
 *    último SHA guardado; continuar editando o publicar.
 * 3. **Publicados en este curso**: módulos creados con su estado por
 *    comparación de SHAs (UP_TO_DATE / UPDATE_AVAILABLE, §5.3 de la v1).
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$courseid = required_param('id', PARAM_INT);
$context = context_course::instance($courseid);
require_login($courseid);
require_capability('local/liamateriales:viewcatalog', $context);

$canedit = has_capability('local/liamateriales:edit', $context);
$candeploy = has_capability('local/liamateriales:deploy', $context);

$pagetitle = get_string('materials', 'local_liamateriales');

$PAGE->set_url(new moodle_url('/local/liamateriales/materials.php', ['id' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title($pagetitle);
$PAGE->set_heading($pagetitle);

$tabidmaster = 'cacc-master';
$tabidminedit = 'cacc-minedit';
$tabidpublished = 'cacc-published';

// Lo publicado que sigue en el curso (el último despliegue por fichero y
// formato cuya actividad no se ha borrado) y su estado frente a su rama.
$latest = \local_liamateriales\local\publisher::live_deploys($courseid);
$states = [];
$dependents = [];
foreach ($latest as $deploy) {
    try {
        $states[$deploy->id] = local_liamateriales_deploy_state($deploy, (int) $USER->id);
    } catch (\moodle_exception $e) {
        $states[$deploy->id] = null;
        continue;
    }
    // Generado desde main y obsoleto tras un cambio de main (LIA-129).
    if (\local_liamateriales\local\publisher::is_main_dependent($states[$deploy->id])) {
        $dependents[$deploy->id] = $deploy;
    }
}

/**
 * Botón «Actualizar todos los contenidos dependientes de esta versión»
 * (formulario POST a update_dependents.php; js/update_dependents.js muestra
 * el progreso).
 */
$dependentsbutton = static function () use ($courseid): string {
    return html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url('/local/liamateriales/update_dependents.php'))->out(false),
        'class' => 'liamat-update-dependents d-inline',
    ])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $courseid])
        . html_writer::tag('button', get_string('dependents_update', 'local_liamateriales'), [
            'type' => 'submit',
            'class' => 'btn btn-warning btn-sm',
        ])
        . html_writer::end_tag('form');
};

echo $OUTPUT->header();

if ($candeploy && $dependents) {
    // Aviso visible desde cualquier pestaña: la versión oficial cambió.
    echo $OUTPUT->notification(
        get_string(count($dependents) === 1 ? 'dependents_notice_one' : 'dependents_notice', 'local_liamateriales', count($dependents))
            . ' ' . $dependentsbutton(),
        \core\output\notification::NOTIFY_WARNING
    );
    $items = [];
    foreach ($dependents as $deploy) {
        $items[] = [
            'id' => (int) $deploy->id,
            'label' => $deploy->source_file . ' · '
                . \local_liamateriales\local\publisher::label($deploy->artifact_type),
        ];
    }
    echo html_writer::div('', '', [
        'id' => 'liamat-dependents',
        'hidden' => 'hidden',
        'data-config' => json_encode([
            'items' => $items,
            'returnurl' => (new moodle_url('/local/liamateriales/materials.php', ['id' => $courseid]))->out(false),
            'strings' => [
                'confirm' => get_string('dependents_confirm', 'local_liamateriales', count($items)),
                'title' => get_string('dependents_update', 'local_liamateriales'),
                'pending' => get_string('dependents_pending', 'local_liamateriales'),
                'running' => get_string('dependents_running', 'local_liamateriales'),
                'updated' => get_string('dependents_updated', 'local_liamateriales'),
                'skipped' => get_string('dependents_skipped', 'local_liamateriales'),
                'error' => get_string('dependents_error', 'local_liamateriales'),
                'keepopen' => get_string('progress_keepopen', 'local_liamateriales'),
                'done' => get_string('dependents_finished', 'local_liamateriales'),
                'close' => get_string('dependents_close', 'local_liamateriales'),
            ],
        ]),
    ]);
    $PAGE->requires->js(new moodle_url('/local/liamateriales/js/update_dependents.js'));
}

// -- Navegación por pestañas -------------------------------------------------

$tabactive = ' active';
$paneactive = ' show active';

$tabs = [
    $tabidmaster => get_string('materials_catalog', 'local_liamateriales'),
    $tabidminedit => get_string('materials_minedit', 'local_liamateriales'),
    $tabidpublished => get_string('materials_published', 'local_liamateriales'),
];

echo html_writer::start_tag('ul', ['class' => 'nav nav-tabs mb-3', 'role' => 'tablist']);
foreach ($tabs as $tid => $tlabel) {
    echo html_writer::tag('li',
        html_writer::link(
            '#' . $tid,
            $tlabel,
            [
                'class' => 'nav-link' . $tabactive,
                'id' => $tid . '-tab',
                'data-bs-toggle' => 'tab',
                'data-toggle' => 'tab',
                'href' => '#' . $tid,
                'role' => 'tab',
                'aria-controls' => $tid,
                'aria-selected' => $tabactive ? 'true' : 'false',
            ]
        ),
        ['class' => 'nav-item', 'role' => 'presentation']
    );
    $tabactive = '';
}
echo html_writer::end_tag('ul');

echo html_writer::start_tag('div', ['class' => 'tab-content']);

// -- 1. Contenidos Master ----------------------------------------------------

echo html_writer::start_tag('div', [
    'class' => 'tab-pane fade' . $paneactive,
    'id' => $tabidmaster,
    'role' => 'tabpanel',
    'aria-labelledby' => $tabidmaster . '-tab',
]);
$paneactive = '';

try {
    $materials = local_liamateriales_service_get('/edit/files');
} catch (\moodle_exception $e) {
    $materials = null;
    echo $OUTPUT->notification(
        get_string('servicerror', 'local_liamateriales', $e->getMessage()),
        \core\output\notification::NOTIFY_ERROR
    );
}

if ($materials && !empty($materials->files)) {
    $table = new html_table();
    $table->head = [
        get_string('material_title', 'local_liamateriales'),
        get_string('material_file', 'local_liamateriales'),
        get_string('actions', 'local_liamateriales'),
    ];
    foreach ($materials->files as $material) {
        $file = $material->path;
        $actions = [];
        // Vista previa de solo lectura: el código se emite al pulsar (preview.php, LIA-067).
        $actions[] = html_writer::link(
            new moodle_url('/local/liamateriales/preview.php', ['id' => $courseid, 'file' => $file]),
            get_string('material_preview', 'local_liamateriales'),
            ['target' => '_blank', 'rel' => 'noopener noreferrer', 'class' => 'btn btn-secondary btn-sm']
        );
        if ($canedit) {
            $actions[] = html_writer::link(
                new moodle_url('/local/liamateriales/edit.php', ['id' => $courseid, 'file' => $file]),
                get_string('material_edit', 'local_liamateriales'),
                ['class' => 'btn btn-primary btn-sm']
            );
        }
        if ($candeploy) {
            $actions[] = html_writer::link(
                new moodle_url('/local/liamateriales/publish.php', ['id' => $courseid, 'file' => $file]),
                get_string('material_publish', 'local_liamateriales'),
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
    echo $OUTPUT->notification(get_string('materials_empty', 'local_liamateriales'), \core\output\notification::NOTIFY_INFO);
}

echo html_writer::end_tag('div');

// -- 2. Mis Contenidos -------------------------------------------------------

echo html_writer::start_tag('div', [
    'class' => 'tab-pane fade',
    'id' => $tabidminedit,
    'role' => 'tabpanel',
    'aria-labelledby' => $tabidminedit . '-tab',
]);

$sessions = $DB->get_records('local_liamateriales_editsess', ['userid' => $USER->id, 'closed_at' => null], 'opened_at DESC');
if ($sessions) {
    $table = new html_table();
    $table->head = [
        get_string('material_file', 'local_liamateriales'),
        get_string('material_branch', 'local_liamateriales'),
        get_string('material_last_save', 'local_liamateriales'),
        get_string('material_last_sha', 'local_liamateriales'),
        get_string('actions', 'local_liamateriales'),
    ];
    foreach ($sessions as $session) {
        $actions = [];
        if ($canedit) {
            $actions[] = html_writer::link(
                new moodle_url('/local/liamateriales/edit.php', [
                    'id' => $courseid,
                    'file' => $session->file,
                    'branch' => $session->branch,
                ]),
                get_string('material_continue', 'local_liamateriales'),
                ['class' => 'btn btn-primary btn-sm']
            );
        }
        if ($candeploy && $session->last_sha !== '') {
            $actions[] = html_writer::link(
                new moodle_url('/local/liamateriales/publish.php', ['id' => $courseid, 'file' => $session->file]),
                get_string('material_publish', 'local_liamateriales'),
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
    echo $OUTPUT->notification(get_string('materials_minedit_empty', 'local_liamateriales'), \core\output\notification::NOTIFY_INFO);
}

echo html_writer::end_tag('div');

// -- 3. Publicados en este curso ---------------------------------------------

echo html_writer::start_tag('div', [
    'class' => 'tab-pane fade',
    'id' => $tabidpublished,
    'role' => 'tabpanel',
    'aria-labelledby' => $tabidpublished . '-tab',
]);

// Lo publicado que sigue en el curso ($latest, calculado arriba con su estado).
if ($latest) {
    $table = new html_table();
    $table->head = [
        get_string('material_file', 'local_liamateriales'),
        get_string('material_module', 'local_liamateriales'),
        get_string('material_published_sha', 'local_liamateriales'),
        get_string('material_state', 'local_liamateriales'),
        get_string('actions', 'local_liamateriales'),
    ];
    $cms = get_fast_modinfo($courseid)->get_cms();
    foreach ($latest as $deploy) {
        $cm = $cms[(int) $deploy->cmid] ?? null;
        if (!$cm) {
            continue;
        }
        $statekey = $states[$deploy->id]->state ?? 'ERROR';
        $badge = html_writer::span(
            local_liamateriales_state_label($statekey),
            'badge bg-' . local_liamateriales_state_class($statekey)
        );
        $cmlink = html_writer::link(
            new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cm->id]),
            format_string($cm->name)
        );
        $actions = '';
        if ($candeploy && isset($dependents[$deploy->id])) {
            // Generado desde main y obsoleto: se actualizan todos a la vez.
            $actions = $dependentsbutton();
        } else if ($candeploy && $statekey === 'update_available') {
            $actions = html_writer::link(
                new moodle_url('/local/liamateriales/publish.php', [
                    'id' => $courseid,
                    'file' => $deploy->source_file,
                    'update' => 1,
                ]),
                get_string('material_update', 'local_liamateriales'),
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
    echo $OUTPUT->notification(get_string('materials_published_empty', 'local_liamateriales'), \core\output\notification::NOTIFY_INFO);
}

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

echo local_liamateriales_version_footer();
echo $OUTPUT->footer();
