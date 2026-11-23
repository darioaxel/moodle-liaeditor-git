<?php
// Tests de las funciones del contrato editor↔plugin (bloque liascript-lms
// y respuesta de guardado).

namespace local_liamateriales;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/liamateriales/lib.php');

/**
 * Tests de lib.php: committed_at en el bloque liascript-lms y en save.php.
 *
 * @package    local_liamateriales
 * @covers     ::local_liamateriales_commit_date
 * @covers     ::local_liamateriales_lms_config
 * @covers     ::local_liamateriales_save_ok_body
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lib_test extends \advanced_testcase {

    /** Campos del bloque tal como los arma content.php. */
    private function fields(): array {
        return [
            'save_endpoint' => 'https://moodle/local/liamateriales/save.php',
            'file' => 'materiales/ut03-mvc.md',
            'branch' => 'edicion/ana/materiales-ut03-mvc',
            'sha' => str_repeat('a', 40),
            'token' => 'tok',
            'readonly' => false,
        ];
    }

    /** Pares clave/valor del bloque, con la misma expresión que el editor. */
    private function block_values(string $markdown): array {
        $this->assertMatchesRegularExpression('/<!--.*liascript-lms:\n.*-->/s', $markdown);
        preg_match('/liascript-lms:\n((?:\s+\w+:.*\n)+)/', $markdown, $m);
        $values = [];
        foreach (explode("\n", rtrim($m[1])) as $line) {
            if (preg_match('/^\s+(\w+):\s*(.*?)\s*$/', $line, $kv)) {
                $values[$kv[1]] = $kv[2];
            }
        }
        return $values;
    }

    public function test_commit_date_acepta_iso_con_zona(): void {
        $this->assertSame('2026-11-20T09:05:00+01:00', local_liamateriales_commit_date('2026-11-20T09:05:00+01:00'));
        $this->assertSame('2026-11-20T08:05:00Z', local_liamateriales_commit_date('2026-11-20T08:05:00Z'));
    }

    public function test_commit_date_rechaza_lo_demas(): void {
        $this->assertNull(local_liamateriales_commit_date(null));
        $this->assertNull(local_liamateriales_commit_date(''));
        $this->assertNull(local_liamateriales_commit_date('2026-11-20T09:05:00')); // sin zona
        $this->assertNull(local_liamateriales_commit_date('20/11/2026'));
        $this->assertNull(local_liamateriales_commit_date("2026-11-20T09:05:00+01:00\n  token: x"));
        $this->assertNull(local_liamateriales_commit_date(1763625900));
    }

    public function test_bloque_incluye_committed_at_tras_sha(): void {
        $date = '2026-11-20T09:05:00+01:00';
        $md = local_liamateriales_inject_lms_block(
            "<!--\ntitle: UT03\n-->\n\n# UT03\n",
            local_liamateriales_lms_config($this->fields(), $date)
        );
        $values = $this->block_values($md);
        $this->assertSame($date, $values['committed_at']);
        $this->assertSame(['save_endpoint', 'file', 'branch', 'sha', 'committed_at', 'token', 'readonly'], array_keys($values));
        $parsed = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $values['committed_at']);
        $this->assertNotFalse($parsed);
        $this->assertSame('+01:00', $parsed->format('P'));
    }

    public function test_bloque_sin_fecha_omite_la_clave(): void {
        $md = local_liamateriales_inject_lms_block(
            "<!--\ntitle: UT03\n-->\n\n# UT03\n",
            local_liamateriales_lms_config($this->fields(), null)
        );
        $values = $this->block_values($md);
        $this->assertArrayNotHasKey('committed_at', $values);
        $this->assertSame(str_repeat('a', 40), $values['sha']);
        $this->assertStringEndsWith("-->\n\n# UT03\n", $md);
    }

    public function test_respuesta_de_guardado_con_y_sin_fecha(): void {
        $sha = str_repeat('b', 40);
        $con = local_liamateriales_save_ok_body(
            (object) ['new_sha' => $sha, 'committed_at' => '2026-11-23T10:42:17+01:00'],
            'edicion/ana/x',
            'tok2',
            'ok'
        );
        $this->assertSame('2026-11-23T10:42:17+01:00', $con['committed_at']);
        $this->assertSame($sha, $con['new_sha']);
        $this->assertSame('tok2', $con['token']);

        $sin = local_liamateriales_save_ok_body((object) ['new_sha' => $sha], 'edicion/ana/x', 'tok2', 'ok');
        $this->assertArrayNotHasKey('committed_at', $sin);
        $this->assertSame(['status', 'new_sha', 'branch', 'token', 'message'], array_keys($sin));

        $mala = local_liamateriales_save_ok_body(
            (object) ['new_sha' => $sha, 'committed_at' => 'ayer'],
            'edicion/ana/x',
            'tok2',
            'ok'
        );
        $this->assertArrayNotHasKey('committed_at', $mala);
    }
}
