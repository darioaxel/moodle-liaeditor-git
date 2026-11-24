<?php
// Límites de uso de los scripts del editor (LIA-064, LIA-066).

/**
 * rate_limiter: ventanas deslizantes en la caché de aplicación (MUC).
 *
 * - Por usuario y acción (LIA-064): cada guardado es un commit y un push, y
 *   cada publicación un contenedor; un bucle (error o abuso) no debe
 *   saturar Git ni el servidor. Límites configurables en los ajustes del
 *   plugin (0 = sin límite).
 * - Por IP tras tokens inválidos (LIA-066): 20 fallos en 10 minutos
 *   bloquean esa IP 15 minutos.
 *
 * Al superar un límite, los scripts responden 429 con ``Retry-After``.
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_liamateriales\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Contadores de uso y bloqueos temporales.
 */
class rate_limiter {
    /** Acción → [ajuste con el máximo, máximo por defecto, ventana en segundos]. */
    public const LIMITS = [
        'content' => ['ratelimit_content', 30, 60],
        'save' => ['ratelimit_save', 20, 60],
        'token' => ['ratelimit_token', 6, 60],
        'publish' => ['ratelimit_publish', 5, 600],
    ];

    /** Fallos de token por IP antes del bloqueo, ventana y duración del bloqueo (s). */
    public const IP_FAILURES = 20;
    /** Ventana de los fallos de token (s). */
    public const IP_WINDOW = 600;
    /** Duración del bloqueo de la IP (s). */
    public const IP_BLOCK = 900;

    /** Reloj (inyectable en tests). */
    public static ?\Closure $clock = null;

    private static function now(): int {
        return self::$clock ? (int) (self::$clock)() : time();
    }

    private static function cache(): \cache {
        return \cache::make('local_liamateriales', 'ratelimit');
    }

    /** Máximo configurado para una acción (0 = sin límite). */
    public static function limit(string $action): int {
        [$setting, $default] = self::LIMITS[$action];
        $value = get_config('local_liamateriales', $setting);
        return ($value === false || $value === '') ? $default : max(0, (int) $value);
    }

    /**
     * Registra un uso; si supera el límite, devuelve los segundos que hay que
     * esperar (y no lo registra). ``null`` = permitido.
     */
    public static function hit(string $action, int $userid): ?int {
        $max = self::limit($action);
        if ($max === 0) {
            return null;
        }
        $window = self::LIMITS[$action][2];
        $key = $action . '_' . $userid;
        $now = self::now();
        $hits = array_values(array_filter((array) self::cache()->get($key), fn($t) => $t > $now - $window));
        if (count($hits) >= $max) {
            return max(1, $hits[0] + $window - $now);
        }
        $hits[] = $now;
        self::cache()->set($key, $hits);
        return null;
    }

    /** Clave de una IP (las IPv6 tienen «:», no válido en claves simples). */
    private static function ipkey(string $prefix, string $ip): string {
        return $prefix . '_' . sha1($ip);
    }

    /** Segundos de bloqueo que le quedan a la IP, o ``null`` si no está bloqueada. */
    public static function ip_blocked(string $ip): ?int {
        $until = (int) self::cache()->get(self::ipkey('ipblock', $ip));
        $left = $until - self::now();
        return $left > 0 ? $left : null;
    }

    /** Anota un token inválido desde la IP; al llegar al umbral, la bloquea. */
    public static function token_failure(string $ip): void {
        $now = self::now();
        $key = self::ipkey('ipfail', $ip);
        $fails = array_values(array_filter((array) self::cache()->get($key), fn($t) => $t > $now - self::IP_WINDOW));
        $fails[] = $now;
        if (count($fails) >= self::IP_FAILURES) {
            self::cache()->set(self::ipkey('ipblock', $ip), $now + self::IP_BLOCK);
            $fails = [];
        }
        self::cache()->set($key, $fails);
    }
}
