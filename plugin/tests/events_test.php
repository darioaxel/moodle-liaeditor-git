<?php
// Eventos de auditoría (LIA-069).

namespace local_liamateriales;

defined('MOODLE_INTERNAL') || die();

use local_liamateriales\event\material_conflict;
use local_liamateriales\event\material_opened;
use local_liamateriales\event\material_published;
use local_liamateriales\event\material_saved;

/**
 * Tests de los eventos de materiales.
 *
 * @package    local_liamateriales
 * @covers     \local_liamateriales\event\material_event
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class events_test extends \advanced_testcase {

    public function test_los_cuatro_eventos_con_su_contexto_y_datos(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $sink = $this->redirectEvents();
        $file = 'materiales/ut03-mvc/02-mvc.md';
        material_opened::fire((int) $user->id, (int) $course->id, ['file' => $file, 'branch' => 'main', 'sha' => str_repeat('a', 40)]);
        material_saved::fire((int) $user->id, (int) $course->id, ['file' => $file, 'branch' => 'edicion/x', 'sha' => str_repeat('b', 40)]);
        material_conflict::fire((int) $user->id, (int) $course->id, ['file' => $file, 'branch' => 'edicion/x', 'sha' => 'c']);
        material_published::fire((int) $user->id, (int) $course->id, ['file' => $file, 'sha' => 'd', 'format' => 'web', 'cmid' => 5]);
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(4, $events);
        $this->assertInstanceOf(material_opened::class, $events[0]);
        $this->assertInstanceOf(material_published::class, $events[3]);
        foreach ($events as $event) {
            $this->assertEquals((int) $user->id, $event->userid);
            $this->assertEquals(\context_course::instance($course->id)->id, $event->contextid);
            $this->assertSame($file, $event->other['file']);
        }
        $this->assertSame('web', $events[3]->other['format']);
        $this->assertStringContainsString('formato web', $events[3]->get_description());
    }

    public function test_sin_curso_va_al_contexto_del_sistema(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();
        material_opened::fire(2, 0, ['file' => 'a.md', 'branch' => 'main', 'readonly' => 1]);
        $event = $sink->get_events()[0];
        $sink->close();
        $this->assertEquals(\context_system::instance()->id, $event->contextid);
    }

    public function test_exige_el_fichero(): void {
        $this->resetAfterTest();
        $this->expectException(\coding_exception::class);
        material_saved::fire(2, 0, ['branch' => 'main']);
    }
}
