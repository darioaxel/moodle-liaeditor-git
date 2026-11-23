<?php
// Paso "Publicar en el curso" (D6).

/**
 * publish.php — publica un material en el curso.
 *
 * Acción principal: **generar y publicar**. El servidor genera el artefacto
 * (H5P/ePub/PDF) desde el Markdown en Git con liaex (engine, D7 fase 2) y
 * crea o actualiza el módulo (mod_h5pactivity / mod_resource), registrando
 * el despliegue curso↔SHA↔cmid. Es el mismo camino que el botón Publicar
 * del editor.
 *
 * Alternativa: subir un fichero generado por cuenta propia (p. ej. con el
 * LiaScript Exporter en el navegador), con el mismo registro.
 *
 * La versión se elige entre la última guardada por el usuario y la oficial
 * (`main`); el SHA lo resuelve el servidor, no llega del formulario.
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_liamateriales\local\publisher;

$courseid = required_param('id', PARAM_INT);
$file = required_param('file', PARAM_PATH);
$context = context_course::instance($courseid);
require_login($courseid);
require_capability('local/liamateriales:deploy', $context);
require_capability('moodle/course:manageactivities', $context);

local_liamateriales_validate_file($file);

$pageurl = new moodle_url('/local/liamateriales/publish.php', ['id' => $courseid, 'file' => $file]);
$returnurl = new moodle_url('/local/liamateriales/materials.php', ['id' => $courseid]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_title(get_string('material_publish', 'local_liamateriales'));
$PAGE->set_heading(format_string(get_course($courseid)->fullname));

// Versiones publicables: la última guardada por el usuario y la oficial.
$versions = [];
$session = $DB->get_record('local_liamateriales_editsess', [
    'userid' => $USER->id,
    'file' => $file,
    'closed_at' => null,
]);
if ($session && strlen((string) $session->last_sha) === 40) {
    $versions['saved'] = (object) [
        'sha' => $session->last_sha,
        'label' => get_string('publish_version_saved', 'local_liamateriales', (object) [
            'branch' => $session->branch,
            'date' => $session->last_save ? userdate($session->last_save) : '—',
        ]),
    ];
}
try {
    $main = local_liamateriales_service_get('/edit/content', ['file' => $file, 'branch' => 'main']);
    if (!empty($main->sha) && strlen($main->sha) === 40) {
        $versions['main'] = (object) [
            'sha' => $main->sha,
            'label' => get_string('publish_version_main', 'local_liamateriales'),
        ];
    }
} catch (\moodle_exception $e) {
    // Sin engine o sin el fichero en main: solo queda la versión guardada.
    debugging('publish.php: no se pudo leer main: ' . $e->getMessage(), DEBUG_DEVELOPER);
}

$types = [
    'h5p' => get_string('publish_type_h5p', 'local_liamateriales'),
    'epub' => get_string('publish_type_epub', 'local_liamateriales'),
    'pdf' => get_string('publish_type_pdf', 'local_liamateriales'),
];

if (data_submitted() && confirm_sesskey()) {
    $mode = optional_param('mode', 'generate', PARAM_ALPHA);
    $artifacttype = required_param('artifact_type', PARAM_ALPHA);
    $versionkey = required_param('version', PARAM_ALPHA);
    $name = trim(optional_param('name', '', PARAM_TEXT));
    $section = optional_param('section', 0, PARAM_INT);

    if (!isset($types[$artifacttype])) {
        throw new \moodle_exception('invalidartifacttype', 'local_liamateriales', '', $artifacttype);
    }
    if (!isset($versions[$versionkey])) {
        throw new \moodle_exception('publishnosha', 'local_liamateriales');
    }
    $sha = $versions[$versionkey]->sha;

    try {
        if ($mode === 'upload') {
            $upload = $_FILES['artifact'] ?? null;
            if (!$upload || !is_uploaded_file($upload['tmp_name']) || $upload['error'] !== UPLOAD_ERR_OK) {
                throw new \moodle_exception('publishnofile', 'local_liamateriales');
            }
            $cmid = publisher::publish(
                $courseid,
                $section,
                $file,
                $artifacttype,
                $sha,
                publisher::stage_draft((int) $USER->id, $upload['name'], file_get_contents($upload['tmp_name'])),
                $name !== '' ? $name : publisher::default_name($courseid, $file, $artifacttype),
                publisher::reusable_cmid($courseid, $file, $artifacttype),
                $USER->username
            );
        } else {
            $cmid = publisher::publish_generated(
                $courseid,
                $section,
                $file,
                $artifacttype,
                $sha,
                $name !== '' ? $name : null,
                $USER
            );
        }
    } catch (\moodle_exception $e) {
        redirect($pageurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }

    $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
    redirect(
        new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cmid]),
        get_string('publishok', 'local_liamateriales', format_string($cm->name)),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Formulario (GET).
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('material_publish', 'local_liamateriales'));

echo $OUTPUT->box_start('generalbox');
echo html_writer::tag('p', html_writer::tag('strong', get_string('material_file', 'local_liamateriales') . ': ')
    . html_writer::tag('code', s($file)));
$latest = publisher::latest_deploy($courseid, $file);
if ($latest) {
    echo html_writer::tag('p', html_writer::tag('strong', get_string('material_published_sha', 'local_liamateriales') . ': ')
        . html_writer::tag('code', s(substr($latest->git_commit, 0, 8)))
        . ' (' . s(strtoupper($latest->artifact_type)) . ', ' . userdate($latest->deployed_at) . ')');
}
echo $OUTPUT->box_end();

if (!$versions) {
    echo $OUTPUT->notification(get_string('publishnosha', 'local_liamateriales'), \core\output\notification::NOTIFY_WARNING);
    echo html_writer::link($returnurl, get_string('back'), ['class' => 'btn btn-secondary']);
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::start_tag('form', [
    'method' => 'post',
    'enctype' => 'multipart/form-data',
    'action' => $pageurl->out(false),
    'class' => 'mform',
]);
echo html_writer::input_hidden_params($pageurl);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

// Versión.
echo html_writer::start_tag('fieldset', ['class' => 'mb-3']);
echo html_writer::tag('legend', get_string('publish_version', 'local_liamateriales'), ['class' => 'h6']);
$first = true;
foreach ($versions as $key => $version) {
    $id = 'version-' . $key;
    echo html_writer::div(
        html_writer::empty_tag('input', array_filter([
            'type' => 'radio', 'name' => 'version', 'value' => $key, 'id' => $id,
            'class' => 'form-check-input', 'checked' => $first ? 'checked' : null,
        ])) . ' ' . html_writer::tag('label', s($version->label) . ' · '
            . html_writer::tag('code', s(substr($version->sha, 0, 8))), ['for' => $id, 'class' => 'form-check-label']),
        'form-check'
    );
    $first = false;
}
echo html_writer::end_tag('fieldset');

echo html_writer::div(html_writer::tag('label', get_string('publish_type', 'local_liamateriales'), ['for' => 'artifact_type'])
    . html_writer::select($types, 'artifact_type', 'h5p', false, ['class' => 'form-select', 'id' => 'artifact_type']), 'mb-3');

echo html_writer::div(html_writer::tag('label', get_string('publish_name', 'local_liamateriales'), ['for' => 'pubname'])
    . html_writer::empty_tag('input', [
        'type' => 'text', 'name' => 'name', 'id' => 'pubname', 'class' => 'form-control', 'maxlength' => 255,
        'placeholder' => get_string('publish_name_default', 'local_liamateriales'),
    ]), 'mb-3');

echo html_writer::div(html_writer::tag('label', get_string('publish_section', 'local_liamateriales'), ['for' => 'section'])
    . html_writer::empty_tag('input', [
        'type' => 'number', 'name' => 'section', 'id' => 'section', 'value' => 0, 'min' => 0, 'class' => 'form-control',
    ]), 'mb-3');

if ($latest) {
    echo $OUTPUT->notification(get_string('publishupdate', 'local_liamateriales'), \core\output\notification::NOTIFY_INFO);
}

// Acción principal: generar desde el Markdown.
echo html_writer::tag('p', get_string('publish_generate_hint', 'local_liamateriales'), ['class' => 'text-muted']);
echo html_writer::tag('p',
    html_writer::tag('button', get_string('publish_generate', 'local_liamateriales'), [
        'type' => 'submit', 'name' => 'mode', 'value' => 'generate', 'class' => 'btn btn-primary',
    ]) . ' ' . html_writer::link($returnurl, get_string('cancel'), ['class' => 'btn btn-secondary'])
);

// Alternativa: subir un fichero propio.
echo html_writer::start_tag('details', ['class' => 'mt-4']);
echo html_writer::tag('summary', get_string('publish_upload_alt', 'local_liamateriales'));
echo html_writer::div(html_writer::tag('label', get_string('publish_file', 'local_liamateriales'), ['for' => 'artifact'])
    . html_writer::empty_tag('input', [
        'type' => 'file', 'name' => 'artifact', 'id' => 'artifact', 'class' => 'form-control', 'accept' => '.h5p,.epub,.pdf',
    ]), 'mt-2 mb-2');
echo html_writer::tag('button', get_string('publish_upload_button', 'local_liamateriales'), [
    'type' => 'submit', 'name' => 'mode', 'value' => 'upload', 'class' => 'btn btn-outline-secondary',
]);
echo html_writer::end_tag('details');

echo html_writer::end_tag('form');
echo $OUTPUT->footer();
