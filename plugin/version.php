<?php
// This file is part of Moodle - http://moodle.org/
//
// Plugin local_liamateriales — engine "Moodle Course as Code".

/**
 * Plugin version info (Moodle 5.3+).
 *
 * @package    local_liamateriales
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_liamateriales';
$plugin->version = 2026112304;        // SCORM 1.2 mostrado como contenido; fix artifact_type (h5p llegaba como hp).
$plugin->requires = 2026100600;       // Moodle 5.3.0.
$plugin->maturity = MATURITY_ALPHA;
$plugin->release = '2.4.2';
