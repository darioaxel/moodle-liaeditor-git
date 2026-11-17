<?php
// Paso "Publicar en el curso" (LIA-017, D6 fase 1).

/**
 * publish.php — subida del artefacto exportado en el navegador y creación
 * del módulo en el curso.
 *
 * Fase 1 del flujo LiaEditor: el profesor exporta el SCORM/H5P/ePub/PDF
 * desde el editor (exporter en navegador) y lo sube aquí; el plugin crea o
 * actualiza el módulo (mod_scorm / mod_h5pactivity / mod_resource) y
 * registra el despliegue curso↔SHA↔cmid (D6: sin este paso la subida
 * manual rompería la trazabilidad).
 *
 * @package    local_courseascode
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_courseascode\local\publisher;

$courseid = required_param('id', PARAM_INT);
$file = required_param('file', PARAM_PATH);
$context = context_course::instance($courseid);
require_login($courseid);
require_capability('local/courseascode:deploy', $context);

local_courseascode_validate_file($file);

$latest = publisher::latest_deploy($courseid, $file);
$session = $DB->get_record('local_courseascode_editsess', [
    'userid' => $USER->id,
    'file' => $file,
    'closed_at' => null,
]);

$PAGE->set_url(new moodle_url('/local/courseascode/publish.php', ['id' => $courseid, 'file' => $file]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('material_publish', 'local_courseascode'));
$PAGE->set_heading(format_string(get_course($courseid)->fullname));

$returnurl = new moodle_url('/local/courseascode/materials.php', ['id' => $courseid]);

if (data_submitted() && confirm_sesskey()) {
    $artifacttype = required_param('artifact_type', PARAM_ALPHA);
    $name = required_param('name', PARAM_TEXT);
    $section = optional_param('section', 0, PARAM_INT);
    $sha = optional_param('sha', $latest->git_commit ?? ($session->last_sha ?? ''), PARAM_RAW);

    if (strlen($sha) !== 40) {
        throw new \moodle_exception('publishnosha', 'local_courseascode');
    }
    $upload = $_FILES['artifact'] ?? null;
    if (!$upload || !is_uploaded_file($upload['tmp_name']) || $upload['error'] !== UPLOAD_ERR_OK) {
        throw new \moodle_exception('publishnofile', 'local_courseascode');
    }

    // El paquete pasa por el área de borrador del usuario: es el contrato
    // que esperan add_moduleinfo/update_moduleinfo (filemanager del form).
    $draftitemid = file_get_unused_draft_itemid();
    $fs = get_file_storage();
    $fs->create_file_from_pathname([
        'contextid' => \context_user::instance($USER->id)->id,
        'component' => 'user',
        'filearea' => 'draft',
        'itemid' => $draftitemid,
        'filepath' => '/',
        'filename' => clean_filename($upload['name']),
    ], $upload['tmp_name']);

    $cmid = publisher::publish(
        $courseid,
        $section,
        $file,
        $artifacttype,
        $sha,
        $draftitemid,
        $name,
        $latest ? (int) $latest->cmid : 0,
        $USER->username
    );

    redirect(
        $returnurl,
        get_string('publishok', 'local_courseascode', $name),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Formulario (GET).
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('material_publish', 'local_courseascode'));

$sha = $latest->git_commit ?? ($session->last_sha ?? '');
$defaultname = basename($file);
if ($latest) {
    $modinfo = get_fast_modinfo($courseid);
    $cms = $modinfo->get_cms();
    if (isset($cms[$latest->cmid])) {
        $defaultname = $cms[$latest->cmid]->name;
    }
}

echo html_writer::start_tag('form', [
    'method' => 'post',
    'enctype' => 'multipart/form-data',
    'action' => $PAGE->url->out(false),
    'class' => 'mform',
]);
echo html_writer::input_hidden_params($PAGE->url);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo $OUTPUT->box_start('generalbox');
echo html_writer::tag('p', html_writer::tag('strong', get_string('material_file', 'local_courseascode') . ': ')
    . html_writer::tag('code', s($file)));
echo html_writer::tag('p', html_writer::tag('strong', get_string('material_published_sha', 'local_courseascode') . ': ')
    . ($sha !== '' ? html_writer::tag('code', s($sha)) : '—'));
if ($latest) {
    echo $OUTPUT->notification(get_string('publishupdate', 'local_courseascode'), \core\output\notification::NOTIFY_INFO);
}
echo $OUTPUT->box_end();

echo html_writer::tag('p', html_writer::tag('label', get_string('publish_name', 'local_courseascode')
    . ' ' . html_writer::empty_tag('input', [
        'type' => 'text', 'name' => 'name', 'value' => $defaultname, 'required' => 'required',
        'class' => 'form-control', 'maxlength' => 255,
    ])));

echo html_writer::tag('p', html_writer::tag('label', get_string('publish_type', 'local_courseascode')
    . ' ' . html_writer::select([
        'scorm' => 'SCORM 2004 (mod_scorm)',
        'h5p' => 'H5P (mod_h5pactivity)',
        'epub' => 'ePub (recurso descargable)',
        'pdf' => 'PDF (recurso descargable)',
    ], 'artifact_type', 'scorm', false, ['class' => 'form-control'])));

echo html_writer::tag('p', html_writer::tag('label', get_string('publish_section', 'local_courseascode')
    . ' ' . html_writer::empty_tag('input', [
        'type' => 'number', 'name' => 'section', 'value' => 0, 'min' => 0, 'class' => 'form-control',
    ])));

echo html_writer::tag('p', html_writer::tag('label', get_string('publish_file', 'local_courseascode')
    . ' ' . html_writer::empty_tag('input', [
        'type' => 'file', 'name' => 'artifact', 'required' => 'required', 'class' => 'form-control',
        'accept' => '.zip,.h5p,.epub,.pdf',
    ])));

echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sha', 'value' => s($sha)]);
echo html_writer::tag('p',
    html_writer::tag('button', get_string('material_publish', 'local_courseascode'), [
        'type' => 'submit', 'class' => 'btn btn-primary',
    ]) . ' ' . html_writer::link($returnurl, get_string('cancel'), ['class' => 'btn btn-secondary'])
);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
