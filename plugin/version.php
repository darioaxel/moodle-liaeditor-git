<?php
// This file is part of Moodle - http://moodle.org/
//
// Plugin local_courseascode — engine "Moodle Course as Code".

/**
 * Plugin version info (Moodle 5.3+).
 *
 * @package    local_courseascode
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_courseascode';
$plugin->version = 2026111900;        // Retirada del flujo legado mod_book (tablas, web services, assets).
$plugin->requires = 2026100600;       // Moodle 5.3.0.
$plugin->maturity = MATURITY_ALPHA;
$plugin->release = '2.1.0';
