<?php
// Librería del plugin: cliente del engine (server-to-server), tokens/sesiones y navegación.

/**
 * Library functions.
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib/filelib.php'); // clase \curl (cliente server-to-server)

/**
 * URL base del engine (configuración obligatoria en producción).
 */
function local_liamateriales_service_url(): string {
    global $CFG;
    if (empty($CFG->local_liamateriales_service_url)) {
        throw new \moodle_exception('servicenotconfigured', 'local_liamateriales');
    }
    return rtrim($CFG->local_liamateriales_service_url, '/');
}

/**
 * Petición server-to-server contra la API del engine.
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
function local_liamateriales_service_request(string $method, string $path, array $query = [], ?array $payload = null) {
    $url = local_liamateriales_service_url() . $path . ($query ? '?' . http_build_query($query) : '');
    $headers = ['Accept: application/json'];
    $curl = new \curl();
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/json';
        $response = $curl->post($url, json_encode($payload ?? []), ['CURLOPT_HTTPHEADER' => $headers]);
    } else {
        $response = $curl->get($url, [], ['CURLOPT_HTTPHEADER' => $headers]);
    }
    if ($curl->get_errno()) {
        throw new \moodle_exception('servicerror', 'local_liamateriales', '', $curl->error);
    }
    $status = (int) ($curl->get_info()['http_code'] ?? 0);
    $data = json_decode($response);
    if ($status >= 400) {
        $detail = (is_object($data) && !empty($data->detail)) ? $data->detail : "HTTP {$status}";
        throw new \moodle_exception('servicerror', 'local_liamateriales', '', $detail);
    }
    if ($data === null) {
        throw new \moodle_exception('servicerror', 'local_liamateriales', '', 'invalid JSON');
    }
    return $data;
}

/**
 * GET server-to-server contra la API del engine.
 *
 * @param array<string, string> $query
 * @return mixed
 */
function local_liamateriales_service_get(string $path, array $query = []) {
    return local_liamateriales_service_request('GET', $path, $query);
}

/**
 * POST server-to-server contra la API del engine.
 *
 * @param array<string, mixed> $payload
 * @return mixed
 */
function local_liamateriales_service_post(string $path, array $payload) {
    return local_liamateriales_service_request('POST', $path, [], $payload);
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
function local_liamateriales_service_post_full(string $path, array $payload): array {
    $url = local_liamateriales_service_url() . $path;
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    $curl = new \curl();
    $response = $curl->post($url, json_encode($payload), ['CURLOPT_HTTPHEADER' => $headers]);
    if ($curl->get_errno()) {
        throw new \moodle_exception('servicerror', 'local_liamateriales', '', $curl->error);
    }
    $status = (int) ($curl->get_info()['http_code'] ?? 0);
    $data = json_decode($response);
    if ($data === null && $response !== 'null') {
        throw new \moodle_exception('servicerror', 'local_liamateriales', '', 'invalid JSON');
    }
    return [$status, $data];
}

/**
 * URL base del editor LiaEdit self-hosted (setting, LIA-012); vacía si no configurado.
 */
function local_liamateriales_editor_url(): string {
    $url = (string) get_config('local_liamateriales', 'liaeditor_url');
    return rtrim($url, '/');
}

/**
 * Origen CORS permitido en content.php (setting); por defecto el origen del editor.
 */
function local_liamateriales_cors_origin(): string {
    $origin = (string) get_config('local_liamateriales', 'cors_origin');
    if ($origin !== '') {
        return $origin;
    }
    $parts = parse_url(local_liamateriales_editor_url());
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
function local_liamateriales_inject_lms_block(string $markdown, array $config): string {
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
 * Ruta editable del fork (``?/open/file/:fileUrl``, C1). OJO con la
 * codificación: el router del fork captura ``:fileUrl`` de la query **en
 * crudo, sin decodeURIComponent** — si el contenido va codificado, el
 * fetch revienta como URL relativa y el SPA devuelve su propio index.html
 * como "documento". El formato correcto es envolver toda la ruta en una
 * codificación (``?%2F...``): el fork detecta ese prefijo y decodifica la
 * query completa una vez, dejando la URL de content.php lista para fetch.
 *
 * El modo solo lectura NO usa la ruta ``?/show/file/`` de upstream (que
 * renderiza el curso sin mostrar el fuente): se indica con
 * ``readonly: true`` en el bloque liascript-lms y el fork muestra el badge
 * "Solo lectura" en lugar del botón Guardar (C4).
 */
function local_liamateriales_editor_open_url(string $contenturl, bool $readonly): string {
    return local_liamateriales_editor_url() . '/?' . rawurlencode('/open/file/' . $contenturl);
}

/**
 * Crea o actualiza la sesión de edición del usuario (LIA-010, D3).
 *
 * Una sesión activa (closed_at NULL) por usuario y fichero; si ya existe se
 * reabre con la nueva rama. Devuelve el id de la sesión.
 */
function local_liamateriales_touch_session(int $userid, string $file, string $branch, string $lastsha = ''): int {
    global $DB;
    $session = $DB->get_record('local_liamateriales_editsess', [
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
        $DB->update_record('local_liamateriales_editsess', $session);
        return (int) $session->id;
    }
    return (int) $DB->insert_record('local_liamateriales_editsess', (object) [
        'userid' => $userid,
        'file' => $file,
        'branch' => $branch,
        'opened_at' => time(),
        'last_save' => $lastsha !== '' ? time() : 0,
        'closed_at' => null,
        'last_sha' => $lastsha,
    ]);
}

/**
 * Registra el guardado en la sesión activa del usuario (LIA-014, D3).
 */
function local_liamateriales_record_save(int $userid, string $file, string $branch, string $newsha): void {
    global $DB;
    $session = $DB->get_record('local_liamateriales_editsess', [
        'userid' => $userid,
        'file' => $file,
        'closed_at' => null,
    ]);
    if ($session) {
        $session->branch = $branch;
        $session->last_sha = $newsha;
        $session->last_save = time();
        $DB->update_record('local_liamateriales_editsess', $session);
    } else {
        local_liamateriales_touch_session($userid, $file, $branch, $newsha);
    }
}

/**
 * Validación de ruta de fichero en el lado plugin (espejo de las reglas del
 * engine: relativa, sin traversal, extensión .md). Lanza moodle_exception.
 */
function local_liamateriales_validate_file(string $file): string {
    if ($file === '' || $file[0] === '/' || str_contains($file, '..') || str_contains($file, "\0")) {
        throw new \moodle_exception('invalidfile', 'local_liamateriales', '', $file);
    }
    if (!str_ends_with(strtolower($file), '.md')) {
        throw new \moodle_exception('invalidfile', 'local_liamateriales', '', $file);
    }
    if (strlen($file) > 255) {
        throw new \moodle_exception('invalidfile', 'local_liamateriales', '', $file);
    }
    return $file;
}

/**
 * Etiqueta traducida de un estado de material (``up_to_date`` /
 * ``update_available`` del engine, o ``error``) para la UI.
 */
function local_liamateriales_state_label(string $state): string {
    $key = 'state_' . strtolower($state);
    return get_string_manager()->string_exists($key, 'local_liamateriales')
        ? get_string($key, 'local_liamateriales')
        : $state;
}

/**
 * Clase Bootstrap del badge de un estado de material.
 */
function local_liamateriales_state_class(string $state): string {
    return match (strtolower($state)) {
        'up_to_date' => 'success',
        'update_available' => 'warning',
        'error' => 'danger',
        default => 'secondary',
    };
}

/**
 * Navegación: entrada "Materiales Editables" en el bloque de administración
 * del curso.
 */
function local_liamateriales_extend_navigation_course(\navigation_node $parent, \stdClass $course, \context_course $context): void {
    if (has_capability('local/liamateriales:viewcatalog', $context)) {
        $parent->add(
            get_string('pluginname', 'local_liamateriales'),
            new \moodle_url('/local/liamateriales/materials.php', ['id' => $course->id]),
            navigation_node::TYPE_SETTING,
            null,
            'local_liamateriales_materials',
            new \pix_icon('i/edit', '')
        );
    }
}
