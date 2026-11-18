<?php
// Tokens de un solo uso editor↔plugin (D9).

/**
 * Token manager: emisión, validación y renovación de tokens de edición.
 *
 * Las cookies de sesión de Moodle no viajan en el fetch cross-origin del
 * editor self-hosted (SameSite=Lax), así que la autenticación es un token
 * opaco ligado a usuario+fichero+rama, de corta duración y renovable. En la
 * tabla solo se guarda el hash sha256 del token, nunca el token en claro.
 *
 * @package    local_courseascode
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_courseascode\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Ciclo de vida de los tokens de un solo uso (LIA-011).
 */
class token_manager {
    /** Emite un token ligado a usuario+fichero+rama (y curso de origen, para publicar). */
    public static function issue(
        int $userid,
        string $file,
        string $branch,
        int $courseid = 0,
        ?int $ttl = null
    ): \stdClass {
        global $DB;
        $ttl = $ttl ?? self::ttl();
        $token = random_string(48);
        $record = (object) [
            'tokenhash' => hash('sha256', $token),
            'userid' => $userid,
            'courseid' => $courseid,
            'file' => $file,
            'branch' => $branch,
            'expires' => time() + $ttl,
            'revoked' => 0,
            'created' => time(),
        ];
        $record->id = $DB->insert_record('local_courseascode_token', $record);
        $record->token = $token; // Solo se devuelve en claro al emitir.
        return $record;
    }

    /**
     * Valida un token para una operación concreta (fichero y, si se indica,
     * rama). La rama es opcional: el contrato de publicación del editor no
     * la envía (`{file, sha, format, token}`).
     *
     * @return \stdClass fila del token (con userid y courseid)
     * @throws \moodle_exception si es inválido, caducó o no casa
     */
    public static function validate(string $token, string $file, ?string $branch = null): \stdClass {
        global $DB;
        $row = $DB->get_record('local_courseascode_token', ['tokenhash' => hash('sha256', $token)]);
        if (!$row || $row->revoked) {
            throw new \moodle_exception('tokeninvalid', 'local_courseascode');
        }
        if ((int) $row->expires < time()) {
            throw new \moodle_exception('tokenexpired', 'local_courseascode');
        }
        if ($row->file !== $file || ($branch !== null && $row->branch !== $branch)) {
            throw new \moodle_exception('tokenmismatch', 'local_courseascode');
        }
        return $row;
    }

    /**
     * Renueva un token válido: revoca el actual y emite uno nuevo con el
     * mismo usuario/fichero/rama (contrato C6 del roadmap del fork).
     */
    public static function refresh(string $token, string $file, string $branch): \stdClass {
        global $DB;
        $row = self::validate($token, $file, $branch);
        $DB->set_field('local_courseascode_token', 'revoked', 1, ['id' => $row->id]);
        return self::issue((int) $row->userid, $file, $branch);
    }

    /**
     * Revoca todos los tokens activos de un usuario para un fichero (p. ej.
     * al cerrar la sesión de edición desde el plugin).
     */
    public static function revoke(int $userid, string $file, string $branch): void {
        global $DB;
        $DB->set_field_select(
            'local_courseascode_token',
            'revoked',
            1,
            'userid = :userid AND file = :file AND branch = :branch AND revoked = 0',
            ['userid' => $userid, 'file' => $file, 'branch' => $branch]
        );
    }

    /** Duración del token en segundos (setting, default 15 min). */
    public static function ttl(): int {
        $ttl = (int) get_config('local_courseascode', 'token_ttl');
        return $ttl > 0 ? $ttl : 900;
    }
}
