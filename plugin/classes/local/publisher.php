<?php
// Publicación de artefactos en el curso (D6).

/**
 * Publisher: crea/actualiza el módulo del curso a partir del artefacto
 * generado del material (H5P, ePub, PDF) y registra el despliegue
 * curso↔SHA↔cmid en ``local_liamateriales_deploy`` (trazabilidad D6).
 *
 * Mapeo tipo → módulo: ``h5p`` → mod_h5pactivity · ``epub``/``pdf`` →
 * mod_resource (descarga accesible). Desde Materiales Editables v2.0 los
 * formatos de publicación son solo H5P, ePub y PDF.
 *
 * @package    local_liamateriales
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_liamateriales\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Creación/actualización de módulos de artefacto y registro del despliegue.
 */
class publisher {
    /** Tipos de artefacto → (modulename, campo filemanager del form). */
    private const TYPES = [
        'h5p' => ['h5pactivity', 'packagefile'],
        'epub' => ['resource', 'files'],
        'pdf' => ['resource', 'files'],
    ];

    /**
     * Publica el artefacto subido (área de borrador del usuario) como módulo
     * del curso y registra el despliegue.
     *
     * @param int $courseid curso destino
     * @param int $section sección del curso (0 = general)
     * @param string $sourcefile ruta del .md en el repo
     * @param string $artifacttype h5p | epub | pdf
     * @param string $sha SHA del material publicado (trazabilidad)
     * @param int $draftitemid área de borrador con el paquete subido
     * @param string $name nombre visible del módulo
     * @param int $existingcmid cmid a actualizar (0 = crear nuevo)
     * @param string $deployedby username que publica
     * @return int cmid del módulo
     */
    public static function publish(
        int $courseid,
        int $section,
        string $sourcefile,
        string $artifacttype,
        string $sha,
        int $draftitemid,
        string $name,
        int $existingcmid,
        string $deployedby
    ): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/modlib.php');

        if (!isset(self::TYPES[$artifacttype])) {
            throw new \moodle_exception('invalidartifacttype', 'local_liamateriales', '', $artifacttype);
        }
        [$modulename, $filefield] = self::TYPES[$artifacttype];
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $moduleid = $DB->get_field('modules', 'id', ['name' => $modulename], MUST_EXIST);

        $moduleinfo = (object) [
            'modulename' => $modulename,
            'module' => $moduleid,
            'course' => $courseid,
            'section' => $section,
            'visible' => 1,
            'name' => $name,
            'intro' => '',
            'introformat' => FORMAT_HTML,
            $filefield => $draftitemid,
        ];

        if ($existingcmid > 0) {
            $cm = get_coursemodule_from_id('', $existingcmid, 0, false, MUST_EXIST);
            $moduleinfo->id = $cm->instance;
            $moduleinfo->coursemodule = $cm->id;
            $moduleinfo->cmidnumber = $cm->idnumber;
            $moduleinfo->introeditor = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
            update_moduleinfo($cm, $moduleinfo, $course);
            $cmid = (int) $cm->id;
        } else {
            $moduleinfo->coursemodule = 0;
            $created = add_moduleinfo($moduleinfo, $course);
            $cmid = (int) $created->coursemodule;
        }

        $DB->insert_record('local_liamateriales_deploy', (object) [
            'courseid' => $courseid,
            'source_file' => $sourcefile,
            'git_commit' => $sha,
            'artifact_type' => $artifacttype,
            'cmid' => $cmid,
            'deployed_at' => time(),
            'deployed_by' => $deployedby,
            'status' => 'ok',
        ]);
        return $cmid;
    }

    /**
     * Último despliegue de un fichero en el curso (para pre-rellenar el
     * formulario de actualización), o null.
     */
    public static function latest_deploy(int $courseid, string $sourcefile): ?\stdClass {
        global $DB;
        $rows = $DB->get_records(
            'local_liamateriales_deploy',
            ['courseid' => $courseid, 'source_file' => $sourcefile],
            'deployed_at DESC, id DESC',
            '*',
            0,
            1
        );
        return $rows ? reset($rows) : null;
    }
}
