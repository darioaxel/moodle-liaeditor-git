<?php
// Privacy API (Moodle exige provider para plugins con datos).
// PENDIENTE: editsess, token y deploy guardan userid/username; hace falta
// un provider real (metadata + export + delete) en lugar del null provider.

/**
 * Privacy provider.
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_liamateriales\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * Null provider provisional (ver nota de cabecera).
 */
class provider implements \core_privacy\local\metadata\null_provider {
    public static function get_reason(): string {
        return 'privacy:metadata:reason';
    }
}
