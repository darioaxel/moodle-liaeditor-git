<?php
// Límites de uso y bloqueo por IP (LIA-064, LIA-066) y publicación única (LIA-065).

namespace local_liamateriales;

defined('MOODLE_INTERNAL') || die();

use local_liamateriales\local\publisher;
use local_liamateriales\local\rate_limiter;

/**
 * Tests de rate_limiter y del bloqueo de publicación.
 *
 * @package    local_liamateriales
 * @covers     \local_liamateriales\local\rate_limiter
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rate_limiter_test extends \advanced_testcase {

    private int $now = 1700000000;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        rate_limiter::$clock = fn() => $this->now;
    }

    protected function tearDown(): void {
        rate_limiter::$clock = null;
        parent::tearDown();
    }

    public function test_cada_accion_tiene_su_limite_por_usuario(): void {
        foreach (rate_limiter::LIMITS as $action => [, $max, $window]) {
            for ($i = 0; $i < $max; $i++) {
                $this->assertNull(rate_limiter::hit($action, 7), "$action #$i");
            }
            $wait = rate_limiter::hit($action, 7);
            $this->assertSame($window, $wait, $action);
            // Otro usuario no se ve afectado.
            $this->assertNull(rate_limiter::hit($action, 8));
        }
    }

    public function test_ventana_deslizante(): void {
        for ($i = 0; $i < 20; $i++) {
            rate_limiter::hit('save', 7);
        }
        $this->assertSame(60, rate_limiter::hit('save', 7));
        $this->now += 30;
        $this->assertSame(30, rate_limiter::hit('save', 7));
        $this->now += 31;
        $this->assertNull(rate_limiter::hit('save', 7));
    }

    public function test_limite_configurable_y_desactivable(): void {
        set_config('ratelimit_token', 1, 'local_liamateriales');
        $this->assertNull(rate_limiter::hit('token', 7));
        $this->assertNotNull(rate_limiter::hit('token', 7));
        set_config('ratelimit_token', 0, 'local_liamateriales');
        $this->assertNull(rate_limiter::hit('token', 7));
    }

    public function test_bloqueo_de_ip_tras_veinte_tokens_invalidos(): void {
        $ip = '2001:db8::1';
        for ($i = 0; $i < rate_limiter::IP_FAILURES - 1; $i++) {
            rate_limiter::token_failure($ip);
        }
        $this->assertNull(rate_limiter::ip_blocked($ip));
        rate_limiter::token_failure($ip);
        $this->assertSame(rate_limiter::IP_BLOCK, rate_limiter::ip_blocked($ip));
        $this->assertNull(rate_limiter::ip_blocked('192.0.2.1'));
        $this->now += rate_limiter::IP_BLOCK;
        $this->assertNull(rate_limiter::ip_blocked($ip));
    }

    public function test_los_fallos_antiguos_no_cuentan(): void {
        $ip = '192.0.2.9';
        for ($i = 0; $i < rate_limiter::IP_FAILURES - 1; $i++) {
            rate_limiter::token_failure($ip);
        }
        $this->now += rate_limiter::IP_WINDOW + 1;
        rate_limiter::token_failure($ip);
        $this->assertNull(rate_limiter::ip_blocked($ip));
    }

    public function test_una_publicacion_a_la_vez(): void {
        $user = $this->getDataGenerator()->create_user();
        $file = 'materiales/ut03-mvc/02-mvc.md';
        $lock = \core\lock\lock_config::get_lock_factory('local_liamateriales')
            ->get_lock('publish_' . $user->id . '_' . sha1($file), 0);
        $this->assertNotFalse($lock);
        try {
            publisher::publish_generated(1, 0, $file, 'pdf', str_repeat('a', 40), null, $user);
            $this->fail('debía rechazar la segunda publicación');
        } catch (\moodle_exception $e) {
            $this->assertSame('publishbusy', $e->errorcode);
        } finally {
            $lock->release();
        }
    }
}
