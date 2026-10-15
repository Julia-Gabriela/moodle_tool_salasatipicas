<?php
// This file is part of Moodle - http://moodle.org/

namespace tool_salasatipicas\local;

defined('MOODLE_INTERNAL') || die();

/** Core business logic for REA. */
class rea_manager {
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY = 'ready';
    public const STATUS_ERROR = 'error';

    public const DEFAULT_FULLNAME_PATTERN = 'PROCESSO N° {PROCESSO} - {ALUNO} - {SIGLA_CURSO}- {TURNO}{SEMESTRE}{TURMA} - {PERIODO_CURTO} - REA';
    public const DEFAULT_SHORTNAME_PATTERN = 'PROCESSO N° {PROCESSO} - {ALUNO} - {SIGLA_CURSO}- {TURNO}{SEMESTRE}{TURMA} - {PERIODO_CURTO} - REA';
    public const MAX_FULLNAME_LENGTH = 1333;
    public const MAX_SHORTNAME_LENGTH = 255;

    public static function get_config_value(string $name, $default = null) {
        $value = get_config('tool_salasatipicas', 'rea_' . $name);
        return $value === false ? $default : $value;
    }

    public static function creation_enabled(): bool {
        return (bool)(int)self::get_config_value('creationenabled', 1);
    }

    public static function get_categories(bool $activeonly = true): array {
        global $DB;
        return $DB->get_records('tool_salasat_rea_cat', $activeonly ? ['active' => 1] : [], 'sortorder ASC, name ASC');
    }

    public static function get_category_options(): array {
        $options = [];
        foreach (self::get_categories(true) as $category) {
            $options[$category->id] = $category->name . ' (ID Moodle: ' . $category->categoryid . ')';
        }
        return $options;
    }

    public static function get_course_role_options(): array {
        global $DB;
        $options = [];
        foreach ($DB->get_records('role', null, 'sortorder ASC, name ASC') as $role) {
            if (!$DB->record_exists('role_context_levels', ['roleid' => $role->id, 'contextlevel' => CONTEXT_COURSE])) {
                continue;
            }
            $options[$role->id] = role_get_name($role, \context_system::instance(), ROLENAME_ORIGINAL) . ' (' . $role->shortname . ')';
        }
        return $options;
    }

    public static function role_id_by_shortname(string $shortname): int {
        global $DB;
        return (int)$DB->get_field('role', 'id', ['shortname' => $shortname], IGNORE_MISSING);
    }

    /** Validate form data and build a durable creation definition. */
    public static function prepare_definition($data): array {
        global $CFG, $DB;
        $data = (array)$data;
        $errors = [];

        foreach (['studentdisplayname', 'courseprogram', 'processnumber', 'courseabbr', 'shift', 'semester', 'classgroup', 'contactemail', 'period', 'categoryconfigid', 'moodlestudentuserid'] as $field) {
            if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
                $errors[] = get_string('rea_missingfield', 'tool_salasatipicas', $field);
            }
        }
        if (empty($data['licensestart']) || empty($data['licenseend'])) {
            $errors[] = get_string('rea_missinglicenseperiod', 'tool_salasatipicas');
        }
        if ($errors) {
            return ['valid' => false, 'errors' => array_values(array_unique($errors)), 'definition' => null];
        }

        $studentdisplayname = trim((string)$data['studentdisplayname']);
        $courseprogram = trim((string)$data['courseprogram']);
        $processnumber = self::normalise_process_number((string)$data['processnumber']);
        $courseabbr = strtoupper(trim((string)$data['courseabbr']));
        $shift = strtoupper(trim((string)$data['shift']));
        $semester = (int)$data['semester'];
        $classgroup = strtoupper(trim((string)$data['classgroup']));
        $phone = trim((string)($data['phone'] ?? ''));
        $contactemail = trim((string)$data['contactemail']);
        $period = self::normalise_period((string)$data['period']);
        $licensestart = (int)$data['licensestart'];
        $licenseend = (int)$data['licenseend'];

        if (!validate_email($contactemail)) {
            $errors[] = get_string('rea_invalidcontactemail', 'tool_salasatipicas');
        }
        if (!preg_match('/^(?:01|02)\/(?:19|20)\d{2}$/', $period)) {
            $errors[] = get_string('rea_invalidperiod', 'tool_salasatipicas');
        }
        if ($licenseend < $licensestart) {
            $errors[] = get_string('rea_invalidlicensedates', 'tool_salasatipicas');
        }
        if (\core_text::strlen($studentdisplayname) > 255 || \core_text::strlen($courseprogram) > 255) {
            $errors[] = get_string('rea_fieldtoolong', 'tool_salasatipicas');
        }
        if ($processnumber === '' || !preg_match('/^[0-9]+(?:\/[0-9]{4})?$/', $processnumber)) {
            $errors[] = get_string('rea_invalidprocessnumber', 'tool_salasatipicas');
        }
        if ($courseabbr === '' || !preg_match('/^[A-Z0-9_-]{1,20}$/', $courseabbr)) {
            $errors[] = get_string('rea_invalidcourseabbr', 'tool_salasatipicas');
        }
        if (!in_array($shift, ['MAT', 'NOT'], true)) {
            $errors[] = get_string('rea_invalidshift', 'tool_salasatipicas');
        }
        if ($semester < 1 || $semester > 10) {
            $errors[] = get_string('rea_invalidsemester', 'tool_salasatipicas');
        }
        if (!in_array($classgroup, ['A', 'B'], true)) {
            $errors[] = get_string('rea_invalidclassgroup', 'tool_salasatipicas');
        }

        $category = $DB->get_record('tool_salasat_rea_cat', ['id' => (int)$data['categoryconfigid'], 'active' => 1]);
        if (!$category) {
            $errors[] = get_string('rea_invalidconfiguredcategory', 'tool_salasatipicas');
        } else if (!$DB->record_exists('course_categories', ['id' => $category->categoryid])) {
            $errors[] = get_string('rea_invalidmoodlecategory', 'tool_salasatipicas');
        }

        $templateid = $category ? (int)$category->templatecourseid : 0;
        if ($templateid <= 0) {
            $templateid = (int)self::get_config_value('defaulttemplateid', 0);
        }
        if ($templateid <= 0 || !$DB->record_exists('course', ['id' => $templateid])) {
            $errors[] = get_string('rea_templatenotconfigured', 'tool_salasatipicas');
        }

        $student = $DB->get_record('user', ['id' => (int)$data['moodlestudentuserid'], 'deleted' => 0]);
        if (!$student || $student->suspended) {
            $errors[] = get_string('rea_studentusernotfound', 'tool_salasatipicas');
        }

        $studentroleid = (int)self::get_config_value('studentroleid', self::role_id_by_shortname('student'));
        $teacherroleid = (int)self::get_config_value('teacherroleid', self::role_id_by_shortname('editingteacher'));
        $coordroleid = (int)self::get_config_value('coordinationroleid', $teacherroleid);
        foreach ([['id' => $studentroleid, 'key' => 'studentrolenotconfigured'], ['id' => $teacherroleid, 'key' => 'teacherrolenotconfigured']] as $check) {
            if ($check['id'] <= 0 || !$DB->record_exists('role_context_levels', ['roleid' => $check['id'], 'contextlevel' => CONTEXT_COURSE])) {
                $errors[] = get_string('rea_' . $check['key'], 'tool_salasatipicas');
            }
        }

        $coordids = self::normalise_userid_list($data['coordinationuserids'] ?? []);
        $coordinators = self::resolve_users($coordids, 'coordination', $errors);
        if ($coordinators && ($coordroleid <= 0 || !$DB->record_exists('role_context_levels', ['roleid' => $coordroleid, 'contextlevel' => CONTEXT_COURSE]))) {
            $errors[] = get_string('rea_coordinationrolenotconfigured', 'tool_salasatipicas');
        }

        $subjectnames = is_array($data['subjectname'] ?? null) ? $data['subjectname'] : [];
        $subjectteachersraw = is_array($data['subjectteacheruserids'] ?? null) ? $data['subjectteacheruserids'] : [];
        $subjects = [];
        $allteachers = [];
        foreach ($subjectnames as $idx => $rawname) {
            $name = trim((string)$rawname);
            if ($name === '') {
                continue;
            }
            if (\core_text::strlen($name) > 255) {
                $errors[] = get_string('rea_subjecttoolong', 'tool_salasatipicas', $idx + 1);
                continue;
            }
            $ids = self::normalise_userid_list($subjectteachersraw[$idx] ?? []);
            if (!$ids) {
                $errors[] = get_string('rea_subjectteacherrequired', 'tool_salasatipicas', $idx + 1);
                continue;
            }
            $teachers = self::resolve_users($ids, 'teacher', $errors);
            if (!$teachers) {
                continue;
            }
            $teacherdefs = [];
            foreach ($teachers as $teacher) {
                $teacherdefs[] = [
                    'userid' => (int)$teacher->id,
                    'username' => (string)$teacher->username,
                    'fullname' => fullname($teacher),
                ];
                $allteachers[(int)$teacher->id] = $teacher;
            }
            $subjects[] = ['name' => $name, 'teachers' => $teacherdefs];
        }
        if (!$subjects) {
            $errors[] = get_string('rea_atleastonesubject', 'tool_salasatipicas');
        }

        require_once($CFG->libdir . '/enrollib.php');
        if (!enrol_get_plugin('manual')) {
            $errors[] = get_string('rea_manualenrolmissing', 'tool_salasatipicas');
        }

        if ($errors) {
            return ['valid' => false, 'errors' => array_values(array_unique($errors)), 'definition' => null];
        }

        $periodshort = preg_replace('/^0([12])\//', '$1/', $period);
        $variables = [
            '{ALUNO}' => $studentdisplayname,
            '{CURSO}' => $courseprogram,
            '{PROCESSO}' => $processnumber,
            '{SIGLA_CURSO}' => $courseabbr,
            '{TURNO}' => $shift,
            '{SEMESTRE}' => (string)$semester,
            '{TURMA}' => $classgroup,
            '{PERIODO}' => $period,
            '{PERIODO_CURTO}' => $periodshort,
            '{INICIO_LICENCA}' => userdate($licensestart, '%d/%m/%Y'),
            '{FIM_LICENCA}' => userdate($licenseend, '%d/%m/%Y'),
            '{USERNAME_ALUNO}' => (string)$student->username,
            '{CATEGORIA}' => (string)$category->name,
        ];
        $fullname = trim(strtr((string)self::get_config_value('fullnamepattern', self::DEFAULT_FULLNAME_PATTERN), $variables));
        $shortname = self::normalise_shortname(strtr((string)self::get_config_value('shortnamepattern', self::DEFAULT_SHORTNAME_PATTERN), $variables));
        if ($fullname === '' || \core_text::strlen($fullname) > self::MAX_FULLNAME_LENGTH) {
            $errors[] = get_string('rea_invalidgeneratedfullname', 'tool_salasatipicas');
        }
        if ($shortname === '' || \core_text::strlen($shortname) > self::MAX_SHORTNAME_LENGTH) {
            $errors[] = get_string('rea_invalidgeneratedshortname', 'tool_salasatipicas');
        }
        if ($DB->record_exists('course', ['shortname' => $shortname]) ||
                $DB->record_exists_select('tool_salasat_rea_hist', 'targetshortname = ? AND status <> ?', [$shortname, self::STATUS_ERROR])) {
            $errors[] = get_string('rea_duplicatecourse', 'tool_salasatipicas', $shortname);
        }
        if ($errors) {
            return ['valid' => false, 'errors' => array_values(array_unique($errors)), 'definition' => null];
        }

        $participants = [];
        $sort = 0;
        $participants[] = self::participant_definition($student, 'student', $studentroleid, $sort++);
        foreach ($allteachers as $teacher) {
            $participants[] = self::participant_definition($teacher, 'teacher', $teacherroleid, $sort++);
        }
        foreach ($coordinators as $coordinator) {
            $participants[] = self::participant_definition($coordinator, 'coordination', $coordroleid, $sort++);
        }
        // Keep intentional multi-role assignment, remove only duplicate userid+role pairs.
        $seen = [];
        $unique = [];
        foreach ($participants as $participant) {
            $key = $participant['userid'] . ':' . $participant['roleid'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $participant;
            }
        }

        return ['valid' => true, 'errors' => [], 'definition' => [
            'studentdisplayname' => $studentdisplayname,
            'courseprogram' => $courseprogram,
            'processnumber' => $processnumber,
            'courseabbr' => $courseabbr,
            'shift' => $shift,
            'semester' => $semester,
            'classgroup' => $classgroup,
            'phone' => $phone,
            'contactemail' => $contactemail,
            'licensestart' => $licensestart,
            'licenseend' => $licenseend,
            'period' => $period,
            'subjects' => $subjects,
            'participants' => $unique,
            'moodlestudentuserid' => (int)$student->id,
            'moodlestudentusername' => (string)$student->username,
            'moodlestudentname' => fullname($student),
            'categoryconfigid' => (int)$category->id,
            'coursecategoryid' => (int)$category->categoryid,
            'templatecourseid' => $templateid,
            'targetfullname' => $fullname,
            'targetshortname' => $shortname,
            'visible' => (int)self::get_config_value('coursevisible', 0),
        ]];
    }

    private static function normalise_userid_list($values): array {
        if (!is_array($values)) {
            $values = [$values];
        }
        return array_values(array_unique(array_filter(array_map('intval', $values), fn($id) => $id > 0)));
    }

    private static function resolve_users(array $ids, string $type, array &$errors): array {
        global $DB;
        $users = [];
        foreach ($ids as $id) {
            $user = $DB->get_record('user', ['id' => $id, 'deleted' => 0]);
            if (!$user || $user->suspended) {
                $errors[] = get_string('rea_' . $type . 'usernotfound', 'tool_salasatipicas', $id);
                continue;
            }
            $users[$id] = $user;
        }
        return array_values($users);
    }

    private static function participant_definition(\stdClass $user, string $type, int $roleid, int $sortorder): array {
        return [
            'userid' => (int)$user->id,
            'username' => (string)$user->username,
            'fullname' => fullname($user),
            'participanttype' => $type,
            'roleid' => $roleid,
            'sortorder' => $sortorder,
        ];
    }

    public static function normalise_process_number(string $process): string {
        $process = trim($process);
        $process = preg_replace('/^PROCESSO\s*N[º°o]?\s*/iu', '', $process);
        $process = preg_replace('/\s+/', '', $process);
        return trim((string)$process, '-:');
    }

    public static function normalise_period(string $value): string {
        $value = trim(ltrim($value, "'\" "));
        if (preg_match('/^([12])\/(\d{4})$/', $value, $m)) {
            return sprintf('%02d/%04d', (int)$m[1], (int)$m[2]);
        }
        if (preg_match('/^(0[12])\/(\d{4})$/', $value, $m)) {
            return $m[1] . '/' . $m[2];
        }
        return $value;
    }

    public static function normalise_shortname(string $value): string {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', trim($value));
        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    public static function create_request(array $definition, string $source = 'manual'): int {
        global $DB, $USER;
        if (!self::creation_enabled()) {
            throw new \moodle_exception('rea_creationdisabled', 'tool_salasatipicas');
        }
        self::assert_worker_available();

        $factory = \core\lock\lock_config::get_lock_factory('tool_salasatipicas');
        $targetlock = $factory->get_lock('target_' . sha1($definition['targetshortname']), 10);
        if (!$targetlock) {
            throw new \moodle_exception('rea_targetbusy', 'tool_salasatipicas');
        }
        try {
            if ($DB->record_exists('course', ['shortname' => $definition['targetshortname']]) ||
                    $DB->record_exists_select('tool_salasat_rea_hist', 'targetshortname = ? AND status <> ?', [$definition['targetshortname'], self::STATUS_ERROR])) {
                throw new \moodle_exception('rea_duplicatecourse', 'tool_salasatipicas', '', $definition['targetshortname']);
            }
            $now = time();
            $transaction = $DB->start_delegated_transaction();
            $historyid = $DB->insert_record('tool_salasat_rea_hist', (object)[
                'studentdisplayname' => $definition['studentdisplayname'],
                'courseprogram' => $definition['courseprogram'],
                'phone' => $definition['phone'],
                'contactemail' => $definition['contactemail'],
                'licensestart' => $definition['licensestart'],
                'licenseend' => $definition['licenseend'],
                'period' => $definition['period'],
                'subjectsjson' => json_encode($definition['subjects'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sourcecourseid' => $definition['templatecourseid'],
                'coursecategoryid' => $definition['coursecategoryid'],
                'categoryconfigid' => $definition['categoryconfigid'],
                'targetcourseid' => 0,
                'targetfullname' => $definition['targetfullname'],
                'targetshortname' => $definition['targetshortname'],
                'source' => $source,
                'status' => self::STATUS_QUEUED,
                'message' => get_string('rea_queuedmessage', 'tool_salasatipicas'),
                'backupid' => '',
                'restoreid' => '',
                'createdby' => (int)$USER->id,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            foreach ($definition['participants'] as $participant) {
                $DB->insert_record('tool_salasat_rea_part', (object)[
                    'historyid' => $historyid,
                    'userid' => $participant['userid'],
                    'username' => $participant['username'],
                    'fullname' => $participant['fullname'],
                    'participanttype' => $participant['participanttype'],
                    'roleid' => $participant['roleid'],
                    'sortorder' => $participant['sortorder'],
                    'timecreated' => $now,
                ]);
            }
            $transaction->allow_commit();
            return $historyid;
        } finally {
            $targetlock->release();
        }
    }

    /** Detect whether the proven detached CLI architecture can run. */
    public static function get_worker_health(): array {
        global $CFG;
        $script = $CFG->dirroot . '/' . $CFG->admin . '/tool/salasatipicas/cli/create_rea.php';
        if (!is_file($script)) {
            return ['available' => false, 'php' => '', 'launcher' => '', 'nohup' => '', 'reason' => get_string('rea_workerscriptmissing', 'tool_salasatipicas')];
        }
        $php = self::detect_php_cli();
        if ($php === '') {
            return ['available' => false, 'php' => '', 'launcher' => '', 'nohup' => '', 'reason' => get_string('rea_workercliproductionrequired', 'tool_salasatipicas')];
        }
        if (PHP_OS_FAMILY === 'Windows') {
            if (!self::function_enabled('popen')) {
                return ['available' => false, 'php' => $php, 'launcher' => '', 'nohup' => '', 'reason' => get_string('rea_workerlaunchfunctionmissing', 'tool_salasatipicas')];
            }
            return ['available' => true, 'php' => $php, 'launcher' => 'popen', 'nohup' => '', 'reason' => ''];
        }
        $launcher = self::function_enabled('exec') ? 'exec' : (self::function_enabled('shell_exec') ? 'shell_exec' : '');
        $nohup = '';
        foreach (['/usr/bin/nohup', '/bin/nohup'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                $nohup = $candidate;
                break;
            }
        }
        if ($launcher === '' || $nohup === '') {
            return ['available' => false, 'php' => $php, 'launcher' => $launcher, 'nohup' => $nohup, 'reason' => get_string('rea_workerlaunchfunctionmissing', 'tool_salasatipicas')];
        }
        return ['available' => true, 'php' => $php, 'launcher' => $launcher, 'nohup' => $nohup, 'reason' => ''];
    }

    public static function assert_worker_available(): void {
        $health = self::get_worker_health();
        if (!$health['available']) {
            throw new \moodle_exception('rea_workerunavailable', 'tool_salasatipicas', '', $health['reason']);
        }
    }

    /**
     * Discover a usable PHP CLI binary without mistaking the web PHP-FPM binary for CLI.
     *
     * This intentionally mirrors the detection strategy already homologated in
     * tool_salasatipicas. PHP_BINARY is NOT used from web requests because under
     * PHP-FPM it may point to /usr/sbin/php-fpm, which cannot run Moodle CLI scripts.
     *
     * @return string Absolute executable path or empty string.
     */
    private static function detect_php_cli(): string {
        global $CFG;

        $candidates = [];

        // Optional plugin-specific override, if configured manually in the future.
        $pluginconfigured = trim((string)get_config('tool_salasatipicas', 'rea_phpclipath'));
        if ($pluginconfigured !== '') {
            $candidates[] = $pluginconfigured;
        }

        // Moodle's official PHP CLI path, when configured.
        $configured = trim((string)($CFG->pathtophp ?? ''));
        if ($configured !== '') {
            $candidates[] = $configured;
        }

        // PHP_BINDIR usually points to the directory containing the CLI binary and,
        // unlike PHP_BINARY in PHP-FPM, does not resolve to php-fpm itself.
        if (defined('PHP_BINDIR')) {
            $candidates[] = rtrim((string)PHP_BINDIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
                (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
        }

        if (PHP_OS_FAMILY === 'Windows') {
            foreach ([
                'C:\\php\\php.exe',
                'C:\\xampp\\php\\php.exe',
                'C:\\laragon\\bin\\php\\php.exe',
            ] as $candidate) {
                $candidates[] = $candidate;
            }
        } else {
            foreach ([
                '/usr/bin/php',
                '/usr/local/bin/php',
                '/bin/php',
            ] as $candidate) {
                $candidates[] = $candidate;
            }

            foreach ([
                '/opt/cpanel/ea-php*/root/usr/bin/php',
                '/opt/remi/php*/root/usr/bin/php',
            ] as $pattern) {
                foreach ((array)glob($pattern) as $candidate) {
                    $candidates[] = $candidate;
                }
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            if ($candidate === '' || !is_file($candidate) || !is_executable($candidate)) {
                continue;
            }

            // Never accept known FastCGI/FPM executables as a CLI worker.
            $basename = strtolower(basename($candidate));
            if (str_contains($basename, 'fpm') || str_contains($basename, 'cgi')) {
                continue;
            }

            return $candidate;
        }

        return '';
    }

    private static function function_enabled(string $function): bool {
        if (!function_exists($function)) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        return !in_array($function, $disabled, true);
    }

    public static function launch_worker(int $historyid): array {
        global $CFG, $DB;
        $health = self::get_worker_health();
        if (!$health['available']) {
            throw new \moodle_exception('rea_workerunavailable', 'tool_salasatipicas', '', $health['reason']);
        }
        $script = $CFG->dirroot . '/' . $CFG->admin . '/tool/salasatipicas/cli/create_rea.php';
        $tempdir = !empty($CFG->tempdir) ? $CFG->tempdir : ($CFG->dataroot . DIRECTORY_SEPARATOR . 'temp');
        $logdir = rtrim((string)$tempdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tool_salasatipicas_rea';
        if (!make_writable_directory($logdir)) {
            throw new \moodle_exception('rea_workerlogdirfailed', 'tool_salasatipicas');
        }
        $logfile = $logdir . DIRECTORY_SEPARATOR . 'worker-' . $historyid . '.log';
        $php = escapeshellarg($health['php']);
        $scriptarg = escapeshellarg($script);
        $idarg = '--historyid=' . $historyid;
        $logarg = escapeshellarg($logfile);
        $pid = 0;

        if (PHP_OS_FAMILY === 'Windows') {
            $command = 'start /B "" ' . $php . ' ' . $scriptarg . ' ' . $idarg . ' >> ' . $logarg . ' 2>&1';
            $handle = @popen($command, 'r');
            if ($handle === false) {
                throw new \moodle_exception('rea_workerlaunchfailed', 'tool_salasatipicas');
            }
            @pclose($handle);
        } else {
            $command = escapeshellarg($health['nohup']) . ' ' . $php . ' ' . $scriptarg . ' ' . $idarg . ' >> ' . $logarg . ' 2>&1 < /dev/null & echo $!';
            if ($health['launcher'] === 'exec') {
                $output = [];
                $returncode = 1;
                @exec($command, $output, $returncode);
                if ($returncode !== 0) {
                    throw new \moodle_exception('rea_workerlaunchfailed', 'tool_salasatipicas');
                }
                if ($output) {
                    $pid = (int)trim((string)end($output));
                }
            } else {
                $output = @shell_exec($command);
                if ($output === null) {
                    throw new \moodle_exception('rea_workerlaunchfailed', 'tool_salasatipicas');
                }
                $pid = (int)trim($output);
            }
        }
        $DB->update_record('tool_salasat_rea_hist', (object)[
            'id' => $historyid,
            'message' => get_string('rea_workerstarted', 'tool_salasatipicas') . ($pid > 0 ? ' PID ' . $pid . '.' : ''),
            'timemodified' => time(),
        ]);
        return ['pid' => $pid, 'log' => $logfile];
    }

    /** Heavy creation path. It is intentionally impossible to execute from a browser. */
    public static function process_history_worker(int $historyid): void {
        global $CFG, $DB;
        if (!defined('CLI_SCRIPT') || !CLI_SCRIPT) {
            throw new \moodle_exception('rea_workerclionly', 'tool_salasatipicas');
        }
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        require_once($CFG->dirroot . '/backup/moodle2/backup_plan_builder.class.php');
        require_once($CFG->dirroot . '/backup/util/helper/copy_helper.class.php');
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir . '/enrollib.php');

        $factory = \core\lock\lock_config::get_lock_factory('tool_salasatipicas');
        $lock = $factory->get_lock('history_' . $historyid, 0);
        if (!$lock) {
            return;
        }
        $globallock = null;
        $legacydeplock = null;
        $legacyrealock = null;
        $targetlock = null;
        $bc = null;
        $rc = null;
        $backupfile = null;
        $backupbasepath = '';
        $targetcourseid = 0;

        try {
            $history = $DB->get_record('tool_salasat_rea_hist', ['id' => $historyid], '*', MUST_EXIST);
            if (in_array($history->status, [self::STATUS_READY, self::STATUS_ERROR], true)) {
                return;
            }
            self::update_history_message($historyid, get_string('rea_workerwaitingglobal', 'tool_salasatipicas'));
            $globallock = $factory->get_lock('global_creation_worker', HOURSECS);
            if (!$globallock) {
                throw new \moodle_exception('rea_workerbusytimeout', 'tool_salasatipicas');
            }

            // Respect the same global locks used by the legacy plugins while they coexist
            // during homologation, avoiding parallel backup/restore across components.
            $legacydepfactory = \core\lock\lock_config::get_lock_factory('tool_salasdependencias');
            $legacyreafactory = \core\lock\lock_config::get_lock_factory('tool_salasrea');
            $legacydeplock = $legacydepfactory->get_lock('global_creation_worker', HOURSECS);
            if (!$legacydeplock) {
                throw new \moodle_exception('rea_workerbusytimeout', 'tool_salasatipicas');
            }
            $legacyrealock = $legacyreafactory->get_lock('global_creation_worker', HOURSECS);
            if (!$legacyrealock) {
                throw new \moodle_exception('rea_workerbusytimeout', 'tool_salasatipicas');
            }
            $targetlock = $factory->get_lock('target_' . sha1((string)$history->targetshortname), 60);
            if (!$targetlock) {
                throw new \moodle_exception('rea_targetbusy', 'tool_salasatipicas');
            }

            $creator = $DB->get_record('user', ['id' => (int)$history->createdby, 'deleted' => 0], '*', MUST_EXIST);
            \core\cron::setup_user($creator);
            if (!$DB->record_exists('course', ['id' => (int)$history->sourcecourseid])) {
                throw new \moodle_exception('rea_templatenotconfigured', 'tool_salasatipicas');
            }
            if ($DB->record_exists('course', ['shortname' => (string)$history->targetshortname])) {
                throw new \moodle_exception('rea_duplicatecourse', 'tool_salasatipicas', '', $history->targetshortname);
            }

            $participants = self::get_history_participants($historyid);
            if (!$participants) {
                throw new \moodle_exception('rea_participantsmissing', 'tool_salasatipicas');
            }
            $subjects = json_decode((string)$history->subjectsjson, true);
            if (!is_array($subjects) || !$subjects) {
                throw new \moodle_exception('rea_subjectsmissing', 'tool_salasatipicas');
            }

            $DB->update_record('tool_salasat_rea_hist', (object)[
                'id' => $historyid,
                'status' => self::STATUS_PROCESSING,
                'message' => get_string('rea_workerprocessing', 'tool_salasatipicas'),
                'timemodified' => time(),
            ]);

            $rawcopydata = (object)[
                'courseid' => (int)$history->sourcecourseid,
                'fullname' => (string)$history->targetfullname,
                'shortname' => (string)$history->targetshortname,
                'category' => (int)$history->coursecategoryid,
                'visible' => (int)self::get_config_value('coursevisible', 0),
                'startdate' => time(),
                'enddate' => 0,
                'idnumber' => '',
                'userdata' => 0,
            ];
            $copydata = \copy_helper::process_formdata($rawcopydata);

            self::update_history_message($historyid, get_string('rea_syncbackup', 'tool_salasatipicas'));
            $bc = new \backup_controller(
                \backup::TYPE_1COURSE,
                (int)$history->sourcecourseid,
                \backup::FORMAT_MOODLE,
                \backup::INTERACTIVE_NO,
                \backup::MODE_COPY,
                (int)$history->createdby,
                \backup::RELEASESESSION_YES
            );
            $bc->set_execution(\backup::EXECUTION_INMEDIATE);
            $bc->set_kept_roles([]);
            $backupplan = $bc->get_plan();
            $usersetting = $backupplan->get_setting('users');
            $usersetting->set_status(\backup_setting::NOT_LOCKED);
            $usersetting->set_value('0');
            $backupid = $bc->get_backupid();
            $DB->set_field('tool_salasat_rea_hist', 'backupid', $backupid, ['id' => $historyid]);
            $bc->execute_plan();
            if ($bc->get_status() !== \backup::STATUS_FINISHED_OK) {
                throw new \moodle_exception('rea_syncbackupfailed', 'tool_salasatipicas');
            }
            $results = $bc->get_results();
            if (empty($results['backup_destination'])) {
                throw new \moodle_exception('rea_syncbackupfilemissing', 'tool_salasatipicas');
            }
            $backupbasepath = $backupplan->get_basepath();
            $backupfile = $results['backup_destination'];
            $backupfile->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $backupbasepath);
            $bc->destroy();
            $bc = null;

            self::update_history_message($historyid, get_string('rea_synccreatecourse', 'tool_salasatipicas'));
            [$placeholderfullname, $placeholdershortname] = \restore_dbops::calculate_course_names(0, get_string('copyingcourse', 'backup'), get_string('copyingcourseshortname', 'backup'));
            $targetcourseid = (int)\restore_dbops::create_new_course($placeholderfullname, $placeholdershortname, (int)$history->coursecategoryid);
            $DB->set_field('tool_salasat_rea_hist', 'targetcourseid', $targetcourseid, ['id' => $historyid]);

            self::update_history_message($historyid, get_string('rea_syncrestore', 'tool_salasatipicas'));
            $rc = new \restore_controller(
                $backupid,
                $targetcourseid,
                \backup::INTERACTIVE_NO,
                \backup::MODE_COPY,
                (int)$history->createdby,
                \backup::TARGET_NEW_COURSE,
                null,
                \backup::RELEASESESSION_YES,
                $copydata
            );
            $rc->set_execution(\backup::EXECUTION_INMEDIATE);
            $rc->prepare_copy();
            $restoreid = $rc->get_restoreid();
            $DB->set_field('tool_salasat_rea_hist', 'restoreid', $restoreid, ['id' => $historyid]);
            $plan = $rc->get_plan();
            $plan->get_setting('course_startdate')->set_value($copydata->startdate);
            $plan->get_setting('course_fullname')->set_value($copydata->fullname);
            $plan->get_setting('course_shortname')->set_value($copydata->shortname);
            $hook = new \core_backup\hook\before_copy_course_execute($plan, $copydata);
            \core\di::get(\core\hook\manager::class)->dispatch($hook);
            $rc->execute_precheck();
            if ($rc->get_status() !== \backup::STATUS_AWAITING) {
                $precheck = $rc->get_precheck_results();
                throw new \moodle_exception('rea_syncrestoreprecheckfailed', 'tool_salasatipicas', '', null, json_encode($precheck['errors'] ?? []));
            }
            $rc->execute_plan();
            if ($rc->get_status() !== \backup::STATUS_FINISHED_OK) {
                throw new \moodle_exception('rea_syncrestorefailed', 'tool_salasatipicas');
            }
            $rc->destroy();
            $rc = null;

            $course = $DB->get_record('course', ['id' => $targetcourseid], '*', MUST_EXIST);
            $course->visible = (int)$copydata->visible;
            $DB->update_record('course', $course);

            // All REA-specific editing is still inside the detached CLI worker.
            self::update_history_message($historyid, get_string('rea_customizingcourse', 'tool_salasatipicas'));
            self::rename_student_section($targetcourseid, (string)$history->studentdisplayname);
            self::populate_student_info_block($targetcourseid, $history, $subjects);
            self::configure_subject_modules($targetcourseid, $subjects);

            self::update_history_message($historyid, get_string('rea_enrollingparticipants', 'tool_salasatipicas'));
            foreach ($participants as $participant) {
                self::manual_enrol($targetcourseid, (int)$participant->userid, (int)$participant->roleid);
            }

            rebuild_course_cache($targetcourseid, true);
            \cache_helper::purge_by_event('changesincourse');
            $DB->update_record('tool_salasat_rea_hist', (object)[
                'id' => $historyid,
                'status' => self::STATUS_READY,
                'message' => get_string('rea_reaready', 'tool_salasatipicas'),
                'timemodified' => time(),
            ]);
        } catch (\Throwable $e) {
            $cleanuperror = '';
            if ($targetcourseid > 0 && $DB->record_exists('course', ['id' => $targetcourseid])) {
                try {
                    delete_course($targetcourseid, false);
                    $targetcourseid = 0;
                } catch (\Throwable $cleanup) {
                    $cleanuperror = ' | Cleanup: ' . $cleanup->getMessage();
                }
            }
            if ($DB->record_exists('tool_salasat_rea_hist', ['id' => $historyid])) {
                $DB->update_record('tool_salasat_rea_hist', (object)[
                    'id' => $historyid,
                    'targetcourseid' => $targetcourseid,
                    'status' => self::STATUS_ERROR,
                    'message' => $e->getMessage() . $cleanuperror,
                    'timemodified' => time(),
                ]);
            }
            throw $e;
        } finally {
            if ($rc) {
                try { $rc->destroy(); } catch (\Throwable $ignored) {}
            }
            if ($bc) {
                try { $bc->destroy(); } catch (\Throwable $ignored) {}
            }
            if ($backupfile) {
                try { $backupfile->delete(); } catch (\Throwable $ignored) {}
            }
            if ($backupbasepath !== '' && empty($CFG->keeptempdirectoriesonbackup)) {
                try { fulldelete($backupbasepath); } catch (\Throwable $ignored) {}
            }
            if ($targetlock) { $targetlock->release(); }
            if ($legacyrealock) { $legacyrealock->release(); }
            if ($legacydeplock) { $legacydeplock->release(); }
            if ($globallock) { $globallock->release(); }
            $lock->release();
        }
    }

    /** Rename the template section "Aluno: xxx" with the process name entered by the administrator. */
    private static function rename_student_section(int $courseid, string $studentname): void {
        global $DB;

        $marker = trim((string)self::get_config_value('studentsectionmarker', 'Aluno: xxx'));
        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC, id ASC', 'id,name');
        $match = null;
        foreach ($sections as $section) {
            $name = trim((string)$section->name);
            if ($name === '') {
                continue;
            }
            if ($marker !== '' && \core_text::strtolower($name) === \core_text::strtolower($marker)) {
                $match = $section;
                break;
            }
            if ($marker !== '' && stripos($name, $marker) !== false) {
                $match = $section;
                break;
            }
            // Safe fallback for the institutional template used during homologation.
            if (preg_match('/^aluno\s*:\s*x{1,3}\s*$/iu', $name)) {
                $match = $section;
                break;
            }
        }

        if (!$match) {
            throw new \moodle_exception('rea_studentsectionnotfound', 'tool_salasatipicas', '', $marker);
        }

        $DB->set_field('course_sections', 'name', 'Aluno: ' . trim($studentname), ['id' => (int)$match->id]);
    }

    /** Populate the Text and media (mod_label) block from manual process data. */
    private static function populate_student_info_block(int $courseid, \stdClass $history, array $subjects): void {
        global $DB;
        $marker = trim((string)self::get_config_value('infomarker', 'Aluno (a):'));
        $sql = "SELECT l.id, l.intro
                  FROM {label} l
                 WHERE l.course = :courseid
              ORDER BY l.id ASC";
        $labels = $DB->get_records_sql($sql, ['courseid' => $courseid]);
        $label = null;
        foreach ($labels as $candidate) {
            if ($marker === '' || stripos(strip_tags((string)$candidate->intro), $marker) !== false) {
                $label = $candidate;
                break;
            }
        }
        if (!$label) {
            throw new \moodle_exception('rea_infoblocknotfound', 'tool_salasatipicas', '', $marker);
        }

        $subjectitems = '';
        foreach ($subjects as $subject) {
            $teachernames = array_map(fn($t) => (string)$t['fullname'], (array)($subject['teachers'] ?? []));
            $subjectitems .= \html_writer::tag('li',
                \html_writer::tag('strong', s((string)$subject['name'])) . ' — ' . s(implode(' / ', $teachernames))
            );
        }
        $html = \html_writer::tag('p', \html_writer::tag('strong', get_string('rea_studentlabel', 'tool_salasatipicas') . ' ' . s((string)$history->studentdisplayname))) .
            \html_writer::start_tag('ul') .
            \html_writer::tag('li', \html_writer::tag('strong', get_string('rea_courseprogramlabel', 'tool_salasatipicas')) . ' ' . s((string)$history->courseprogram)) .
            \html_writer::tag('li', \html_writer::tag('strong', get_string('rea_phonelabel', 'tool_salasatipicas')) . ' ' . s((string)$history->phone)) .
            \html_writer::tag('li', \html_writer::tag('strong', get_string('rea_emaillabel', 'tool_salasatipicas')) . ' ' . s((string)$history->contactemail)) .
            \html_writer::tag('li', \html_writer::tag('strong', get_string('rea_licenseperiodlabel', 'tool_salasatipicas')) . ' ' .
                userdate((int)$history->licensestart, '%d/%m/%Y') . ' a ' . userdate((int)$history->licenseend, '%d/%m/%Y')) .
            \html_writer::end_tag('ul') .
            \html_writer::tag('p', \html_writer::tag('strong', get_string('rea_subjectsandteacherslabel', 'tool_salasatipicas'))) .
            \html_writer::tag('ul', $subjectitems);

        $update = (object)['id' => $label->id, 'intro' => $html, 'introformat' => FORMAT_HTML, 'timemodified' => time()];
        $columns = $DB->get_columns('label');
        if (isset($columns['name'])) {
            $update->name = get_string('rea_studentinfoblockname', 'tool_salasatipicas');
        }
        $DB->update_record('label', $update);
    }

    /** Rename/duplicate and organise the template File + Assignment pair for every discipline. */
    private static function configure_subject_modules(int $courseid, array $subjects): void {
        global $DB;
        require_once(__DIR__ . '/../../../../../course/lib.php');

        $materialmarker = trim((string)self::get_config_value('materialmarker', 'Material da Disciplina'));
        $assignmentmarker = trim((string)self::get_config_value('assignmentmarker', 'Entrega da Atividade REA - Disciplina'));

        $resources = $DB->get_records_sql(
            "SELECT cm.id AS cmid, cm.section, cm.instance, r.name
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'resource'
               JOIN {resource} r ON r.id = cm.instance
              WHERE cm.course = :courseid AND " . $DB->sql_like('r.name', ':marker', false) . "
           ORDER BY cm.id ASC",
            ['courseid' => $courseid, 'marker' => '%' . $DB->sql_like_escape($materialmarker) . '%']
        );
        $assignments = $DB->get_records_sql(
            "SELECT cm.id AS cmid, cm.section, cm.instance, a.name
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
               JOIN {assign} a ON a.id = cm.instance
              WHERE cm.course = :courseid AND " . $DB->sql_like('a.name', ':marker', false) . "
           ORDER BY cm.id ASC",
            ['courseid' => $courseid, 'marker' => '%' . $DB->sql_like_escape($assignmentmarker) . '%']
        );
        $resources = array_values($resources);
        $assignments = array_values($assignments);
        if (!$resources) {
            throw new \moodle_exception('rea_materialtemplatenotfound', 'tool_salasatipicas', '', $materialmarker);
        }
        if (!$assignments) {
            throw new \moodle_exception('rea_assignmenttemplatenotfound', 'tool_salasatipicas', '', $assignmentmarker);
        }

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $baseresourcecm = get_coursemodule_from_id('resource', (int)$resources[0]->cmid, $courseid, false, MUST_EXIST);
        $baseassigncm = get_coursemodule_from_id('assign', (int)$assignments[0]->cmid, $courseid, false, MUST_EXIST);
        $pairs = [];
        $allmanagedids = [];

        foreach ($resources as $resource) {
            $allmanagedids[] = (int)$resource->cmid;
        }
        foreach ($assignments as $assignment) {
            $allmanagedids[] = (int)$assignment->cmid;
        }

        foreach ($subjects as $idx => $subject) {
            $subjectname = trim((string)$subject['name']);
            if (isset($resources[$idx])) {
                $resourcecmid = (int)$resources[$idx]->cmid;
            } else {
                $newcm = duplicate_module($course, $baseresourcecm, null, false);
                if (!$newcm) {
                    throw new \moodle_exception('rea_duplicatemodulefailed', 'tool_salasatipicas');
                }
                $resourcecmid = (int)$newcm->id;
                $allmanagedids[] = $resourcecmid;
            }
            if (isset($assignments[$idx])) {
                $assigncmid = (int)$assignments[$idx]->cmid;
            } else {
                $newcm = duplicate_module($course, $baseassigncm, null, false);
                if (!$newcm) {
                    throw new \moodle_exception('rea_duplicatemodulefailed', 'tool_salasatipicas');
                }
                $assigncmid = (int)$newcm->id;
                $allmanagedids[] = $assigncmid;
            }
            set_coursemodule_name($resourcecmid, get_string('rea_materialname', 'tool_salasatipicas', $subjectname));
            set_coursemodule_name($assigncmid, get_string('rea_assignmentname', 'tool_salasatipicas', $subjectname));
            $pairs[] = [$resourcecmid, $assigncmid];
        }

        // Extra xx/yy placeholders in the template are hidden instead of deleted.
        $hiddenids = [];
        for ($i = count($subjects); $i < count($resources); $i++) {
            $id = (int)$resources[$i]->cmid;
            set_coursemodule_visible($id, 0, 1, false);
            $hiddenids[] = $id;
        }
        for ($i = count($subjects); $i < count($assignments); $i++) {
            $id = (int)$assignments[$i]->cmid;
            set_coursemodule_visible($id, 0, 1, false);
            $hiddenids[] = $id;
        }

        // Decorative separator-only Text and media activities from the template should not split
        // the generated subject pairs. They are preserved but hidden and moved after the pairs.
        $targetsectionid = (int)$DB->get_field('course_modules', 'section', ['id' => (int)$pairs[0][0]], MUST_EXIST);
        $separatorids = self::find_decorative_separator_cmids($courseid, $targetsectionid);
        foreach ($separatorids as $separatorid) {
            set_coursemodule_visible($separatorid, 0, 1, false);
        }
        $allmanagedids = array_values(array_unique(array_merge($allmanagedids, $separatorids)));

        self::reorder_subject_modules($courseid, $targetsectionid, $pairs, $allmanagedids, $hiddenids, $separatorids);
        rebuild_course_cache($courseid, true);
    }

    /** Find label/text-and-media items that contain only visual separator punctuation. */
    private static function find_decorative_separator_cmids(int $courseid, int $sectionid): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT cm.id AS cmid, l.intro
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'label'
               JOIN {label} l ON l.id = cm.instance
              WHERE cm.course = :courseid AND cm.section = :sectionid",
            ['courseid' => $courseid, 'sectionid' => $sectionid]
        );
        $ids = [];
        foreach ($rows as $row) {
            $intro = (string)$row->intro;
            $plain = html_entity_decode(strip_tags($intro), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $plain = preg_replace('/\s+/u', '', $plain) ?? '';
            if (preg_match('/<hr\b/i', $intro) || ($plain !== '' && preg_match('/^[-_–—.]{3,}$/u', $plain))) {
                $ids[] = (int)$row->cmid;
            }
        }
        return $ids;
    }

    /** Put every generated subject as Material -> Entrega, in the same order as the form. */
    private static function reorder_subject_modules(
        int $courseid,
        int $targetsectionid,
        array $pairs,
        array $allmanagedids,
        array $hiddenids,
        array $separatorids
    ): void {
        global $DB;

        $managedlookup = array_fill_keys(array_map('intval', $allmanagedids), true);
        $managedlist = implode(',', array_map('intval', array_keys($managedlookup)));
        $managedmodules = $DB->get_records_select(
            'course_modules',
            'course = :courseid AND id IN (' . $managedlist . ')',
            ['courseid' => $courseid],
            '',
            'id,section'
        );
        $sectionids = [];
        foreach ($managedmodules as $managedmodule) {
            $sectionids[] = (int)$managedmodule->section;
        }
        $sectionids = array_values(array_unique($sectionids));
        if (!in_array($targetsectionid, $sectionids, true)) {
            $sectionids[] = $targetsectionid;
        }

        $targetsection = $DB->get_record('course_sections', ['id' => $targetsectionid, 'course' => $courseid], '*', MUST_EXIST);
        $originaltarget = self::parse_section_sequence((string)$targetsection->sequence);
        $firstmanagedposition = null;
        foreach ($originaltarget as $pos => $cmid) {
            if (isset($managedlookup[$cmid])) {
                $firstmanagedposition = $pos;
                break;
            }
        }

        // Remove managed modules from every affected section first.
        foreach ($sectionids as $sectionid) {
            $section = $DB->get_record('course_sections', ['id' => $sectionid, 'course' => $courseid]);
            if (!$section) {
                continue;
            }
            $sequence = self::parse_section_sequence((string)$section->sequence);
            $clean = array_values(array_filter($sequence, fn($cmid) => !isset($managedlookup[$cmid])));
            $DB->set_field('course_sections', 'sequence', implode(',', $clean), ['id' => $sectionid]);
        }

        foreach (array_keys($managedlookup) as $cmid) {
            $DB->set_field('course_modules', 'section', $targetsectionid, ['id' => (int)$cmid]);
        }

        $targetsection = $DB->get_record('course_sections', ['id' => $targetsectionid, 'course' => $courseid], '*', MUST_EXIST);
        $clean = self::parse_section_sequence((string)$targetsection->sequence);
        $insertat = count($clean);
        if ($firstmanagedposition !== null) {
            $insertat = 0;
            foreach (array_slice($originaltarget, 0, $firstmanagedposition) as $cmid) {
                if (!isset($managedlookup[$cmid])) {
                    $insertat++;
                }
            }
            $insertat = min($insertat, count($clean));
        }

        $ordered = [];
        foreach ($pairs as $pair) {
            $ordered[] = (int)$pair[0];
            $ordered[] = (int)$pair[1];
        }
        // Hidden template leftovers and visual separators remain preserved, but are placed after
        // the active subject pairs so they never interrupt the user-facing sequence.
        $ordered = array_values(array_unique(array_merge($ordered, array_map('intval', $hiddenids), array_map('intval', $separatorids))));
        array_splice($clean, $insertat, 0, $ordered);
        $DB->set_field('course_sections', 'sequence', implode(',', $clean), ['id' => $targetsectionid]);
    }

    private static function parse_section_sequence(string $sequence): array {
        if (trim($sequence) === '') {
            return [];
        }
        return array_values(array_filter(array_map('intval', explode(',', $sequence)), fn($id) => $id > 0));
    }

    public static function manual_enrol(int $courseid, int $userid, int $roleid): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/enrollib.php');
        $plugin = enrol_get_plugin('manual');
        if (!$plugin) {
            throw new \moodle_exception('rea_manualenrolmissing', 'tool_salasatipicas');
        }
        $instances = enrol_get_instances($courseid, true);
        $instance = null;
        foreach ($instances as $candidate) {
            if ($candidate->enrol === 'manual') {
                $instance = $candidate;
                break;
            }
        }
        if (!$instance) {
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
            $instanceid = $plugin->add_default_instance($course);
            $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
        }
        $plugin->enrol_user($instance, $userid, $roleid, 0, 0, ENROL_USER_ACTIVE);
    }

    public static function get_history_participants(int $historyid): array {
        global $DB;
        return array_values($DB->get_records('tool_salasat_rea_part', ['historyid' => $historyid], 'sortorder ASC, id ASC'));
    }

    public static function get_participants_for_histories(array $ids): array {
        global $DB;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) { return []; }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'hid');
        $rows = $DB->get_records_select('tool_salasat_rea_part', "historyid {$insql}", $params, 'historyid ASC, sortorder ASC, id ASC');
        $out = [];
        foreach ($rows as $row) { $out[(int)$row->historyid][] = $row; }
        return $out;
    }

    public static function participants_summary(array $participants, string $type = ''): string {
        $parts = [];
        foreach ($participants as $participant) {
            $participant = (object)$participant;
            if ($type !== '' && $participant->participanttype !== $type) { continue; }
            $parts[] = $participant->fullname . ' (' . $participant->username . ')';
        }
        return implode("\n", $parts);
    }

    public static function status_label(string $status): string {
        $map = [
            self::STATUS_QUEUED => 'statusqueued',
            self::STATUS_PROCESSING => 'statusprocessing',
            self::STATUS_READY => 'statusready',
            self::STATUS_ERROR => 'statuserror',
        ];
        return get_string('rea_' . ($map[$status] ?? 'statusunknown'), 'tool_salasatipicas');
    }

    private static function update_history_message(int $historyid, string $message): void {
        global $DB;
        $DB->update_record('tool_salasat_rea_hist', (object)['id' => $historyid, 'message' => $message, 'timemodified' => time()]);
    }
}
