<?php
// Librería del plugin: cliente del engine (server-to-server), tokens/sesiones y navegación.

/**
 * Library functions.
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib/filelib.php'); // send_file y clase \curl


/**
 * URL base del engine (configuración obligatoria en producción).
 */
function local_liamateriales_service_url(): string {
    return \local_liamateriales\local\engine_client::base_url();
}

/**
 * Petición server-to-server contra la API del engine (firmada, LIA-060).
 *
 * Comprueba el status HTTP: las respuestas de error del engine son JSON
 * ``{"detail": ...}`` (HTTPException de FastAPI) y se convierten en
 * ``moodle_exception``.
 *
 * @param string $method GET | POST
 * @param array<string, string> $query
 * @param array<string, mixed>|null $payload body JSON (solo POST)
 * @return mixed body decodificado
 */
function local_liamateriales_service_request(string $method, string $path, array $query = [], ?array $payload = null) {
    $result = \local_liamateriales\local\engine_client::send($method, $path, $query, $method === 'POST' ? ($payload ?? []) : null);
    if ($result->errno) {
        throw new \moodle_exception('servicerror', 'local_liamateriales', '', $result->error);
    }
    $data = json_decode($result->body);
    if ($result->status >= 400) {
        $detail = (is_object($data) && !empty($data->detail)) ? $data->detail : "HTTP {$result->status}";
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
    $result = \local_liamateriales\local\engine_client::send('POST', $path, [], $payload);
    if ($result->errno) {
        throw new \moodle_exception('servicerror', 'local_liamateriales', '', $result->error);
    }
    $data = json_decode($result->body);
    if ($data === null && $result->body !== 'null') {
        throw new \moodle_exception('servicerror', 'local_liamateriales', '', 'invalid JSON');
    }
    return [$result->status, $data];
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
 * Cabeceras de las respuestas de los scripts que usa el editor
 * (content.php, save.php, token.php, publish_api.php; LIA-063): CORS
 * restringido al origen del editor, sin Referer, sin sniffing y sin caché.
 */
function local_liamateriales_api_headers(): void {
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    $origin = local_liamateriales_cors_origin();
    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
}

/**
 * Responde el preflight CORS (OPTIONS) y termina; en otro método no hace nada.
 *
 * @param string $methods métodos admitidos además de OPTIONS (``POST``, ``GET, POST``)
 */
function local_liamateriales_api_preflight(string $methods): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'OPTIONS') {
        return;
    }
    local_liamateriales_api_headers();
    if (local_liamateriales_cors_origin() !== '') {
        header('Access-Control-Allow-Methods: ' . $methods . ', OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 600');
    }
    exit;
}

/**
 * Respuesta JSON de los scripts del editor y fin.
 *
 * @param array<string, mixed> $body
 * @param string[] $headers cabeceras adicionales («Nombre: valor»)
 */
function local_liamateriales_api_respond(int $status, array $body, array $headers = []): void {
    http_response_code($status);
    local_liamateriales_api_headers();
    header('Content-Type: application/json; charset=utf-8');
    foreach ($headers as $header) {
        header($header);
    }
    echo json_encode($body);
    exit;
}

/**
 * Respuesta 429 con ``Retry-After`` (LIA-064, LIA-066) y fin.
 */
function local_liamateriales_api_too_many(int $seconds): void {
    local_liamateriales_api_respond(429, [
        'status' => 'rate_limited',
        'message' => get_string('ratelimited', 'local_liamateriales', $seconds),
        'retry_after' => $seconds,
    ], ['Retry-After: ' . $seconds]);
}

/** Corta con 429 si la IP está bloqueada por tokens inválidos (LIA-066). */
function local_liamateriales_api_guard_ip(): void {
    $left = \local_liamateriales\local\rate_limiter::ip_blocked(getremoteaddr());
    if ($left !== null) {
        local_liamateriales_api_too_many($left);
    }
}

/** Anota un token inválido desde la IP de la petición (LIA-066). */
function local_liamateriales_api_token_failed(): void {
    \local_liamateriales\local\rate_limiter::token_failure(getremoteaddr());
}

/** Corta con 429 si el usuario supera el límite de la acción (LIA-064). */
function local_liamateriales_api_limit(string $action, int $userid): void {
    $wait = \local_liamateriales\local\rate_limiter::hit($action, $userid);
    if ($wait !== null) {
        local_liamateriales_api_too_many($wait);
    }
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
    if (preg_match('/^\s*<!--(.*?)-->/s', $markdown, $match, PREG_OFFSET_CAPTURE)) {
        [$header, $offset] = $match[1];
        // Sustituye un bloque previo si existe (claves raíz duplicadas no valen).
        $header = preg_replace('/liascript-lms:\n(  .*\n?)*/', '', $header) ?? $header;
        // Inyección reversible: el bloque va justo antes de `-->` sin tocar el
        // resto de la cabecera, de modo que el editor, al quitar sus líneas,
        // devuelve la cabecera byte a byte. substr_replace (no preg_replace)
        // para que `$1` o `\\` de la cabecera no se interpreten.
        $header .= (str_ends_with($header, "\n") ? '' : "\n") . $yaml;
        return substr_replace($markdown, $header, $offset, strlen($match[1][0]));
    }
    return "<!--\n" . $yaml . "-->\n\n" . $markdown;
}

/**
 * Fecha de commit válida para el editor, o null.
 *
 * Acepta solo ISO 8601 con zona horaria en una línea (formato de
 * `git --format=%cI`, p. ej. ``2026-11-20T09:05:00+01:00``). Cualquier otra
 * cosa se descarta: el campo ``committed_at`` es opcional en el contrato y,
 * además, así nunca puede colar un salto de línea en el bloque YAML.
 *
 * @param mixed $value valor recibido del engine
 */
function local_liamateriales_commit_date($value): ?string {
    if (!is_string($value)) {
        return null;
    }
    return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/D', $value) ? $value : null;
}

/**
 * Configuración del bloque ``liascript-lms`` (contrato editor↔plugin).
 *
 * ``committed_at`` va justo después de ``sha`` y solo si el engine dio una
 * fecha válida para ese commit; si no, la clave se omite.
 *
 * @param array<string, string|bool> $fields campos del bloque en su orden
 * @param mixed $committedat fecha del commit ``sha`` según el engine
 * @return array<string, string|bool>
 */
function local_liamateriales_lms_config(array $fields, $committedat): array {
    $date = local_liamateriales_commit_date($committedat);
    if ($date === null) {
        return $fields;
    }
    $config = [];
    foreach ($fields as $key => $value) {
        $config[$key] = $value;
        if ($key === 'sha') {
            $config['committed_at'] = $date;
        }
    }
    return $config;
}

/**
 * Cuerpo de la respuesta de guardado correcto (save.php, HTTP 200).
 *
 * @param \stdClass $result respuesta del engine (``new_sha`` y, opcional, ``committed_at``)
 * @param string $branch rama guardada
 * @param string $token token renovado
 * @param string $message mensaje para el usuario
 * @return array<string, string>
 */
function local_liamateriales_save_ok_body(\stdClass $result, string $branch, string $token, string $message): array {
    $body = [
        'status' => 'ok',
        'new_sha' => $result->new_sha,
        'branch' => $branch,
        'token' => $token,
        'message' => $message,
    ];
    $date = local_liamateriales_commit_date($result->committed_at ?? null);
    if ($date !== null) {
        $body['committed_at'] = $date;
    }
    return $body;
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
 * ¿Se mantiene la URL de apertura antigua (token en la query, para el fork
 * de LiveEditor)? Ajuste ``legacy_open_url``, desactivado por defecto (LIA-062).
 */
function local_liamateriales_legacy_open(): bool {
    return (bool) get_config('local_liamateriales', 'legacy_open_url');
}

/**
 * URL del editor que abre un material (LIA-062).
 *
 * Emite un **código de apertura** (60 s, un solo uso) y lo pasa en el
 * fragmento: ``<editor>/#open=<código>&src=<content.php>``. El fragmento no
 * llega a ningún servidor (ni a los registros ni al Referer); el editor lo
 * borra de la barra de direcciones y canjea el código con
 * ``POST content.php {code}``, que le devuelve el token de edición.
 *
 * Con ``legacy_open_url`` activo se mantiene el formato antiguo
 * (``?/open/file/<content.php?…&token=…>``) para el fork.
 */
function local_liamateriales_open_in_editor(int $userid, string $file, string $branch, int $courseid, bool $readonly): string {
    $content = new \moodle_url('/local/liamateriales/content.php');
    if (local_liamateriales_legacy_open()) {
        $token = \local_liamateriales\local\token_manager::issue($userid, $file, $branch, $courseid, $readonly);
        $content->params(['file' => $file, 'branch' => $branch, 'token' => $token->token]);
        return local_liamateriales_editor_open_url($content->out(false), $readonly);
    }
    $code = \local_liamateriales\local\token_manager::issue_open($userid, $file, $branch, $courseid, $readonly);
    return local_liamateriales_editor_url() . '/#open=' . rawurlencode($code->token)
        . '&src=' . rawurlencode($content->out(false));
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
        // Última apertura: la tarea de limpieza (LIA-068) cierra las sesiones
        // sin aperturas ni guardados en 7 días.
        $session->opened_at = time();
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
