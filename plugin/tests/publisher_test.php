<?php
// Tests de los formatos de publicación y de los despliegues vivos.

namespace local_liamateriales;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

use local_liamateriales\local\publisher;

/**
 * Tests de publisher: formatos admitidos y extensiones de fichero.
 *
 * @package    local_liamateriales
 * @covers     \local_liamateriales\local\publisher
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class publisher_test extends \advanced_testcase {

    public function test_formatos_sin_scorm(): void {
        $this->assertSame(['h5p', 'epub', 'pdf', 'web', 'pdfcontenido'], publisher::types());
    }

    public function test_nombres_de_los_pdf(): void {
        $this->assertSame('PDF (contenido web)', publisher::label('pdf'));
        $this->assertSame('PDF (LaTeX)', publisher::label('pdfcontenido'));
        $this->assertSame('H5P', publisher::label('h5p'));
    }

    public function test_extensiones(): void {
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

    /**
     * Registra un despliegue como lo hace publish().
     */
    private function desplegar(int $courseid, string $file, string $type, int $cmid, int $when): void {
        global $DB;
        $DB->insert_record('local_liamateriales_deploy', (object) [
            'courseid' => $courseid, 'source_file' => $file, 'git_commit' => str_repeat('a', 40),
            'artifact_type' => $type, 'cmid' => $cmid, 'deployed_at' => $when, 'deployed_by' => 'profe',
            'status' => 'ok',
        ]);
    }

    public function test_actividad_borrada_deja_de_estar_publicada(): void {
        global $DB;
        $this->resetAfterTest();
        // Sin papelera: el borrado no hace copia de seguridad del módulo.
        set_config('coursebinenable', 0, 'tool_recyclebin');
        $course = $this->getDataGenerator()->create_course();
        $pdf = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $this->desplegar((int) $course->id, 'tema1.md', 'pdf', (int) $pdf->cmid, 100);

        $this->assertCount(1, publisher::live_deploys((int) $course->id));
        $this->assertSame((int) $pdf->cmid, publisher::reusable_cmid((int) $course->id, 'tema1.md', 'pdf'));
        $this->assertNotNull(publisher::latest_deploy((int) $course->id, 'tema1.md'));

        course_delete_module((int) $pdf->cmid);

        $this->assertSame([], publisher::live_deploys((int) $course->id));
        $this->assertSame(0, publisher::reusable_cmid((int) $course->id, 'tema1.md', 'pdf'));
        $this->assertNull(publisher::latest_deploy((int) $course->id, 'tema1.md'));
        // El historial (trazabilidad D6) se conserva.
        $this->assertSame(1, $DB->count_records('local_liamateriales_deploy'));
    }

    public function test_actividad_en_borrado_no_cuenta_como_publicada(): void {
        global $DB;
        $this->resetAfterTest();
        // Sin papelera: el borrado no hace copia de seguridad del módulo.
        set_config('coursebinenable', 0, 'tool_recyclebin');
        $course = $this->getDataGenerator()->create_course();
        $pdf = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $this->desplegar((int) $course->id, 'tema1.md', 'pdf', (int) $pdf->cmid, 100);

        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $pdf->cmid]);

        $this->assertSame([], publisher::live_deploys((int) $course->id));
    }

    public function test_un_despliegue_vivo_por_fichero_y_formato(): void {
        $this->resetAfterTest();
        // Sin papelera: el borrado no hace copia de seguridad del módulo.
        set_config('coursebinenable', 0, 'tool_recyclebin');
        $course = $this->getDataGenerator()->create_course();
        $pdf = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $epub = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $this->desplegar((int) $course->id, 'tema1.md', 'pdf', (int) $pdf->cmid, 100);
        $this->desplegar((int) $course->id, 'tema1.md', 'pdf', (int) $pdf->cmid, 200);
        $this->desplegar((int) $course->id, 'tema1.md', 'epub', (int) $epub->cmid, 300);

        $live = publisher::live_deploys((int) $course->id);
        $this->assertSame(['epub', 'pdf'], array_map(static fn($row) => $row->artifact_type, $live));
        $this->assertSame(200, (int) $live[1]->deployed_at);

        // Borrar el ePub deja publicado el PDF.
        course_delete_module((int) $epub->cmid);
        $this->assertSame('pdf', publisher::latest_deploy((int) $course->id, 'tema1.md')->artifact_type);
    }

    public function test_modulo_de_otro_tipo_no_cuenta(): void {
        $this->resetAfterTest();
        // Sin papelera: el borrado no hace copia de seguridad del módulo.
        set_config('coursebinenable', 0, 'tool_recyclebin');
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->desplegar((int) $course->id, 'tema1.md', 'pdf', (int) $page->cmid, 100);

        $this->assertSame([], publisher::live_deploys((int) $course->id));
    }
}
