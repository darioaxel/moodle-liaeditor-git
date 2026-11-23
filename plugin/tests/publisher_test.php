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
}
