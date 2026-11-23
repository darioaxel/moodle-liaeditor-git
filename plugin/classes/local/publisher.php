<?php
// Publicación de artefactos en el curso (D6).

/**
 * Publisher: crea/actualiza el módulo del curso a partir del artefacto
 * generado del material (H5P, ePub, PDF) y registra el despliegue
 * curso↔SHA↔cmid en ``local_liamateriales_deploy`` (trazabilidad D6).
 *
 * Mapeo tipo → módulo: ``scorm`` → mod_scorm (SCORM 1.2 de liaex, mostrado
 * como contenido: sin página de intentos, índice ni navegación) ·
 * ``h5p`` → mod_h5pactivity · ``epub``/``pdf`` →
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
        'scorm' => ['scorm', 'packagefile'],
    ];

    /** Extensión del fichero de cada formato (SCORM es un .zip). */
    private const EXTENSIONS = [
        'h5p' => 'h5p',
        'epub' => 'epub',
        'pdf' => 'pdf',
        'scorm' => 'zip',
    ];

    /**
     * Ajustes de mod_scorm que su formulario toma de la configuración del
     * sitio; scorm_add_instance los lee, así que se rellenan igual.
     */
    private const SCORM_DEFAULTS = [
        'maxgrade', 'grademethod', 'whatgrade', 'maxattempt', 'forcecompleted', 'forcenewattempt',
        'lastattemptlock', 'displayattemptstatus', 'displaycoursestructure', 'popup', 'skipview',
        'hidebrowse', 'hidetoc', 'nav', 'navpositionleft', 'navpositiontop', 'collapsetocwinsize',
        'displayactivityname', 'auto', 'autocommit', 'masteryoverride',
    ];

    /** Formatos publicables (h5p, epub, pdf, scorm). */
    public static function types(): array {
        return array_keys(self::TYPES);
    }

    /** Extensión del fichero de un formato. */
    public static function extension(string $artifacttype): string {
        return self::EXTENSIONS[$artifacttype] ?? $artifacttype;
    }

    /**
     * Publica el artefacto subido (área de borrador del usuario) como módulo
     * del curso y registra el despliegue.
     *
     * @param int $courseid curso destino
     * @param int $section sección del curso (0 = general)
     * @param string $sourcefile ruta del .md en el repo
     * @param string $artifacttype h5p | epub | pdf | scorm
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
        if ($modulename === 'scorm') {
            self::apply_scorm_defaults($moduleinfo);
        }

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
     * Ajustes de visualización del SCORM: el material se ve como un
     * contenido, no como un examen. Valores de mod_scorm (lib.php/locallib.php):
     * SCORM_SKIPVIEW_ALWAYS = 2, SCORM_TOC_DISABLED = 3, SCORM_NAV_DISABLED = 0,
     * SCORM_DISPLAY_ATTEMPTSTATUS_NO = 0, SCORM_FORCEATTEMPT_NO = 0.
     */
    private const SCORM_DISPLAY = [
        'skipview' => 2,               // Entra directo al contenido, sin página de intentos.
        'displayattemptstatus' => 0,   // No muestra el estado de los intentos.
        'hidetoc' => 3,                // Sin índice de SCORM: todo el ancho para el contenido.
        'nav' => 0,                    // Sin botones de navegación de SCORM (LiaScript tiene los suyos).
        'displaycoursestructure' => 0, // Sin estructura del curso en la página de entrada.
        'hidebrowse' => 1,             // Sin modo «previsualizar».
        'displayactivityname' => 0,    // Sin el título encima del reproductor.
        'popup' => 0,                  // Ventana actual: el alto se ajusta al de la ventana.
        'width' => 100,                // 100 % (mod_scorm trata ≤ 100 como porcentaje).
        'height' => 100,
        'maxattempt' => 0,             // Intentos ilimitados.
        'forcenewattempt' => 0,
        'lastattemptlock' => 0,
    ];

    /**
     * Ajustes del módulo SCORM: los valores por defecto del sitio (como el
     * formulario de mod_scorm) y, encima, la visualización de contenido.
     *
     * @param \stdClass $siteconfig get_config('scorm')
     * @return array<string, mixed>
     */
    public static function scorm_settings(\stdClass $siteconfig): array {
        $settings = [
            'scormtype' => 'local',    // Paquete subido (SCORM_TYPE_LOCAL).
            'cmidnumber' => '',
            'timeopen' => 0,
            'timeclose' => 0,
            'updatefreq' => 0,
        ];
        foreach (self::SCORM_DEFAULTS as $key) {
            if (isset($siteconfig->$key)) {
                $settings[$key] = $siteconfig->$key;
            }
        }
        return array_merge($settings, self::SCORM_DISPLAY);
    }

    /** Aplica scorm_settings() al moduleinfo. */
    private static function apply_scorm_defaults(\stdClass $moduleinfo): void {
        foreach (self::scorm_settings(get_config('scorm')) as $key => $value) {
            $moduleinfo->$key = $value;
        }
    }

    /** Tiempo máximo de generación del artefacto (s). PDF con Chromium tarda ~20 s en frío. */
    private const GENERATE_TIMEOUT = 330;

    /**
     * Genera el artefacto en el engine (liaex) a partir del Markdown en Git.
     *
     * @param string $sourcefile ruta del .md en el repo
     * @param string $sha commit exacto a exportar
     * @param string $artifacttype h5p | epub | pdf | scorm
     * @return string bytes del artefacto
     * @throws \moodle_exception con el motivo si el engine no lo genera
     */
    public static function generate_artifact(string $sourcefile, string $sha, string $artifacttype): string {
        if (!isset(self::TYPES[$artifacttype])) {
            throw new \moodle_exception('invalidartifacttype', 'local_liamateriales', '', $artifacttype);
        }
        // La generación puede superar el límite de ejecución PHP por defecto.
        \core_php_time_limit::raise(self::GENERATE_TIMEOUT + 30);
        $curl = new \curl();
        $curl->setHeader('Content-Type: application/json');
        $curl->setHeader('Accept: application/octet-stream');
        $curl->setopt(['CURLOPT_TIMEOUT' => self::GENERATE_TIMEOUT, 'CURLOPT_CONNECTTIMEOUT' => 10]);
        $response = $curl->post(
            local_liamateriales_service_url() . '/publish',
            json_encode(['file' => $sourcefile, 'sha' => $sha, 'format' => $artifacttype])
        );
        $status = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($curl->get_errno() || $status !== 200 || !is_string($response) || $response === '') {
            $detail = $curl->get_errno() ? $curl->error : "HTTP {$status}";
            $decoded = json_decode(is_string($response) ? $response : '');
            if (is_object($decoded) && !empty($decoded->detail)) {
                $detail = $decoded->detail;
            }
            throw new \moodle_exception('publishgenerateerror', 'local_liamateriales', '', $detail);
        }
        return $response;
    }

    /**
     * Deja un fichero en el área de borrador del usuario (contrato del
     * filemanager de add_moduleinfo/update_moduleinfo) y devuelve el itemid.
     */
    public static function stage_draft(int $userid, string $filename, string $bytes): int {
        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($userid)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => clean_filename($filename),
        ], $bytes);
        return $draftitemid;
    }

    /** Nombre por defecto del módulo: el actual si ya existe, si no el del material y el formato. */
    public static function default_name(int $courseid, string $sourcefile, string $artifacttype): string {
        $cmid = self::reusable_cmid($courseid, $sourcefile, $artifacttype);
        $cms = get_fast_modinfo($courseid)->get_cms();
        return $cmid && isset($cms[$cmid])
            ? $cms[$cmid]->name
            : basename($sourcefile, '.md') . ' (' . strtoupper($artifacttype) . ')';
    }

    /**
     * Genera el artefacto desde Git y lo publica en el curso (un clic): usado
     * por el botón Publicar del editor y por la página Publicar del plugin.
     *
     * @return int cmid del módulo creado o actualizado
     */
    public static function publish_generated(
        int $courseid,
        int $section,
        string $sourcefile,
        string $artifacttype,
        string $sha,
        ?string $name,
        \stdClass $user
    ): int {
        $bytes = self::generate_artifact($sourcefile, $sha, $artifacttype);
        $draftitemid = self::stage_draft(
            (int) $user->id,
            basename($sourcefile, '.md') . '@' . substr($sha, 0, 8) . '.' . self::extension($artifacttype),
            $bytes
        );
        return self::publish(
            $courseid,
            $section,
            $sourcefile,
            $artifacttype,
            $sha,
            $draftitemid,
            $name ?? self::default_name($courseid, $sourcefile, $artifacttype),
            self::reusable_cmid($courseid, $sourcefile, $artifacttype),
            $user->username
        );
    }

    /**
     * Módulo que se puede actualizar al volver a publicar un fichero en un
     * formato: el del último despliegue de ese mismo formato, si el módulo
     * sigue existiendo en el curso y es del tipo esperado. 0 = crear uno nuevo
     * (primera publicación, otro formato o módulo borrado por el profesor).
     */
    public static function reusable_cmid(int $courseid, string $sourcefile, string $artifacttype): int {
        global $DB;
        if (!isset(self::TYPES[$artifacttype])) {
            return 0;
        }
        $rows = $DB->get_records(
            'local_liamateriales_deploy',
            ['courseid' => $courseid, 'source_file' => $sourcefile, 'artifact_type' => $artifacttype],
            'deployed_at DESC, id DESC',
            'id, cmid',
            0,
            1
        );
        $row = $rows ? reset($rows) : null;
        if (!$row) {
            return 0;
        }
        $cm = get_coursemodule_from_id(self::TYPES[$artifacttype][0], (int) $row->cmid, $courseid, false, IGNORE_MISSING);
        return $cm ? (int) $cm->id : 0;
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
