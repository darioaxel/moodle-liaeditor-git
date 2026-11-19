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

    if ($oldversion < 2026111802) {
        // El índice course-source-content no validaba en Moodle 6 (source
        // char(1333) > 255 y compuesto > 333 chars): se acorta el campo y
        // se sustituye por un índice simple por curso (tabla legado, que el
        // paso 2026111900 elimina).
        $table = new xmldb_table('local_courseascode_deploy');
        $field = new xmldb_field('source', XMLDB_TYPE_CHAR, '133', null, XMLDB_NOTNULL, null, null, 'bookid');
        if ($dbman->table_exists($table)) {
            if ($dbman->field_exists($table, $field)) {
                $dbman->change_field_precision($table, $field);
            }
            $oldindex = new xmldb_index('course-source-content', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'source', 'content_id']);
            if ($dbman->index_exists($table, $oldindex)) {
                $dbman->drop_index($table, $oldindex);
            }
            $newindex = new xmldb_index('courseid', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            if (!$dbman->index_exists($table, $newindex)) {
                $dbman->add_index($table, $newindex);
            }
        }
        upgrade_plugin_savepoint(true, 2026111802, 'local', 'courseascode');
    }

    if ($oldversion < 2026111900) {
        // Retirada del flujo legado "Course as Code" (mod_book): tablas de
        // despliegues de Books y mapping de capítulos, y assets subidos por
        // el antiguo web service upload_asset. Los web services y la
        // capability :manage desaparecen solos al no estar ya declarados.
        foreach (['local_courseascode_deploy', 'local_courseascode_chapter'] as $name) {
            $table = new xmldb_table($name);
            if ($dbman->table_exists($table)) {
                $dbman->drop_table($table);
            }
        }
        $fs = get_file_storage();
        $contextids = $DB->get_fieldset_sql(
            'SELECT DISTINCT contextid FROM {files} WHERE component = ? AND filearea = ?',
            ['local_courseascode', 'asset']
        );
        foreach ($contextids as $contextid) {
            $fs->delete_area_files((int) $contextid, 'local_courseascode', 'asset');
        }
        upgrade_plugin_savepoint(true, 2026111900, 'local', 'courseascode');
    }

    return true;
}
