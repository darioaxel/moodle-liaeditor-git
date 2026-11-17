<?php
// Configuración del plugin (LIA-012).

/**
 * Settings: editor LiaEdit, CORS, tokens y límites del flujo de edición.
 *
 * @package    local_courseascode
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_courseascode', get_string('pluginname', 'local_courseascode'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configtext(
        'local_courseascode/liaeditor_url',
        get_string('settings_liaeditor_url', 'local_courseascode'),
        get_string('settings_liaeditor_url_desc', 'local_courseascode'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_courseascode/cors_origin',
        get_string('settings_cors_origin', 'local_courseascode'),
        get_string('settings_cors_origin_desc', 'local_courseascode'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_courseascode/token_ttl',
        get_string('settings_token_ttl', 'local_courseascode'),
        get_string('settings_token_ttl_desc', 'local_courseascode'),
        900,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_courseascode/max_file_size',
        get_string('settings_max_file_size', 'local_courseascode'),
        get_string('settings_max_file_size_desc', 'local_courseascode'),
        1048576,
        PARAM_INT
    ));
}
