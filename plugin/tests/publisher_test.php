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
        $this->assertSame(['h5p', 'epub', 'pdf', 'scorm', 'web', 'pdfcontenido'], publisher::types());
    }

    public function test_extensiones(): void {
        $this->assertSame('zip', publisher::extension('scorm'));
        $this->assertSame('h5p', publisher::extension('h5p'));
        $this->assertSame('pdf', publisher::extension('pdf'));
        $this->assertSame('epub', publisher::extension('epub'));
        $this->assertSame('zip', publisher::extension('web'));
        $this->assertSame('pdf', publisher::extension('pdfcontenido'));
    }

    public function test_web_en_ventana_emergente_por_defecto(): void {
        $settings = publisher::web_settings((object) []);
        $this->assertSame(6, $settings['display']);
        $this->assertSame(1200, $settings['popupwidth']);
        $this->assertSame(600, $settings['popupheight']);
        $this->assertSame(0, $settings['filterfiles']);
        // Ajustes del plugin; un modo no ofrecido vuelve al de por defecto.
        $settings = publisher::web_settings((object) ['web_display' => '5', 'web_popupwidth' => '900']);
        $this->assertSame(5, $settings['display']);
        $this->assertSame(900, $settings['popupwidth']);
        $this->assertSame(6, publisher::web_settings((object) ['web_display' => '4'])['display']);
    }

    public function test_avisos_de_la_cabecera(): void {
        $headers = ['HTTP/1.1' => '200 OK', 'x-liamat-avisos' => '["12 \u00b7 sintaxis \u00b7 @mermaid", 3]'];
        $this->assertSame(['12 · sintaxis · @mermaid', '3'], publisher::parse_avisos($headers));
        $this->assertSame([], publisher::parse_avisos(['content-type' => 'application/zip']));
        $this->assertSame([], publisher::parse_avisos(['X-Liamat-Avisos' => 'no-json']));
    }

    public function test_web_se_descomprime_con_index_principal(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $zippath = make_request_directory() . '/w.zip';
        $zip = new \ZipArchive();
        $zip->open($zippath, \ZipArchive::CREATE);
        $zip->addFromString('index.html', '<h1>Inicio</h1>');
        $zip->addFromString('1.1-tema.html', '<p>Tema</p>');
        $zip->addFromString('img/a.png', 'png');
        $zip->close();
        $itemid = publisher::stage_artifact((int) $user->id, 'x.zip', file_get_contents($zippath), 'web');
        $files = get_file_storage()->get_area_files(
            \context_user::instance($user->id)->id, 'user', 'draft', $itemid, 'filepath, filename', false
        );
        $byname = [];
        foreach ($files as $file) {
            $byname[$file->get_filepath() . $file->get_filename()] = $file->get_sortorder();
        }
        $this->assertSame(['/1.1-tema.html' => 0, '/img/a.png' => 0, '/index.html' => 1], $byname);
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
