<?php
// Upgrade del plugin local_courseascode.

/**
 * Upgrade steps.
 *
 * @package    local_courseascode
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Actualiza la BD del plugin.
 *
 * @param int $oldversion versión instalada
 * @return bool
 */
function xmldb_local_courseascode_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026111700) {
        // LIA-010/011: tablas del flujo LiaEditor (despliegues simplificada,
        // sesiones de edición y tokens de un solo uso).
        $tables = ['local_courseascode_ldeploy', 'local_courseascode_editsess', 'local_courseascode_token'];
        foreach ($tables as $name) {
            $table = new xmldb_table($name);
            if (!$dbman->table_exists($table)) {
                $dbman->install_one_table_from_xmldb_file(
                    __DIR__ . '/install.xml',
                    $name
                );
            }
        }
        upgrade_plugin_savepoint(true, 2026111700, 'local', 'courseascode');
    }

    if ($oldversion < 2026111801) {
        // LIA-041: el token conoce el curso de origen para publicar desde el editor.
        $table = new xmldb_table('local_courseascode_token');
        $field = new xmldb_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'userid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026111801, 'local', 'courseascode');
    }

    return true;
}
