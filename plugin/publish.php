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
 * También: **descargar** el fichero generado sin publicarlo, y subir un
 * fichero generado por cuenta propia (p. ej. con el LiaScript Exporter en
 * el navegador), con el mismo registro.
 *
 * Formatos: H5P, ePub y PDF (contenido web) con liaex; web estática y PDF
 * (LaTeX) con Pandoc + lector LiaScript (docs/generacion_web). La versión se elige
 * entre la última de la rama de edición del usuario y la oficial (`main`);
 * el SHA lo resuelve el servidor, no llega del formulario.
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_liamateriales\local\publisher;

/**
 * Respuesta JSON para las peticiones en segundo plano de la página y fin.
 *
 * @param int $status código HTTP
 * @param array $body cuerpo
 */
function local_liamateriales_publish_json(int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body);
    die;
}

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

// Versiones publicables: la última de la rama de edición del usuario (HEAD
// de edicion/<usuario>/<material>, no solo lo último que anotó el plugin) y
// la oficial (main). El SHA lo resuelve siempre el servidor.
$versions = [];
$session = $DB->get_record('local_liamateriales_editsess', [
    'userid' => $USER->id,
    'file' => $file,
    'closed_at' => null,
]);
$branches = $session && $session->branch !== 'main' ? ['branch' => $session->branch] : [];
$branches['main'] = 'main';
foreach ($branches as $key => $branchname) {
    try {
        $head = local_liamateriales_service_get('/edit/content', ['file' => $file, 'branch' => $branchname]);
    } catch (\moodle_exception $e) {
        // Rama borrada, fichero ausente o engine caído: esa versión no se ofrece.
        debugging("publish.php: no se pudo leer {$branchname}: " . $e->getMessage(), DEBUG_DEVELOPER);
        continue;
    }
    if (empty($head->sha) || strlen($head->sha) !== 40) {
        continue;
    }
    $date = local_liamateriales_commit_date($head->committed_at ?? null);
    $versions[$key] = (object) [
        'sha' => $head->sha,
        'branch' => $branchname,
        // Autor Git del último commit del material (autor de la web y del PDF LaTeX).
        'lastauthor' => isset($head->last_author) && is_string($head->last_author) ? $head->last_author : null,
        'label' => get_string($key === 'main' ? 'publish_version_main' : 'publish_version_branch', 'local_liamateriales', (object) [
            'branch' => $branchname,
            'date' => $date ? userdate((new \DateTimeImmutable($date))->getTimestamp()) : '—',
        ]),
    ];
}

$types = [];
foreach (publisher::types() as $type) {
    $types[$type] = get_string('publish_type_' . $type, 'local_liamateriales');
}

if (data_submitted() && confirm_sesskey()) {
    // ajax=1: petición en segundo plano de js/publish_progress.js, que
    // muestra el progreso; responde JSON (o el fichero, al descargar).
    // Sin JavaScript, el formulario funciona igual con redirecciones.
    $ajax = optional_param('ajax', 0, PARAM_BOOL);
    $mode = optional_param('mode', 'generate', PARAM_ALPHA);

    try {
        // PARAM_ALPHANUM: con PARAM_ALPHA «h5p» llegaba como «hp» (solo letras).
        $artifacttype = required_param('artifact_type', PARAM_ALPHANUM);
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
        // Rama de la versión publicada: el estado de «Publicados» se compara con ella.
        $branch = $versions[$versionkey]->branch;
        $author = publisher::author_name($versionkey, $versions[$versionkey]->lastauthor);

        if ($mode === 'download') {
            // Descargar: genera el fichero desde el Markdown sin publicarlo.
            $bytes = publisher::generate_artifact($file, $sha, $artifacttype, $author, publisher::module_title($courseid));
            $avisos = publisher::last_avisos();
            if ($avisos) {
                // Para que el JS los muestre tras la descarga (ASCII: json_encode escapa).
                header('X-Liamat-Avisos: ' . json_encode(array_slice($avisos, 0, 50)));
            }
            send_file(
                $bytes,
                basename($file, '.md') . '@' . substr($sha, 0, 8) . '.' . publisher::extension($artifacttype),
                0,
                0,
                true,
                true
            );
            die;
        }
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
                publisher::stage_artifact((int) $USER->id, $upload['name'], file_get_contents($upload['tmp_name']), $artifacttype),
                $name !== '' ? $name : publisher::default_name($courseid, $file, $artifacttype),
                publisher::reusable_cmid($courseid, $file, $artifacttype),
                $USER->username,
                $branch
            );
        } else {
            $cmid = publisher::publish_generated(
                $courseid,
                $section,
                $file,
                $artifacttype,
                $sha,
                $name !== '' ? $name : null,
                $USER,
                $branch,
                $author
            );
        }
    } catch (\moodle_exception $e) {
        if ($ajax) {
            local_liamateriales_publish_json(502, ['status' => 'error', 'message' => $e->getMessage()]);
        }
        redirect($pageurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }

    $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
    $target = new moodle_url('/mod/' . $cm->modname . '/view.php', ['id' => $cmid]);
    $message = get_string('publishok', 'local_liamateriales', format_string($cm->name));
    $avisos = $mode === 'upload' ? [] : publisher::last_avisos();
    if ($avisos) {
        // Avisos del lector LiaScript: se ven en la actividad publicada.
        \core\notification::warning(get_string('publish_avisos', 'local_liamateriales')
            . html_writer::alist(array_map('s', array_slice($avisos, 0, 20))));
    }
    if ($ajax) {
        // El aviso de éxito se muestra en la actividad a la que navega el JS.
        \core\notification::success($message);
        local_liamateriales_publish_json(200, [
            'status' => 'published', 'url' => $target->out(false), 'message' => $message, 'avisos' => $avisos,
        ]);
    }
    redirect($target, $message, null, \core\output\notification::NOTIFY_SUCCESS);
}

// Formulario (GET).
echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('material_publish', 'local_liamateriales'));

// Volver a la lista de materiales (el «Cancelar» del formulario queda al final).
echo html_writer::div(
    html_writer::link(
        $returnurl,
        $OUTPUT->pix_icon('t/left', '') . get_string('backto', 'moodle', get_string('materials', 'local_liamateriales')),
        ['class' => 'btn btn-outline-secondary btn-sm', 'id' => 'liamat-publish-back']
    ),
    'mb-3'
);

echo $OUTPUT->box_start('generalbox');
echo html_writer::tag('p', html_writer::tag('strong', get_string('material_file', 'local_liamateriales') . ': ')
    . html_writer::tag('code', s($file)));
$latest = publisher::latest_deploy($courseid, $file);
if ($latest) {
    echo html_writer::tag('p', html_writer::tag('strong', get_string('material_published_sha', 'local_liamateriales') . ': ')
        . html_writer::tag('code', s(substr($latest->git_commit, 0, 8)))
        . ' (' . s(publisher::label($latest->artifact_type)) . ', ' . userdate($latest->deployed_at) . ')');
}
echo $OUTPUT->box_end();

if (!$versions) {
    echo $OUTPUT->notification(get_string('publishnosha', 'local_liamateriales'), \core\output\notification::NOTIFY_WARNING);
    echo html_writer::link($returnurl, get_string('back'), ['class' => 'btn btn-secondary']);
    echo $OUTPUT->footer();
    exit;
}

// Progreso en el navegador (js/publish_progress.js): duración habitual de
// cada formato en el servidor (medida: H5P ~3 s, ePub/PDF ~18 s) y
// textos traducidos. Un porcentaje real no existe: liaex no informa de su
// avance, así que se muestra tiempo transcurrido frente al habitual.
$progress = [
    'estimates' => ['h5p' => 6, 'epub' => 25, 'pdf' => 30, 'web' => 4, 'pdfcontenido' => 20, 'upload' => 6],
    'strings' => [],
];
foreach (['progress_generate', 'progress_download', 'progress_upload', 'progress_elapsed',
        'progress_estimate', 'progress_slow', 'progress_keepopen', 'progress_published',
        'progress_downloaded', 'progress_error', 'progress_nofile', 'progress_avisos'] as $key) {
    $progress['strings'][$key] = get_string($key, 'local_liamateriales');
}
foreach (publisher::types() as $type) {
    $progress['strings']['type_' . $type] = publisher::label($type);
}
$PAGE->requires->js(new moodle_url('/local/liamateriales/js/publish_progress.js'));

echo html_writer::start_tag('form', [
    'method' => 'post',
    'enctype' => 'multipart/form-data',
    'action' => $pageurl->out(false),
    'class' => 'mform',
    'id' => 'liamat-publish-form',
    'data-progress' => json_encode($progress),
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
    ]) . ' ' . html_writer::tag('button', get_string('publish_download', 'local_liamateriales'), [
        'type' => 'submit', 'name' => 'mode', 'value' => 'download', 'class' => 'btn btn-outline-primary',
    ]) . ' ' . html_writer::link($returnurl, get_string('cancel'), ['class' => 'btn btn-secondary'])
);

// Alternativa: subir un fichero propio.
echo html_writer::start_tag('details', ['class' => 'mt-4']);
echo html_writer::tag('summary', get_string('publish_upload_alt', 'local_liamateriales'));
echo html_writer::div(html_writer::tag('label', get_string('publish_file', 'local_liamateriales'), ['for' => 'artifact'])
    . html_writer::empty_tag('input', [
        'type' => 'file', 'name' => 'artifact', 'id' => 'artifact', 'class' => 'form-control', 'accept' => '.h5p,.epub,.pdf,.zip',
    ]), 'mt-2 mb-2');
echo html_writer::tag('button', get_string('publish_upload_button', 'local_liamateriales'), [
    'type' => 'submit', 'name' => 'mode', 'value' => 'upload', 'class' => 'btn btn-outline-secondary',
]);
echo html_writer::end_tag('details');

echo html_writer::end_tag('form');
echo local_liamateriales_version_footer();
echo $OUTPUT->footer();
