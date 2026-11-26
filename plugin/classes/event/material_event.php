<?php
// Base de los eventos de auditoría de materiales (LIA-069).

/**
 * Evento de un material (fichero del repositorio de contenidos).
 *
 * Los cuatro eventos del plugin (abierto, guardado, conflicto, publicado)
 * quedan en el registro estándar de Moodle y en los informes del curso.
 * Contexto: el curso desde el que se trabaja o, si no lo hay (vista previa
 * desde fuera de un curso), el del sistema. ``other`` lleva el fichero, la
 * rama, el SHA y, según el evento, el cmid o el formato.
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_liamateriales\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Base común de los eventos de materiales.
 */
abstract class material_event extends \core\event\base {
    /** Clave de la cadena de idioma del nombre (``event_<clave>``). */
    abstract protected static function key(): string;

    /** Operación CRUD del evento. */
    protected static function crud(): string {
        return 'u';
    }

    protected function init() {
        $this->data['crud'] = static::crud();
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }

    public static function get_name() {
        return get_string('event_' . static::key(), 'local_liamateriales');
    }

    public function get_description() {
        $other = $this->other;
        $detail = s($other['file'] ?? '');
        $labels = [
            'branch' => 'rama', 'sha' => 'SHA', 'format' => 'formato', 'cmid' => 'cmid',
            'merged_main' => 'incorpora main', // LIA-130
        ];
        foreach ($labels as $key => $label) {
            if (!empty($other[$key])) {
                $value = in_array($key, ['sha', 'merged_main'], true) ? substr((string) $other[$key], 0, 8) : (string) $other[$key];
                $detail .= " · {$label} " . s($value);
            }
        }
        return "El usuario con id '{$this->userid}': " . static::get_name() . " — {$detail}";
    }

    protected function validate_data() {
        parent::validate_data();
        if (empty($this->other['file'])) {
            throw new \coding_exception('Falta other[file]');
        }
    }

    public static function get_other_mapping() {
        return ['cmid' => ['db' => 'course_modules', 'restore' => 'course_module']];
    }

    /**
     * Crea y dispara el evento.
     *
     * @param int $userid usuario que actúa (los scripts del editor no tienen sesión)
     * @param int $courseid curso de trabajo (0 = sistema)
     * @param array<string, mixed> $other file, branch, sha, cmid, format…
     */
    public static function fire(int $userid, int $courseid, array $other): void {
        $context = $courseid > 0 ? \context_course::instance($courseid, IGNORE_MISSING) : null;
        $data = [
            'context' => $context ?: \context_system::instance(),
            'userid' => $userid,
            'other' => array_filter($other, fn($v) => $v !== null && $v !== ''),
        ];
        if ($context) {
            $data['courseid'] = $courseid;
        }
        static::create($data)->trigger();
    }
}
