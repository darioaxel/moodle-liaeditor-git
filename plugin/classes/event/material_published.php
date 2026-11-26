<?php
// Evento de auditoría (LIA-069).

/**
 * Material publicado en el curso.
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_liamateriales\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Material publicado en el curso.
 */
class material_published extends material_event {
    protected static function key(): string {
        return 'published';
    }

    protected static function crud(): string {
        return 'c';
    }
}
