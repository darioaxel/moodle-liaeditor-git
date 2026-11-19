<?php
// Upgrade del plugin local_liamateriales.

/**
 * Upgrade steps.
 *
 * El plugin nace en 2026111900 como renombrado de local_courseascode
 * (instalación desde cero, sin migración de datos): los pasos de upgrade
 * empiezan a partir de esa versión.
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Actualiza la BD del plugin.
 *
 * @param int $oldversion versión instalada
 * @return bool
 */
function xmldb_local_liamateriales_upgrade(int $oldversion): bool {
    return true;
}
