<?php
// Tarea programada de limpieza (LIA-068).

namespace local_liamateriales;

defined('MOODLE_INTERNAL') || die();

use local_liamateriales\task\cleanup;

/**
 * Tests de la tarea de limpieza.
 *
 * @package    local_liamateriales
 * @covers     \local_liamateriales\task\cleanup
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cleanup_task_test extends \advanced_testcase {

    private function token(string $hash, int $expires, int $revoked, int $created): void {
        global $DB;
        $DB->insert_record('local_liamateriales_token', (object) [
            'tokenhash' => str_pad($hash, 64, '0'), 'userid' => 2, 'courseid' => 0, 'readonly' => 0,
            'kind' => 'edit', 'file' => 'a.md', 'branch' => 'main',
            'expires' => $expires, 'revoked' => $revoked, 'created' => $created,
        ]);
    }

    private function session(string $file, int $opened, int $saved): int {
        global $DB;
        return (int) $DB->insert_record('local_liamateriales_editsess', (object) [
            'userid' => 2, 'file' => $file, 'branch' => 'edicion/x', 'opened_at' => $opened,
            'last_save' => $saved, 'closed_at' => null, 'last_sha' => '',
        ]);
    }

    public function test_borra_tokens_viejos_y_conserva_los_recientes(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $this->token('a', $now - 2 * DAYSECS, 0, $now - 2 * DAYSECS);  // Caducado hace 2 días: fuera.
        $this->token('b', $now + 600, 1, $now - 2 * DAYSECS);          // Revocado y antiguo: fuera.
        $this->token('c', $now - 3600, 0, $now - 3600);                // Caducado hace 1 h: se queda.
        $this->token('d', $now + 600, 1, $now - 60);                   // Revocado hace 1 min: se queda.
        $this->token('e', $now + 600, 0, $now);                        // Vigente.
        $this->assertSame(2, cleanup::delete_old_tokens($now));
        $this->assertSame(3, $DB->count_records('local_liamateriales_token'));
    }

    public function test_cierra_sesiones_inactivas(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $vieja = $this->session('a.md', $now - 8 * DAYSECS, 0);
        $guardada = $this->session('b.md', $now - 8 * DAYSECS, $now - DAYSECS);
        $abierta = $this->session('c.md', $now - DAYSECS, 0);
        $this->assertSame(1, cleanup::close_idle_sessions($now));
        $this->assertEquals($now, $DB->get_field('local_liamateriales_editsess', 'closed_at', ['id' => $vieja]));
        $this->assertNull($DB->get_field('local_liamateriales_editsess', 'closed_at', ['id' => $guardada]));
        $this->assertNull($DB->get_field('local_liamateriales_editsess', 'closed_at', ['id' => $abierta]));
    }

    public function test_registrada_como_tarea_programada(): void {
        $this->resetAfterTest();
        $this->assertNotNull(\core\task\manager::get_scheduled_task(cleanup::class));
    }
}
