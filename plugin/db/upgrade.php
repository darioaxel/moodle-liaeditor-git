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
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026111901) {
        // Alcance del token: los de vista previa (solo lectura) no pueden
        // guardar ni publicar. Los tokens existentes caducan en minutos: se
        // marcan como de escritura (valor por defecto) sin más migración.
        $table = new xmldb_table('local_liamateriales_token');
        $field = new xmldb_field('readonly', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'courseid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026111901, 'local', 'liamateriales');
    }

    if ($oldversion < 2026112400) {
        // Código de apertura de un solo uso (LIA-062): clase de token.
        $table = new xmldb_table('local_liamateriales_token');
        $field = new xmldb_field('kind', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'edit', 'readonly');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026112400, 'local', 'liamateriales');
    }

    if ($oldversion < 2026112503) {
        // Publicar desde el editor retirado (publish_api.php): su límite de uso sobra.
        unset_config('ratelimit_publish', 'local_liamateriales');
        upgrade_plugin_savepoint(true, 2026112503, 'local', 'liamateriales');
    }

    return true;
}
