<?php
// Cliente HTTP del engine con firma HMAC (LIA-060).

/**
 * engine_client: único punto por el que el plugin habla con el engine.
 *
 * Firma cada petición con HMAC-SHA256 sobre el texto canónico
 * ``MÉTODO\nRUTA[?QUERY]\nMARCA_DE_TIEMPO\nNONCE\nSHA256(CUERPO)`` y la envía en
 * ``X-Liamat-Timestamp``, ``X-Liamat-Nonce`` y ``X-Liamat-Signature``. El
 * nonce (aleatorio) evita que dos peticiones idénticas en el mismo segundo
 * —el estado de un material publicado en dos formatos— tengan la misma firma
 * y el engine rechace la segunda como réplica (mismo formato que
 * ``materiales_liascript.auth`` del engine). El secreto compartido sale de
 * ``$CFG->local_liamateriales_service_secret`` (config.php, preferente) o del
 * ajuste «Secreto compartido con el engine»; sin secreto no se firma (el
 * engine solo lo acepta arrancado en modo desarrollo).
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_liamateriales\local;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/filelib.php');

/**
 * Peticiones firmadas al engine.
 */
class engine_client {
    /** Cabeceras de la firma. */
    public const TIMESTAMP_HEADER = 'X-Liamat-Timestamp';
    /** Cabecera con la firma HMAC en hexadecimal. */
    public const SIGNATURE_HEADER = 'X-Liamat-Signature';
    /** Cabecera con el nonce aleatorio (hexadecimal) que entra en la firma. */
    public const NONCE_HEADER = 'X-Liamat-Nonce';

    /** Secreto compartido con el engine ('' si no está configurado). */
    public static function secret(): string {
        global $CFG;
        if (!empty($CFG->local_liamateriales_service_secret)) {
            return (string) $CFG->local_liamateriales_service_secret;
        }
        return trim((string) get_config('local_liamateriales', 'service_secret'));
    }

    /**
     * URL base del engine (``$CFG->local_liamateriales_service_url``, obligatoria).
     *
     * @throws \moodle_exception si no está configurada
     */
    public static function base_url(): string {
        global $CFG;
        if (empty($CFG->local_liamateriales_service_url)) {
            throw new \moodle_exception('servicenotconfigured', 'local_liamateriales');
        }
        return rtrim($CFG->local_liamateriales_service_url, '/');
    }

    /** URL completa de una ruta del engine con su query. */
    public static function url(string $path, array $query = []): string {
        return self::base_url() . $path . ($query ? '?' . http_build_query($query) : '');
    }

    /** Texto que se firma. */
    public static function canonical(string $method, string $target, string $timestamp, string $nonce, string $body): string {
        return strtoupper($method) . "\n" . $target . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
    }

    /**
     * Cabeceras de firma para una petición (vacío si no hay secreto).
     *
     * @param string $url URL completa (se firma su ruta y su query)
     * @param int|null $time marca de tiempo (tests; por defecto, ahora)
     * @param string|null $nonce nonce (tests; por defecto, 16 bytes aleatorios)
     * @return string[] cabeceras «Nombre: valor»
     */
    public static function auth_headers(string $secret, string $method, string $url, string $body,
            ?int $time = null, ?string $nonce = null): array {
        if ($secret === '') {
            return [];
        }
        $parts = parse_url($url);
        $target = ($parts['path'] ?? '/') . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
        $timestamp = (string) ($time ?? time());
        $nonce ??= bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', self::canonical($method, $target, $timestamp, $nonce, $body), $secret);
        return [
            self::TIMESTAMP_HEADER . ': ' . $timestamp,
            self::NONCE_HEADER . ': ' . $nonce,
            self::SIGNATURE_HEADER . ': ' . $signature,
        ];
    }

    /**
     * Envía una petición firmada al engine.
     *
     * @param string $method GET | POST
     * @param string $path ruta del engine (``/edit/save``…)
     * @param array<string, string> $query parámetros de la query
     * @param array<string, mixed>|null $payload cuerpo JSON (POST)
     * @param string $accept tipo aceptado
     * @param array<string, mixed> $curlopts opciones de curl (timeouts)
     * @return \stdClass {status:int, body:string, errno:int, error:string, headers:array}
     */
    public static function send(
        string $method,
        string $path,
        array $query = [],
        ?array $payload = null,
        string $accept = 'application/json',
        array $curlopts = []
    ): \stdClass {
        $url = self::url($path, $query);
        $body = $payload === null ? '' : json_encode($payload);
        $curl = new \curl();
        $curl->setHeader('Accept: ' . $accept);
        if ($payload !== null) {
            $curl->setHeader('Content-Type: application/json');
        }
        foreach (self::auth_headers(self::secret(), $method, $url, $body) as $header) {
            $curl->setHeader($header);
        }
        if ($curlopts) {
            $curl->setopt($curlopts);
        }
        $response = $method === 'POST' ? $curl->post($url, $body) : $curl->get($url);
        return (object) [
            'status' => (int) ($curl->get_info()['http_code'] ?? 0),
            'body' => is_string($response) ? $response : '',
            'errno' => (int) $curl->get_errno(),
            'error' => (string) $curl->error,
            'headers' => (array) $curl->getResponse(),
        ];
    }
}
