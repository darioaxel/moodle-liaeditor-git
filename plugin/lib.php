<?php
// Librería del plugin: cliente del servicio (server-to-server) e inyección CSS.

/**
 * Library functions.
 *
 * @package    local_courseascode
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * URL base del servicio courseascode (configuración obligatoria en producción).
 */
function local_courseascode_service_url(): string {
    global $CFG;
    if (empty($CFG->local_courseascode_service_url)) {
        throw new \moodle_exception('servicenotconfigured', 'local_courseascode');
    }
    return rtrim($CFG->local_courseascode_service_url, '/');
}

/**
 * Petición server-to-server contra la API del servicio (§5.4).
 *
 * Comprueba el status HTTP: las respuestas de error del engine son JSON
 * ``{"detail": ...}`` (HTTPException de FastAPI) y se convierten en
 * ``moodle_exception`` accionable.
 *
 * @param string $method GET | POST
 * @param array<string, string> $query
 * @param array<string, mixed>|null $payload body JSON (solo POST)
 * @return mixed body decodificado
 */
function local_courseascode_service_request(string $method, string $path, array $query = [], ?array $payload = null) {
    global $CFG;
    $url = local_courseascode_service_url() . $path . ($query ? '?' . http_build_query($query) : '');
    $headers = [
        'X-CourseAsCode-User: ' . ($CFG->local_courseascode_service_token ?? ''),
        'Accept: application/json',
    ];
    $curl = new \curl();
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/json';
        $response = $curl->post($url, json_encode($payload ?? []), ['CURLOPT_HTTPHEADER' => $headers]);
    } else {
        $response = $curl->get($url, [], ['CURLOPT_HTTPHEADER' => $headers]);
    }
    if ($curl->get_errno()) {
        throw new \moodle_exception('servicerror', 'local_courseascode', '', $curl->error);
    }
    $status = (int) ($curl->get_info()['http_code'] ?? 0);
    $data = json_decode($response);
    if ($status >= 400) {
        $detail = (is_object($data) && !empty($data->detail)) ? $data->detail : "HTTP {$status}";
        throw new \moodle_exception('servicerror', 'local_courseascode', '', $detail);
    }
    if ($data === null) {
        throw new \moodle_exception('servicerror', 'local_courseascode', '', 'invalid JSON');
    }
    return $data;
}

/**
 * GET server-to-server contra la API del servicio (§5.4).
 *
 * @param array<string, string> $query
 * @return mixed
 */
function local_courseascode_service_get(string $path, array $query = []) {
    return local_courseascode_service_request('GET', $path, $query);
}

/**
 * POST server-to-server contra la API del servicio (deploy/plan, §12.3, §17).
 *
 * @param array<string, mixed> $payload
 * @return mixed
 */
function local_courseascode_service_post(string $path, array $payload) {
    return local_courseascode_service_request('POST', $path, [], $payload);
}

/**
 * POST al servicio devolviendo (status, data) sin lanzar en 4xx (LIA-014).
 *
 * ``save.php`` necesita propagar al editor el 409 de conflicto con su body
 * JSON; la excepción de ``service_request`` lo perdería. Solo lanza en error
 * de red o JSON inválido.
 *
 * @return array{0: int, 1: mixed} (HTTP status, body decodificado)
 */
function local_courseascode_service_post_full(string $path, array $payload): array {
    $url = local_courseascode_service_url() . $path;
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    $curl = new \curl();
    $response = $curl->post($url, json_encode($payload), ['CURLOPT_HTTPHEADER' => $headers]);
    if ($curl->get_errno()) {
        throw new \moodle_exception('servicerror', 'local_courseascode', '', $curl->error);
    }
    $status = (int) ($curl->get_info()['http_code'] ?? 0);
    $data = json_decode($response);
    if ($data === null && $response !== 'null') {
        throw new \moodle_exception('servicerror', 'local_courseascode', '', 'invalid JSON');
    }
    return [$status, $data];
}

/**
 * URL pública del servicio (para enlaces que abre el navegador del usuario:
 * preview, CSS). Si no se define, se usa la URL server-to-server.
 */
function local_courseascode_service_public_url(): string {
    global $CFG;
    if (!empty($CFG->local_courseascode_service_public_url)) {
        return rtrim($CFG->local_courseascode_service_public_url, '/');
    }
    return local_courseascode_service_url();
}

/**
 * URL base del editor LiaEdit self-hosted (setting, LIA-012); vacía si no configurado.
 */
function local_courseascode_editor_url(): string {
    $url = (string) get_config('local_courseascode', 'liaeditor_url');
    return rtrim($url, '/');
}

/**
 * Origen CORS permitido en content.php (setting); por defecto el origen del editor.
 */
function local_courseascode_cors_origin(): string {
    $origin = (string) get_config('local_courseascode', 'cors_origin');
    if ($origin !== '') {
        return $origin;
    }
    $parts = parse_url(local_courseascode_editor_url());
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    return $parts['scheme'] . '://' . $parts['host'] . $port;
}

/**
 * Inyecta (o sustituye) el bloque ``liascript-lms`` en la cabecera LiaScript.
 *
 * El bloque vive dentro del comentario HTML de cabecera del documento
 * (roadmap del fork §C2); si el documento no tiene comentario de cabecera se
 * crea uno. El fork lo lee para saber dónde guardar y lo elimina antes de
 * importar el documento, así que nunca se commitea de vuelta a Git.
 *
 * @param string $markdown contenido original
 * @param array<string, string|bool> $config campos del bloque (yaml plano)
 */
function local_courseascode_inject_lms_block(string $markdown, array $config): string {
    $yaml = "liascript-lms:\n";
    foreach ($config as $key => $value) {
        $rendered = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        $yaml .= "  {$key}: {$rendered}\n";
    }
    if (preg_match('/^\s*<!--(.*?)-->/s', $markdown, $match)) {
        $header = $match[1];
        // Sustituye un bloque previo si existe (claves raíz duplicadas no valen).
        $header = preg_replace('/liascript-lms:\n(  .*\n?)*/', '', $header) ?? $header;
        $header = rtrim($header) . "\n\n" . $yaml . "\n";
        $result = preg_replace('/^\s*<!--.*?-->/s', '<!--' . $header . '-->', $markdown, 1);
        return $result ?? $markdown;
    }
    return "<!--\n" . $yaml . "-->\n\n" . $markdown;
}

/**
 * URL de apertura del editor para una content.php dada (LIA-016).
 *
 * Ruta editable del fork (``?/open/file/:fileUrl``, cambio C1 del roadmap)
 * o de solo lectura de upstream (``?/show/file/:fileUrl``) para la vista
 * previa sin edición.
 */
function local_courseascode_editor_open_url(string $contenturl, bool $readonly): string {
    $base = local_courseascode_editor_url();
    $route = $readonly ? '/?/show/file/' : '/?/open/file/';
    return $base . $route . rawurlencode($contenturl);
}

/**
 * Crea o actualiza la sesión de edición del usuario (LIA-010, D3).
 *
 * Una sesión activa (closed_at NULL) por usuario y fichero; si ya existe se
 * reabre con la nueva rama. Devuelve el id de la sesión.
 */
function local_courseascode_touch_session(int $userid, string $file, string $branch, string $lastsha = ''): int {
    global $DB;
    $session = $DB->get_record('local_courseascode_editsess', [
        'userid' => $userid,
        'file' => $file,
        'closed_at' => null,
    ]);
    if ($session) {
        $session->branch = $branch;
        if ($lastsha !== '') {
            $session->last_sha = $lastsha;
            $session->last_save = time();
        }
        $DB->update_record('local_courseascode_editsess', $session);
        return (int) $session->id;
    }
    return (int) $DB->insert_record('local_courseascode_editsess', (object) [
        'userid' => $userid,
        'file' => $file,
        'branch' => $branch,
        'opened_at' => time(),
        'last_save' => 0,
        'closed_at' => null,
        'last_sha' => $lastsha,
    ]);
}

/**
 * Registra el guardado en la sesión activa del usuario (LIA-014, D3).
 */
function local_courseascode_record_save(int $userid, string $file, string $branch, string $newsha): void {
    global $DB;
    $session = $DB->get_record('local_courseascode_editsess', [
        'userid' => $userid,
        'file' => $file,
        'closed_at' => null,
    ]);
    if ($session) {
        $session->branch = $branch;
        $session->last_sha = $newsha;
        $session->last_save = time();
        $DB->update_record('local_courseascode_editsess', $session);
    } else {
        local_courseascode_touch_session($userid, $file, $branch, $newsha);
    }
}

/**
 * Validación de ruta de fichero en el lado plugin (espejo de las reglas del
 * engine: relativa, sin traversal, extensión .md). Lanza moodle_exception.
 */
function local_courseascode_validate_file(string $file): string {
    if ($file === '' || $file[0] === '/' || str_contains($file, '..') || str_contains($file, "\0")) {
        throw new \moodle_exception('invalidfile', 'local_courseascode', '', $file);
    }
    if (!str_ends_with(strtolower($file), '.md')) {
        throw new \moodle_exception('invalidfile', 'local_courseascode', '', $file);
    }
    if (strlen($file) > 255) {
        throw new \moodle_exception('invalidfile', 'local_courseascode', '', $file);
    }
    return $file;
}

/**
 * URL de la preview pública del servicio (ENG-044): mismo renderer que el deploy.
 * La abre el navegador del profesor: usa la URL pública del servicio.
 */
function local_courseascode_preview_url(string $source, string $book, ?string $ref = null): string {
    $url = local_courseascode_service_public_url() . "/catalog/{$source}/{$book}/preview";
    return $ref ? $url . '?ref=' . rawurlencode($ref) : $url;
}

/**
 * Último despliegue registrado en el curso por cada Book (binding §9).
 *
 * @return \stdClass[] filas de local_courseascode_deploy (la más reciente por source+content_id)
 */
function local_courseascode_course_deploys(int $courseid): array {
    global $DB;
    $rows = $DB->get_records('local_courseascode_deploy', ['courseid' => $courseid], 'deployed_at DESC, id DESC');
    $latest = [];
    foreach ($rows as $row) {
        $key = $row->source . '/' . $row->content_id;
        if (!isset($latest[$key])) {
            $latest[$key] = $row;
        }
    }
    return array_values($latest);
}

/**
 * Estado §19 de un Book en el curso, tolerante a fallos: si el servicio no
 * puede calcularlo devuelve un objeto ERROR con el detalle (la tabla del
 * panel debe seguir mostrando el resto de Books).
 */
function local_courseascode_book_state(int $courseid, string $source, string $book): \stdClass {
    try {
        return local_courseascode_service_get('/deployment/status', [
            'course_id' => (string) $courseid,
            'source' => $source,
            'book_id' => $book,
        ]);
    } catch (\moodle_exception $e) {
        return (object) [
            'state' => 'ERROR',
            'severity' => null,
            'version' => null,
            'git_commit' => '',
            'detail' => $e->getMessage(),
        ];
    }
}

/**
 * Etiqueta traducida de un DeploymentState (§19) para la UI.
 */
function local_courseascode_state_label(string $state): string {
    $key = 'state_' . strtolower($state);
    return get_string_manager()->string_exists($key, 'local_courseascode')
        ? get_string($key, 'local_courseascode')
        : $state;
}

/**
 * Clase Bootstrap del badge/alerta de un estado (§19) con su severidad (§11).
 */
function local_courseascode_state_class(string $state, ?string $severity = null): string {
    if ($severity === 'critical') {
        return 'danger';
    }
    return match ($state) {
        'UP_TO_DATE' => 'success',
        'UPDATE_AVAILABLE' => 'warning',
        'DRIFT_DETECTED', 'ERROR' => 'danger',
        'DEPLOYING' => 'info',
        default => 'secondary',
    };
}

/**
 * Inyección del CSS del tema en las páginas del Book (§5.3d).
 * El CSS lo sirve el engine como asset temático; aquí solo se enlaza.
 */
function local_courseascode_before_standard_html_head(): string {
    global $PAGE;
    if ($PAGE->pagetype !== 'mod-book-view') {
        return '';
    }
    try {
        return \html_writer::tag('link', '', [
            'rel' => 'stylesheet',
            'href' => local_courseascode_service_public_url() . '/static/theme-default.css',
        ]);
    } catch (\moodle_exception $e) {
        return ''; // Servicio no configurado: el campus sigue funcionando.
    }
}

/**
 * Navegación: entrada "Course as Code" en el bloque de administración del curso.
 */
function local_courseascode_extend_navigation_course(\navigation_node $parent, \stdClass $course, \context_course $context): void {
    if (has_capability('local/courseascode:viewcatalog', $context)) {
        $parent->add(
            get_string('pluginname', 'local_courseascode'),
            new \moodle_url('/local/courseascode/catalog.php', ['id' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'local_courseascode',
            new \pix_icon('i/book', '')
        );
        // Flujo LiaEditor (v1): página "Materiales" del curso.
        $parent->add(
            get_string('materials', 'local_courseascode'),
            new \moodle_url('/local/courseascode/materials.php', ['id' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'local_courseascode_materials',
            new \pix_icon('i/edit', '')
        );
    }
    if (has_capability('local/courseascode:deploy', $context)) {
        $node = $parent->get('local_courseascode') ?: $parent;
        $node->add(
            get_string('admin', 'local_courseascode'),
            new \moodle_url('/local/courseascode/admin.php', ['id' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'local_courseascode_admin',
            new \pix_icon('i/settings', '')
        );
    }
}

/**
 * Sirve los assets subidos por el engine (upload_asset).
 * Área local_courseascode/asset con itemid = bookid, en el contexto del curso.
 */
function local_courseascode_pluginfile(
    ?stdClass $course,
    ?stdClass $cm,
    context $context,
    string $filearea,
    array $args,
    bool $forcedownload,
    array $options = []
): bool {
    if ($context->contextlevel !== CONTEXT_COURSE || $filearea !== 'asset') {
        return false;
    }
    $itemid = (int) array_shift($args);
    $filename = array_pop($args);
    $filepath = '/' . implode('/', $args) . '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_courseascode', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, $forcedownload, $options);
}
