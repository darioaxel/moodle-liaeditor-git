<?php
// Configuración del plugin (LIA-012).

/**
 * Settings: editor LiaEdit, CORS, tokens y límites del flujo de edición.
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_liamateriales', get_string('pluginname', 'local_liamateriales'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configtext(
        'local_liamateriales/liaeditor_url',
        get_string('settings_liaeditor_url', 'local_liamateriales'),
        get_string('settings_liaeditor_url_desc', 'local_liamateriales'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_liamateriales/cors_origin',
        get_string('settings_cors_origin', 'local_liamateriales'),
        get_string('settings_cors_origin_desc', 'local_liamateriales'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_liamateriales/token_ttl',
        get_string('settings_token_ttl', 'local_liamateriales'),
        get_string('settings_token_ttl_desc', 'local_liamateriales'),
        900,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_liamateriales/max_file_size',
        get_string('settings_max_file_size', 'local_liamateriales'),
        get_string('settings_max_file_size_desc', 'local_liamateriales'),
        1048576,
        PARAM_INT
    ));
}
