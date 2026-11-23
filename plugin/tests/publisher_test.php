<?php
// Tests de los formatos de publicación (incluido SCORM).

namespace local_liamateriales;

defined('MOODLE_INTERNAL') || die();

use local_liamateriales\local\publisher;

/**
 * Tests de publisher: formatos admitidos y extensiones de fichero.
 *
 * @package    local_liamateriales
 * @covers     \local_liamateriales\local\publisher
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class publisher_test extends \advanced_testcase {

    public function test_formatos_incluyen_scorm(): void {
        $this->assertSame(['h5p', 'epub', 'pdf', 'scorm'], publisher::types());
    }

    public function test_extensiones(): void {
        $this->assertSame('zip', publisher::extension('scorm'));
        $this->assertSame('h5p', publisher::extension('h5p'));
        $this->assertSame('pdf', publisher::extension('pdf'));
        $this->assertSame('epub', publisher::extension('epub'));
    }

    public function test_scorm_se_muestra_como_contenido(): void {
        // La configuración del sitio no puede volver a mostrar intentos ni índice.
        $site = (object) ['skipview' => 0, 'hidetoc' => 0, 'nav' => 1, 'displayattemptstatus' => 1,
            'maxgrade' => 100, 'grademethod' => 1];
        $settings = publisher::scorm_settings($site);
        $this->assertSame('local', $settings['scormtype']);
        $this->assertSame(2, $settings['skipview']);
        $this->assertSame(3, $settings['hidetoc']);
        $this->assertSame(0, $settings['nav']);
        $this->assertSame(0, $settings['displayattemptstatus']);
        $this->assertSame(100, $settings['width']);
        $this->assertSame(100, $settings['height']);
        // La calificación sigue los valores del sitio.
        $this->assertSame(100, $settings['maxgrade']);
        $this->assertSame(1, $settings['grademethod']);
    }
}
