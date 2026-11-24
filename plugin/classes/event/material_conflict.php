<?php
// Evento de auditoría (LIA-069).

/**
 * Conflicto al guardar un material.
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_liamateriales\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Conflicto al guardar un material.
 */
class material_conflict extends material_event {
    protected static function key(): string {
        return 'conflict';
    }

    protected static function crud(): string {
        return 'u';
    }
}
