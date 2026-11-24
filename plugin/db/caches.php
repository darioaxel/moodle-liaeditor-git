<?php
// Cachés MUC del plugin.

/**
 * Definiciones de caché.
 *
 * - ``ratelimit``: contadores de los límites de uso por usuario y de los
 *   fallos de token por IP (LIA-064, LIA-066). Caché de aplicación: los
 *   contadores son comunes a todos los servidores web que compartan el
 *   almacén de MUC.
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    'ratelimit' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'staticacceleration' => false,
    ],
];
