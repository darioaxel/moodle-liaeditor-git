<?php
// Publicación de artefactos en el curso (D6).

/**
 * Publisher: crea/actualiza el módulo del curso a partir del artefacto
 * generado del material (H5P, ePub, PDF) y registra el despliegue
 * curso↔SHA↔cmid en ``local_liamateriales_deploy`` (trazabilidad D6).
 *
 * Mapeo tipo → módulo: ``h5p`` → mod_h5pactivity · ``epub``/``pdf``/``pdfcontenido`` →
 * mod_resource (descarga accesible) · ``web`` → mod_resource con todos los
 * ficheros de la web estática (Pandoc + lector LiaScript) e ``index.html``
 * como fichero principal, abierta en ventana emergente como los sitios de
 * eXeLearning (configurable; docs/generacion_web).
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
        'web' => ['resource', 'files'],
        'pdfcontenido' => ['resource', 'files'],
    ];

    /** Extensión del fichero de cada formato (la web es un .zip). */
    private const EXTENSIONS = [
        'h5p' => 'h5p',
        'epub' => 'epub',
        'pdf' => 'pdf',
        'web' => 'zip',
        'pdfcontenido' => 'pdf',
    ];

    /** Visualización por defecto de la web (RESOURCELIB_DISPLAY_POPUP, como eXeLearning). */
    public const WEB_DISPLAY_DEFAULT = 6;

    /** Modos de visualización ofrecidos para la web: automático, incrustar, abrir, emergente, nueva ventana. */
    public const WEB_DISPLAYS = [0, 1, 5, 6, 3];

    /** Formatos en los que el autor lo decide el plugin y no la cabecera del material. */
    private const AUTHOR_TYPES = ['web', 'pdfcontenido'];

    /** Formatos con portada: título del módulo (el curso) y la unidad como subtítulo. */
    private const MODULE_TYPES = ['pdfcontenido'];

    /** Avisos del lector LiaScript de la última generación (cabecera X-Liamat-Avisos). */
    private static array $lastavisos = [];

    /** Formatos publicables (h5p, epub, pdf, web, pdfcontenido). */
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
     * @param string $artifacttype h5p | epub | pdf | web | pdfcontenido
     * @param string $sha SHA del material publicado (trazabilidad)
     * @param int $draftitemid área de borrador con el paquete subido
     * @param string $name nombre visible del módulo
     * @param int $existingcmid cmid a actualizar (0 = crear nuevo)
     * @param string $deployedby username que publica
     * @param string $branch rama de la versión publicada ('' si no se conoce)
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
        string $deployedby,
        string $branch = ''
    ): int {
        global $DB, $CFG, $USER;
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
        if ($artifacttype === 'web') {
            foreach (self::web_settings(get_config('local_liamateriales')) as $key => $value) {
                $moduleinfo->$key = $value;
            }
        } else if ($modulename === 'resource') {
            // PDF (contenido web), PDF (LaTeX) y ePub: la visualización por defecto del
            // sitio para Recurso, como en el formulario. Sin `display`,
            // resource_add_instance() avisaba en mitad de la respuesta JSON
            // («No se pudo completar: HTTP 200» al publicar por primera vez).
            foreach (self::resource_defaults(get_config('resource')) as $key => $value) {
                $moduleinfo->$key = $value;
            }
        }

        if ($existingcmid > 0) {
            $cm = get_coursemodule_from_id('', $existingcmid, 0, false, MUST_EXIST);
            $moduleinfo->id = $cm->instance;
            $moduleinfo->coursemodule = $cm->id;
            $moduleinfo->cmidnumber = $cm->idnumber;
            $moduleinfo->introeditor = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0];
            if ($modulename === 'resource') {
                // resource_update_instance() hace revision++ sobre lo recibido
                // (el formulario lo manda oculto). Sin él, PHP avisa en mitad
                // de la respuesta JSON y la revisión vuelve a 1, de modo que
                // las URL de los ficheros no cambian y el navegador sirve la
                // versión anterior de su caché.
                $moduleinfo->revision = (int) $DB->get_field('resource', 'revision', ['id' => $cm->instance]);
            }
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
            'branch' => $branch,
            'artifact_type' => $artifacttype,
            'cmid' => $cmid,
            'deployed_at' => time(),
            'deployed_by' => $deployedby,
            'status' => 'ok',
        ]);
        // Auditoría (LIA-069): visible en los informes del curso.
        \local_liamateriales\event\material_published::fire((int) $USER->id, $courseid, [
            'file' => $sourcefile,
            'sha' => $sha,
            'format' => $artifacttype,
            'cmid' => $cmid,
        ]);
        return $cmid;
    }

    /**
     * Ajustes de mod_resource para PDF (contenido web), PDF (LaTeX) y ePub: los valores
     * por defecto del sitio para Recurso (como el formulario), sin descripción.
     *
     * @param \stdClass $config get_config('resource')
     * @return array<string, int>
     */
    public static function resource_defaults(\stdClass $config): array {
        return [
            'display' => (int) ($config->display ?? 0),
            'popupwidth' => (int) ($config->popupwidth ?? 620),
            'popupheight' => (int) ($config->popupheight ?? 450),
            'printintro' => 0,
            'showsize' => (int) !empty($config->showsize),
            'showtype' => (int) !empty($config->showtype),
            'showdate' => (int) !empty($config->showdate),
            'filterfiles' => (int) ($config->filterfiles ?? 0),
        ];
    }

    /**
     * Ajustes de mod_resource para la web: visualización de los ajustes del
     * plugin (por defecto, ventana emergente de 1200×600 como eXeLearning),
     * sin filtros sobre los ficheros (el HTML ya está generado) ni descripción.
     *
     * @param \stdClass $config get_config('local_liamateriales')
     * @return array<string, int>
     */
    public static function web_settings(\stdClass $config): array {
        $display = isset($config->web_display) ? (int) $config->web_display : self::WEB_DISPLAY_DEFAULT;
        if (!in_array($display, self::WEB_DISPLAYS, true)) {
            $display = self::WEB_DISPLAY_DEFAULT;
        }
        $width = (int) ($config->web_popupwidth ?? 0);
        $height = (int) ($config->web_popupheight ?? 0);
        return [
            'display' => $display,
            'popupwidth' => $width > 0 ? $width : 1200,
            'popupheight' => $height > 0 ? $height : 600,
            'printintro' => 0,
            'showsize' => 0,
            'showtype' => 0,
            'showdate' => 0,
            'filterfiles' => 0,
        ];
    }

    /** Tiempo máximo de generación del artefacto (s). PDF con Chromium tarda ~20 s en frío. */
    private const GENERATE_TIMEOUT = 330;

    /**
     * Genera el artefacto en el engine (liaex) a partir del Markdown en Git.
     *
     * @param string $sourcefile ruta del .md en el repo
     * @param string $sha commit exacto a exportar
     * @param string $artifacttype h5p | epub | pdf | web | pdfcontenido
     * @param string|null $author autor que figura en lo generado (ver author_name());
     *     solo se envía en los formatos de AUTHOR_TYPES
     * @param string|null $module título del módulo para la portada (ver module_title());
     *     solo se envía en los formatos de MODULE_TYPES
     * @return string bytes del artefacto (los avisos del lector, en last_avisos())
     * @throws \moodle_exception con el motivo si el engine no lo genera
     */
    public static function generate_artifact(
        string $sourcefile,
        string $sha,
        string $artifacttype,
        ?string $author = null,
        ?string $module = null
    ): string {
        if (!isset(self::TYPES[$artifacttype])) {
            throw new \moodle_exception('invalidartifacttype', 'local_liamateriales', '', $artifacttype);
        }
        self::$lastavisos = [];
        // La generación puede superar el límite de ejecución PHP por defecto.
        \core_php_time_limit::raise(self::GENERATE_TIMEOUT + 30);
        $body = ['file' => $sourcefile, 'sha' => $sha, 'format' => $artifacttype];
        if ($author !== null && trim($author) !== '' && in_array($artifacttype, self::AUTHOR_TYPES, true)) {
            $body['author'] = \core_text::substr(trim($author), 0, 200);
        }
        if ($module !== null && trim($module) !== '' && in_array($artifacttype, self::MODULE_TYPES, true)) {
            $body['module'] = \core_text::substr(trim($module), 0, 255);
        }
        $result = engine_client::send(
            'POST',
            '/publish',
            [],
            $body,
            'application/octet-stream',
            ['CURLOPT_TIMEOUT' => self::GENERATE_TIMEOUT, 'CURLOPT_CONNECTTIMEOUT' => 10]
        );
        if ($result->errno || $result->status !== 200 || $result->body === '') {
            $detail = $result->errno ? $result->error : "HTTP {$result->status}";
            $decoded = json_decode($result->body);
            if (is_object($decoded) && !empty($decoded->detail)) {
                $detail = $decoded->detail;
            }
            throw new \moodle_exception('publishgenerateerror', 'local_liamateriales', '', $detail);
        }
        self::$lastavisos = self::parse_avisos($result->headers);
        return $result->body;
    }

    /**
     * Autor que figura en el contenido generado.
     *
     * - Versión oficial (``main``): la institución («FPVirtual Aragón»,
     *   cadena ``publish_author_default``).
     * - Rama de edición: nombre y apellidos del usuario de Moodle autor del
     *   último commit que modificó el material (el engine commitea los
     *   guardados con el ``username`` como autor, ``last_author`` de
     *   ``/edit/content``). Si no es un usuario de Moodle (p. ej. un cambio
     *   hecho directamente en Git), la institución.
     *
     * @param string $versionkey ``main`` o ``branch`` (página Publicar)
     * @param string|null $lastauthor autor Git del último commit del material
     */
    public static function author_name(string $versionkey, ?string $lastauthor): string {
        global $CFG, $DB;
        $default = get_string('publish_author_default', 'local_liamateriales');
        if ($versionkey === 'main' || $lastauthor === null || trim($lastauthor) === '') {
            return $default;
        }
        $user = $DB->get_record('user', [
            'username' => \core_text::strtolower(trim($lastauthor)),
            'mnethostid' => $CFG->mnet_localhost_id,
            'deleted' => 0,
        ]);
        return $user ? fullname($user) : $default;
    }

    /**
     * Título del módulo para la portada del PDF (LaTeX): el nombre completo
     * del curso, como texto plano (filtros de idioma aplicados, sin HTML).
     */
    public static function module_title(int $courseid): string {
        $course = get_course($courseid);
        $name = format_string($course->fullname, true, ['context' => \context_course::instance($courseid)]);
        return trim(html_entity_decode(strip_tags($name), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Avisos del lector LiaScript (sintaxis que la web o el PDF no
     * representan) de la cabecera ``X-Liamat-Avisos`` (lista JSON).
     *
     * @param array $headers cabeceras de la respuesta (curl::getResponse)
     * @return string[]
     */
    public static function parse_avisos(array $headers): array {
        foreach ($headers as $key => $value) {
            if (strcasecmp(trim((string) $key), 'x-liamat-avisos') === 0) {
                $decoded = json_decode(trim((string) $value));
                return is_array($decoded) ? array_values(array_map('strval', array_filter($decoded, 'is_scalar'))) : [];
            }
        }
        return [];
    }

    /** Avisos del lector de la última generación (vacío si no hubo o no aplica). */
    public static function last_avisos(): array {
        return self::$lastavisos;
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

    /**
     * Deja el artefacto en el área de borrador según su formato: la web se
     * descomprime (todos sus ficheros, con ``index.html`` como principal); el
     * resto es un único fichero.
     */
    public static function stage_artifact(int $userid, string $filename, string $bytes, string $artifacttype): int {
        return $artifacttype === 'web' ? self::stage_web($userid, $bytes) : self::stage_draft($userid, $filename, $bytes);
    }

    /**
     * Descomprime la web en un área de borrador. El extractor de Moodle
     * (zip_packer) limpia las rutas (sin «..» ni rutas absolutas).
     * ``index.html`` lleva sortorder 1: mod_resource lo toma como principal.
     *
     * @throws \moodle_exception si el zip no es válido o no tiene index.html
     */
    public static function stage_web(int $userid, string $bytes): int {
        $zippath = make_request_directory() . '/web.zip';
        file_put_contents($zippath, $bytes);
        $draftitemid = file_get_unused_draft_itemid();
        $contextid = \context_user::instance($userid)->id;
        $result = get_file_packer('application/zip')->extract_to_storage(
            $zippath, $contextid, 'user', 'draft', $draftitemid, '/', $userid
        );
        $fs = get_file_storage();
        if ($result === false || !$fs->file_exists($contextid, 'user', 'draft', $draftitemid, '/', 'index.html')) {
            throw new \moodle_exception('publishwebinvalid', 'local_liamateriales');
        }
        file_set_sortorder($contextid, 'user', 'draft', $draftitemid, '/', 'index.html', 1);
        return $draftitemid;
    }

    /** Nombre por defecto del módulo: el actual si ya existe, si no el del material y el formato. */
    public static function default_name(int $courseid, string $sourcefile, string $artifacttype): string {
        $cmid = self::reusable_cmid($courseid, $sourcefile, $artifacttype);
        $cms = get_fast_modinfo($courseid)->get_cms();
        return $cmid && isset($cms[$cmid])
            ? $cms[$cmid]->name
            : basename($sourcefile, '.md') . ' · ' . self::label($artifacttype);
    }

    /** Etiqueta corta de un formato (nombres de módulo y progreso). */
    public static function label(string $artifacttype): string {
        // PDF: liaex imprime el contenido tal como se ve en la web; el de
        // Pandoc se maqueta con LaTeX (XeLaTeX).
        return [
            'web' => 'Web',
            'pdf' => 'PDF (contenido web)',
            'pdfcontenido' => 'PDF (LaTeX)',
            'epub' => 'ePub',
        ][$artifacttype] ?? strtoupper($artifacttype);
    }

    /**
     * Genera el artefacto desde Git y lo publica en el curso (página Publicar
     * del plugin; el botón del editor y publish_api.php se retiraron en 2.8.0).
     *
     * @param string $branch rama de la versión publicada ('' si no se conoce)
     * @param string|null $author autor de lo generado (ver author_name())
     * @return int cmid del módulo creado o actualizado
     */
    public static function publish_generated(
        int $courseid,
        int $section,
        string $sourcefile,
        string $artifacttype,
        string $sha,
        ?string $name,
        \stdClass $user,
        string $branch = '',
        ?string $author = null
    ): int {
        // Una publicación a la vez por usuario y material (LIA-065): dos clics
        // seguidos no crean dos módulos ni lanzan dos generaciones.
        $lock = \core\lock\lock_config::get_lock_factory('local_liamateriales')
            ->get_lock('publish_' . $user->id . '_' . sha1($sourcefile), 0);
        if (!$lock) {
            throw new \moodle_exception('publishbusy', 'local_liamateriales');
        }
        try {
            return self::generate_and_publish(
                $courseid, $section, $sourcefile, $artifacttype, $sha, $name, $user, $branch, $author
            );
        } finally {
            $lock->release();
        }
    }

    /** Genera y publica (dentro del bloqueo de publish_generated). */
    private static function generate_and_publish(
        int $courseid,
        int $section,
        string $sourcefile,
        string $artifacttype,
        string $sha,
        ?string $name,
        \stdClass $user,
        string $branch,
        ?string $author
    ): int {
        $bytes = self::generate_artifact($sourcefile, $sha, $artifacttype, $author, self::module_title($courseid));
        $draftitemid = self::stage_artifact(
            (int) $user->id,
            basename($sourcefile, '.md') . '@' . substr($sha, 0, 8) . '.' . self::extension($artifacttype),
            $bytes,
            $artifacttype
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
            $user->username,
            $branch
        );
    }

    /**
     * Despliegues vivos de un curso: el más reciente por fichero y formato
     * cuyo módulo sigue en el curso y es del tipo esperado.
     *
     * La tabla de despliegues es historial (trazabilidad D6) y no se borra
     * cuando el profesor elimina la actividad: aquí se descartan las filas
     * de módulos borrados o en borrado (``deletioninprogress``, la actividad
     * ya no se ve en el curso aunque el cron aún no la haya eliminado).
     *
     * @param int $courseid curso
     * @param string $sourcefile solo este fichero ('' = todos)
     * @return \stdClass[] filas de ``local_liamateriales_deploy``, de la más reciente a la más antigua
     */
    public static function live_deploys(int $courseid, string $sourcefile = ''): array {
        global $DB;
        $conditions = ['courseid' => $courseid];
        if ($sourcefile !== '') {
            $conditions['source_file'] = $sourcefile;
        }
        $rows = $DB->get_records('local_liamateriales_deploy', $conditions, 'deployed_at DESC, id DESC');
        if (!$rows) {
            return [];
        }
        $cmids = array_values(array_unique(array_map(static fn($row) => (int) $row->cmid, $rows)));
        [$insql, $params] = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);
        $params['courseid'] = $courseid;
        // Módulos vivos del curso: cmid => nombre del tipo de módulo.
        $alive = $DB->get_records_sql_menu(
            "SELECT cm.id, m.name
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id $insql AND cm.course = :courseid AND cm.deletioninprogress = 0",
            $params
        );
        $live = [];
        foreach ($rows as $row) {
            $key = $row->source_file . "\0" . $row->artifact_type;
            $modname = $alive[(int) $row->cmid] ?? null;
            $expected = self::TYPES[$row->artifact_type][0] ?? $modname;
            if (isset($live[$key]) || $modname === null || $modname !== $expected) {
                continue;
            }
            $live[$key] = $row;
        }
        return array_values($live);
    }

    /**
     * Módulo que se puede actualizar al volver a publicar un fichero en un
     * formato: el del último despliegue vivo de ese mismo formato. 0 = crear
     * uno nuevo (primera publicación, otro formato o módulo borrado por el
     * profesor).
     */
    public static function reusable_cmid(int $courseid, string $sourcefile, string $artifacttype): int {
        if (!isset(self::TYPES[$artifacttype])) {
            return 0;
        }
        foreach (self::live_deploys($courseid, $sourcefile) as $row) {
            if ($row->artifact_type === $artifacttype) {
                return (int) $row->cmid;
            }
        }
        return 0;
    }

    /**
     * Último despliegue vivo de un fichero en el curso (para pre-rellenar el
     * formulario de actualización), o null si no está publicado o se borró
     * su actividad.
     */
    public static function latest_deploy(int $courseid, string $sourcefile): ?\stdClass {
        return self::live_deploys($courseid, $sourcefile)[0] ?? null;
    }
}
