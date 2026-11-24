<?php
// Evento de auditoría (LIA-069).

/**
 * Material guardado.
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_liamateriales\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Material guardado.
 */
class material_saved extends material_event {
    protected static function key(): string {
        return 'saved';
    }

    protected static function crud(): string {
        return 'u';
    }
}
