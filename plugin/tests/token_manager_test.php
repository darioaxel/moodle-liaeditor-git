<?php
// Código de apertura de un solo uso y token de edición (LIA-062).

namespace local_liamateriales;

defined('MOODLE_INTERNAL') || die();

use local_liamateriales\local\token_manager;

/**
 * Tests de token_manager.
 *
 * @package    local_liamateriales
 * @covers     \local_liamateriales\local\token_manager
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class token_manager_test extends \advanced_testcase {

    private const FILE = 'materiales/ut03-mvc/02-mvc.md';

    public function test_el_codigo_se_canjea_una_sola_vez(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $code = token_manager::issue_open((int) $user->id, self::FILE, 'edicion/x', 7, false);
        $this->assertSame(token_manager::KIND_OPEN, $code->kind);
        $this->assertLessThanOrEqual(time() + token_manager::OPEN_TTL, (int) $code->expires);

        $edit = token_manager::redeem($code->token);
        $this->assertSame(token_manager::KIND_EDIT, $edit->kind);
        $this->assertSame(self::FILE, $edit->file);
        $this->assertSame('edicion/x', $edit->branch);
        $this->assertSame(7, (int) $edit->courseid);
        $this->assertNotSame($code->token, $edit->token);

        $this->expectException(\moodle_exception::class);
        token_manager::redeem($code->token);
    }

    public function test_codigo_caducado(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $code = token_manager::issue_open((int) $user->id, self::FILE, 'main', 0, true);
        global $DB;
        $DB->set_field('local_liamateriales_token', 'expires', time() - 1, ['id' => $code->id]);
        $this->expectExceptionMessage(get_string('tokenexpired', 'local_liamateriales'));
        token_manager::redeem($code->token);
    }

    public function test_el_codigo_no_vale_como_token_de_edicion(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $code = token_manager::issue_open((int) $user->id, self::FILE, 'main');
        $this->expectException(\moodle_exception::class);
        token_manager::validate($code->token, self::FILE, 'main');
    }

    public function test_un_token_de_edicion_no_se_canjea(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $edit = token_manager::issue((int) $user->id, self::FILE, 'main');
        $this->expectException(\moodle_exception::class);
        token_manager::redeem($edit->token);
    }

    public function test_la_vista_previa_conserva_solo_lectura(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $edit = token_manager::redeem(token_manager::issue_open((int) $user->id, self::FILE, 'main', 0, true)->token);
        $this->assertSame(1, (int) $edit->readonly);
        $this->expectException(\moodle_exception::class);
        token_manager::validate($edit->token, self::FILE, 'main', true);
    }
}
