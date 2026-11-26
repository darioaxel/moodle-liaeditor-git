<?php
// Tarea programada de limpieza (LIA-068).

/**
 * Limpieza diaria de tokens y sesiones de edición.
 *
 * - Borra los tokens caducados o revocados hace más de 24 h (códigos de
 *   apertura incluidos): la tabla no crece sin control.
 * - Cierra (``closed_at``) las sesiones de edición sin guardados ni
 *   aperturas en 7 días. La rama de edición sigue en Git; el profesor puede
 *   volver a abrir el material cuando quiera.
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_liamateriales\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Tarea programada de limpieza.
 */
class cleanup extends \core\task\scheduled_task {
    /** Antigüedad a partir de la que se borra un token caducado o revocado (s). */
    public const TOKEN_GRACE = DAYSECS;
    /** Inactividad tras la que se cierra una sesión de edición (s). */
    public const SESSION_IDLE = 7 * DAYSECS;

    /** Nombre visible en Administración → Tareas programadas. */
    public function get_name(): string {
        return get_string('taskcleanup', 'local_liamateriales');
    }

    /** Ejecuta la limpieza. */
    public function execute(): void {
        $now = time();
        $tokens = self::delete_old_tokens($now);
        $sessions = self::close_idle_sessions($now);
        mtrace("local_liamateriales: {$tokens} token(s) borrados, {$sessions} sesión(es) cerradas");
    }

    /**
     * Borra los tokens caducados o revocados hace más de 24 h.
     *
     * @return int tokens borrados
     */
    public static function delete_old_tokens(int $now): int {
        global $DB;
        $limit = $now - self::TOKEN_GRACE;
        $where = 'expires < :expired OR (revoked = 1 AND created < :created)';
        $params = ['expired' => $limit, 'created' => $limit];
        $count = $DB->count_records_select('local_liamateriales_token', $where, $params);
        $DB->delete_records_select('local_liamateriales_token', $where, $params);
        return $count;
    }

    /**
     * Cierra las sesiones abiertas sin actividad en 7 días.
     *
     * @return int sesiones cerradas
     */
    public static function close_idle_sessions(int $now): int {
        global $DB;
        $limit = $now - self::SESSION_IDLE;
        $where = 'closed_at IS NULL AND opened_at < :opened AND last_save < :saved';
        $params = ['opened' => $limit, 'saved' => $limit];
        $count = $DB->count_records_select('local_liamateriales_editsess', $where, $params);
        $DB->set_field_select('local_liamateriales_editsess', 'closed_at', $now, $where, $params);
        return $count;
    }
}
