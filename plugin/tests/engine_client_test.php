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
            "POST\n/edit/save\n1700000000\n0123456789abcdef0123456789abcdef\n015abd7f5cc57a2dd94b7590f04ad8084273905ee33ec5cebeae62276a97f862",
            engine_client::canonical('post', '/edit/save', '1700000000', '0123456789abcdef0123456789abcdef', '{"a":1}')
        );
    }

    public function test_firma_post_y_get_con_query(): void {
        $secret = 'secreto-de-pruebas';
        $nonce = '0123456789abcdef0123456789abcdef';
        $this->assertSame(
            ['X-Liamat-Timestamp: 1700000000', 'X-Liamat-Nonce: ' . $nonce,
                'X-Liamat-Signature: a9c1d74cf677c74cee188d59d7e07cf3551ebb3660f1de3ed40969ee6b118956'],
            engine_client::auth_headers($secret, 'POST', 'http://engine:8000/edit/save', '{"a":1}', 1700000000, $nonce)
        );
        $this->assertSame(
            'X-Liamat-Signature: 8748fd4cead619fe02ced04ac3b41fb3ded9d6b80da30b65ccd0ee036409c3ce',
            engine_client::auth_headers($secret, 'GET',
                'http://engine:8000/edit/content?file=materiales%2Fa.md&branch=main', '', 1700000000, $nonce)[2]
        );
    }

    public function test_nonce_aleatorio_distinto_en_cada_peticion(): void {
        $a = engine_client::auth_headers('s', 'GET', 'http://engine:8000/edit/state?file=a.md', '', 1700000000);
        $b = engine_client::auth_headers('s', 'GET', 'http://engine:8000/edit/state?file=a.md', '', 1700000000);
        $this->assertMatchesRegularExpression('/^X-Liamat-Nonce: [0-9a-f]{32}$/', $a[1]);
        $this->assertNotSame($a[2], $b[2]);
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
