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

    // Secreto compartido con el engine (LIA-060): firma HMAC de cada petición.
    // Preferible en config.php: $CFG->local_liamateriales_service_secret.
    $settings->add(new admin_setting_configpasswordunmask(
        'local_liamateriales/service_secret',
        get_string('settings_service_secret', 'local_liamateriales'),
        get_string('settings_service_secret_desc', 'local_liamateriales'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_liamateriales/liaeditor_url',
        get_string('settings_liaeditor_url', 'local_liamateriales'),
        get_string('settings_liaeditor_url_desc', 'local_liamateriales'),
        '',
        PARAM_URL
    ));

    // Formato de apertura antiguo (token en la URL) para el fork de LiveEditor (LIA-062).
    $settings->add(new admin_setting_configcheckbox(
        'local_liamateriales/legacy_open_url',
        get_string('settings_legacy_open_url', 'local_liamateriales'),
        get_string('settings_legacy_open_url_desc', 'local_liamateriales'),
        0
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

    // Web estática (docs/generacion_web): cómo se abre en el curso.
    $settings->add(new admin_setting_heading(
        'local_liamateriales/web_heading',
        get_string('settings_web_heading', 'local_liamateriales'),
        get_string('settings_web_heading_desc', 'local_liamateriales')
    ));
    require_once($CFG->libdir . '/resourcelib.php');
    $displays = [];
    foreach (\local_liamateriales\local\publisher::WEB_DISPLAYS as $display) {
        $displays[$display] = get_string('resourcedisplay' . [0 => 'auto', 1 => 'embed', 3 => 'new', 5 => 'open', 6 => 'popup'][$display]);
    }
    $settings->add(new admin_setting_configselect(
        'local_liamateriales/web_display',
        get_string('settings_web_display', 'local_liamateriales'),
        get_string('settings_web_display_desc', 'local_liamateriales'),
        \local_liamateriales\local\publisher::WEB_DISPLAY_DEFAULT,
        $displays
    ));
    $settings->add(new admin_setting_configtext(
        'local_liamateriales/web_popupwidth',
        get_string('settings_web_popupwidth', 'local_liamateriales'),
        '',
        1200,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_liamateriales/web_popupheight',
        get_string('settings_web_popupheight', 'local_liamateriales'),
        '',
        600,
        PARAM_INT
    ));
}
