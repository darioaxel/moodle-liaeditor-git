<?php
// Evento de auditoría (LIA-069).

/**
 * Material abierto en el editor.
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_liamateriales\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Material abierto en el editor.
 */
class material_opened extends material_event {
    protected static function key(): string {
        return 'opened';
    }

    protected static function crud(): string {
        return 'r';
    }
}
