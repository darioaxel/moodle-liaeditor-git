<?php
// Validación de las imágenes que viajan con el guardado (docs/imagenes, fase 2).

namespace local_liamateriales;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/liamateriales/lib.php');

/**
 * Tests de local_liamateriales_validate_images.
 *
 * @package    local_liamateriales
 * @covers     ::local_liamateriales_validate_images
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class images_test extends \advanced_testcase {

    /** PNG de 1×1. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    /** GIF de 1×1. */
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    private static function img(string $path, string $b64): \stdClass {
        return (object) ['path' => $path, 'content_base64' => $b64];
    }

    public function test_sin_imagenes(): void {
        $this->assertSame([], local_liamateriales_validate_images(null));
        $this->assertSame([], local_liamateriales_validate_images([]));
    }

    public function test_acepta_png_y_gif(): void {
        $result = local_liamateriales_validate_images([self::img('img/a-1a2b3c4d.png', self::PNG), self::img('b.gif', self::GIF)]);
        $this->assertSame([
            ['path' => 'img/a-1a2b3c4d.png', 'content_base64' => self::PNG],
            ['path' => 'b.gif', 'content_base64' => self::GIF],
        ], $result);
    }

    /**
     * @dataProvider invalidas
     */
    public function test_rechaza(string $path, string $b64): void {
        $this->expectException(\moodle_exception::class);
        local_liamateriales_validate_images([self::img($path, $b64)]);
    }

    public static function invalidas(): array {
        return [
            'svg' => ['img/a.svg', base64_encode('<svg onload="alert(1)"/>')],
            'webp' => ['img/a.webp', self::PNG],
            'extensión que no casa' => ['img/a.jpg', self::PNG],
            'html con extensión png' => ['img/a.png', base64_encode('<html><script>x</script></html>')],
            'subir de carpeta' => ['../a.png', self::PNG],
            'absoluta' => ['/a.png', self::PNG],
            'espacios' => ['img/a b.png', self::PNG],
            'base64 roto' => ['img/a.png', 'no*es*base64'],
            'vacía' => ['img/a.png', ''],
            'demasiado grande' => ['img/a.png', base64_encode(base64_decode(self::PNG) . str_repeat("\0", 2 * 1024 * 1024))],
        ];
    }

    public function test_demasiadas(): void {
        $this->expectException(\moodle_exception::class);
        local_liamateriales_validate_images(array_fill(0, 21, self::img('a.png', self::PNG)));
    }
}
