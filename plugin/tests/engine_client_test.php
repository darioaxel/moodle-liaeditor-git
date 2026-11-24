<?php
// Firma HMAC de las peticiones al engine (LIA-060).

namespace local_liamateriales;

defined('MOODLE_INTERNAL') || die();

use local_liamateriales\local\engine_client;

/**
 * Tests de engine_client: mismos vectores que tests/test_auth.py del engine.
 *
 * @package    local_liamateriales
 * @covers     \local_liamateriales\local\engine_client
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class engine_client_test extends \advanced_testcase {

    public function test_texto_canonico_igual_que_el_engine(): void {
        $this->assertSame(
            "POST\n/edit/save\n1700000000\n015abd7f5cc57a2dd94b7590f04ad8084273905ee33ec5cebeae62276a97f862",
            engine_client::canonical('post', '/edit/save', '1700000000', '{"a":1}')
        );
    }

    public function test_firma_post_y_get_con_query(): void {
        $secret = 'secreto-de-pruebas';
        $this->assertSame(
            ['X-Liamat-Timestamp: 1700000000',
                'X-Liamat-Signature: 67bc53c7ceda72b7f51dd50a57474fa057f89c67fddf8c99a7e852bcb142ce17'],
            engine_client::auth_headers($secret, 'POST', 'http://engine:8000/edit/save', '{"a":1}', 1700000000)
        );
        $this->assertSame(
            'X-Liamat-Signature: 7f7d69f2d03b6daeb86da376563dabc27beeaa9cb4d98494e56e0812e5d97bae',
            engine_client::auth_headers($secret, 'GET',
                'http://engine:8000/edit/content?file=materiales%2Fa.md&branch=main', '', 1700000000)[1]
        );
    }

    public function test_sin_secreto_no_firma(): void {
        $this->assertSame([], engine_client::auth_headers('', 'GET', 'http://engine:8000/health', ''));
    }

    public function test_secreto_de_config_php_prioritario(): void {
        global $CFG;
        $this->resetAfterTest();
        set_config('service_secret', 'del-ajuste', 'local_liamateriales');
        $this->assertSame('del-ajuste', engine_client::secret());
        $CFG->local_liamateriales_service_secret = 'de-config';
        $this->assertSame('de-config', engine_client::secret());
    }

    public function test_url_con_query(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->local_liamateriales_service_url = 'http://engine:8000/';
        $this->assertSame(
            'http://engine:8000/edit/content?file=materiales%2Fa.md&branch=main',
            engine_client::url('/edit/content', ['file' => 'materiales/a.md', 'branch' => 'main'])
        );
    }
}
