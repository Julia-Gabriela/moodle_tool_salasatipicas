<?php
// This file is part of Moodle - http://moodle.org/

namespace tool_salasatipicas\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Main business logic for dependency creation.
 *
 * @package tool_salasatipicas
 */
class dependency_manager {
    public const STATUS_QUEUED = 'queued';
    public const STATUS_COPYING = 'copying'; // Legacy 0.2.1 status.
    public const STATUS_PROCESSING = 'processing'; // Independent CLI worker is actively creating the course.
    public const STATUS_READY = 'ready';
    public const STATUS_ERROR = 'error';

    public const DEFAULT_FULLNAME_PATTERN = 'PROCESSO N°{PROCESSO} - {DISCIPLINA} - Prof. {PROFESSOR} - {PERIODO} - Dependência';
    public const DEFAULT_SHORTNAME_PATTERN = 'PROCESSO N°{PROCESSO} - {DISCIPLINA} - Prof. {PROFESSOR} - {PERIODO} - Dependência';
    public const MAX_FULLNAME_LENGTH = 1333;
    public const MAX_SHORTNAME_LENGTH = 255;

    /**
     * Get active configured categories.
     *
     * @param bool $activeonly
     * @return array
     */
    public static function get_categories(bool $activeonly = true): array {
        global $DB;
        $conditions = $activeonly ? ['active' => 1] : [];
        return $DB->get_records('tool_salasat_dep_cat', $conditions, 'sortorder ASC, name ASC');
    }

    /**
     * Get category options for a form.
     *
     * @return array
     */
    public static function get_category_options(): array {
        $options = [];
        foreach (self::get_categories(true) as $category) {
            $options[$category->id] = $category->name . ' (ID Moodle: ' . $category->categoryid . ')';
        }
        return $options;
    }

    /**
     * Get plugin config with fallback.
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public static function get_config_value(string $name, $default = null) {
        $value = get_config('tool_salasatipicas', 'dep_' . $name);
        return $value === false ? $default : $value;
    }

    /**
     * Whether new dependency course creation is explicitly enabled by a site administrator.
     *
     * Creation is enabled by default when the setting is absent; administrators can disable it for maintenance.
     *
     * @return bool
     */
    public static function creation_enabled(): bool {
        return (bool)(int)self::get_config_value('creationenabled', 1);
    }

    /**
     * Block every backend creation path when course creation has been administratively disabled.
     *
     * @throws \moodle_exception
     */
    public static function assert_creation_enabled(): void {
        if (!self::creation_enabled()) {
            throw new \moodle_exception('dep_creationdisabled', 'tool_salasatipicas');
        }
    }

    /**
     * Return course-context roles.
     *
     * @return array
     */
    public static function get_course_role_options(): array {
        global $DB;
        $roles = $DB->get_records('role', null, 'sortorder ASC, name ASC');
        $options = [];
        foreach ($roles as $role) {
            if (!$DB->record_exists('role_context_levels', ['roleid' => $role->id, 'contextlevel' => CONTEXT_COURSE])) {
                continue;
            }
            $display = role_get_name($role, \context_system::instance(), ROLENAME_ORIGINAL);
            $options[$role->id] = $display . ' (' . $role->shortname . ')';
        }
        return $options;
    }

    /**
     * Find a sensible default role id by shortname.
     *
     * @param string $shortname
     * @return int
     */
    public static function role_id_by_shortname(string $shortname): int {
        global $DB;
        return (int)$DB->get_field('role', 'id', ['shortname' => $shortname], IGNORE_MISSING);
    }

    /**
     * Resolve an import category name or Moodle category id to a plugin category record.
     *
     * @param string $value
     * @return \stdClass|null
     */
    public static function resolve_category_input(string $value): ?\stdClass {
        $needle = trim($value);
        if ($needle === '') {
            return null;
        }
        foreach (self::get_categories(true) as $category) {
            if ((string)$category->categoryid === $needle || (string)$category->id === $needle ||
                    \core_text::strtolower(trim($category->name)) === \core_text::strtolower($needle)) {
                return $category;
            }
        }
        return null;
    }

    /**
     * Validate and prepare one dependency definition.
     *
     * Expected keys: processnumber, subject, period, categoryconfigid and either teacheruserid/studentuserid
     * (manual autocomplete) or teacherusername/studentusername (CSV/XLSX import).
     *
     * @param array|\stdClass $data
     * @return array ['valid' => bool, 'errors' => string[], 'definition' => array|null]
     */
    public static function prepare_definition($data): array {
        global $CFG, $DB;

        $data = (array)$data;
        $errors = [];
        foreach (['processnumber', 'subject', 'period', 'categoryconfigid'] as $field) {
            if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
                $errors[] = get_string('dep_missingfield', 'tool_salasatipicas', $field);
            }
        }

        // Backwards compatibility with the original single-user fields.
        if (empty($data['teacheruserids']) && !empty($data['teacheruserid'])) {
            $data['teacheruserids'] = [(int)$data['teacheruserid']];
        }
        if (empty($data['studentuserids']) && !empty($data['studentuserid'])) {
            $data['studentuserids'] = [(int)$data['studentuserid']];
        }
        if (empty($data['teacherusernames']) && !empty($data['teacherusername'])) {
            $data['teacherusernames'] = self::normalise_username_list($data['teacherusername']);
        }
        if (empty($data['studentusernames']) && !empty($data['studentusername'])) {
            $data['studentusernames'] = self::normalise_username_list($data['studentusername']);
        }
        if (empty($data['coordinationusernames']) && !empty($data['coordinationusername'])) {
            $data['coordinationusernames'] = self::normalise_username_list($data['coordinationusername']);
        }

        $teacherids = self::normalise_userid_list($data['teacheruserids'] ?? []);
        $studentids = self::normalise_userid_list($data['studentuserids'] ?? []);
        $coordinationids = self::normalise_userid_list($data['coordinationuserids'] ?? []);
        $teacherusernames = self::normalise_username_list($data['teacherusernames'] ?? []);
        $studentusernames = self::normalise_username_list($data['studentusernames'] ?? []);
        $coordinationusernames = self::normalise_username_list($data['coordinationusernames'] ?? []);

        if (!$teacherids && !$teacherusernames) {
            $errors[] = get_string('dep_missingfield', 'tool_salasatipicas', 'teacher');
        }
        if (!$studentids && !$studentusernames) {
            $errors[] = get_string('dep_missingfield', 'tool_salasatipicas', 'student');
        }
        if ($errors) {
            return ['valid' => false, 'errors' => $errors, 'definition' => null];
        }

        $category = $DB->get_record('tool_salasat_dep_cat', [
            'id' => (int)$data['categoryconfigid'],
            'active' => 1,
        ]);
        if (!$category) {
            $errors[] = get_string('dep_invalidconfiguredcategory', 'tool_salasatipicas');
        } else if (!$DB->record_exists('course_categories', ['id' => $category->categoryid])) {
            $errors[] = get_string('dep_invalidmoodlecategory', 'tool_salasatipicas');
        }

        $defaultstudentroleid = (int)self::get_config_value('studentroleid', self::role_id_by_shortname('student'));
        $defaultteacherroleid = (int)self::get_config_value('teacherroleid', self::role_id_by_shortname('editingteacher'));
        $defaultcoordroleid = (int)self::get_config_value('coordinationroleid', $defaultteacherroleid);

        $teacherroleid = (int)($data['teacherroleid'] ?? $defaultteacherroleid);
        $studentroleid = (int)($data['studentroleid'] ?? $defaultstudentroleid);
        $coordinationroleid = (int)($data['coordinationroleid'] ?? $defaultcoordroleid);

        foreach ([
            ['id' => $teacherroleid, 'required' => true, 'key' => 'teacherrolenotconfigured'],
            ['id' => $studentroleid, 'required' => true, 'key' => 'studentrolenotconfigured'],
            ['id' => $coordinationroleid, 'required' => (bool)($coordinationids || $coordinationusernames), 'key' => 'coordinationrolenotconfigured'],
        ] as $rolecheck) {
            if (!$rolecheck['required'] && $rolecheck['id'] <= 0) {
                continue;
            }
            if ($rolecheck['id'] <= 0 || !$DB->record_exists('role', ['id' => $rolecheck['id']]) ||
                    !$DB->record_exists('role_context_levels', [
                        'roleid' => $rolecheck['id'], 'contextlevel' => CONTEXT_COURSE])) {
                $errors[] = get_string('dep_' . $rolecheck['key'], 'tool_salasatipicas');
            }
        }

        $teachers = self::resolve_users($teacherids, $teacherusernames, 'teacher', $errors);
        $students = self::resolve_users($studentids, $studentusernames, 'student', $errors);
        $coordinators = self::resolve_users($coordinationids, $coordinationusernames, 'coordination', $errors);

        $templateid = $category ? (int)$category->templatecourseid : 0;
        if ($templateid <= 0) {
            $templateid = (int)self::get_config_value('defaulttemplateid', 0);
        }
        if ($templateid <= 0 || !$DB->record_exists('course', ['id' => $templateid])) {
            $errors[] = get_string('dep_templatenotconfigured', 'tool_salasatipicas');
        }

        require_once($CFG->libdir . '/enrollib.php');
        if (!enrol_get_plugin('manual')) {
            $errors[] = get_string('dep_manualenrolmissing', 'tool_salasatipicas');
        }

        if ($errors) {
            return ['valid' => false, 'errors' => array_values(array_unique($errors)), 'definition' => null];
        }

        $processnumber = trim((string)$data['processnumber']);
        $subject = trim((string)$data['subject']);
        $period = self::normalise_period((string)$data['period']);
        if (\core_text::strlen($subject) > 255) {
            $errors[] = get_string('dep_subjecttoolong', 'tool_salasatipicas', 255);
        }
        if (!preg_match('/^(?:01|02)\/(?:19|20)\d{2}$/', $period)) {
            $errors[] = get_string('dep_invalidperiod', 'tool_salasatipicas');
        }
        $processnumber = self::normalise_process_number($processnumber, $period);
        if (!preg_match('/^\d{1,10}\/(?:19|20)\d{2}$/', $processnumber)) {
            $errors[] = get_string('dep_invalidprocessnumber', 'tool_salasatipicas');
        }
        if ($errors) {
            return ['valid' => false, 'errors' => $errors, 'definition' => null];
        }

        // The first teacher remains the principal teacher used by the established naming rule.
        $teacher = reset($teachers);
        $student = reset($students);
        $teachername = fullname($teacher);
        $teachernametitle = self::normalise_teacher_name_for_title($teachername);
        $studentname = fullname($student);

        $participants = [];
        $sortorder = 0;
        foreach ($teachers as $user) {
            $participants[] = self::participant_definition($user, 'teacher', $teacherroleid, $sortorder++);
        }
        foreach ($coordinators as $user) {
            $participants[] = self::participant_definition($user, 'coordination', $coordinationroleid, $sortorder++);
        }
        foreach ($students as $user) {
            $participants[] = self::participant_definition($user, 'student', $studentroleid, $sortorder++);
        }

        // Remove exact userid+role duplicates while keeping intentional multi-role assignments.
        $uniqueparticipants = [];
        $seenparticipants = [];
        foreach ($participants as $participant) {
            $key = $participant['userid'] . ':' . $participant['roleid'];
            if (isset($seenparticipants[$key])) {
                continue;
            }
            $seenparticipants[$key] = true;
            $uniqueparticipants[] = $participant;
        }
        $participants = $uniqueparticipants;

        $teachernames = array_map(fn($u) => self::normalise_teacher_name_for_title(fullname($u)), $teachers);
        $studentnames = array_map(fn($u) => fullname($u), $students);
        $coordinationnames = array_map(fn($u) => fullname($u), $coordinators);

        $variables = [
            '{PROCESSO}' => $processnumber,
            '{DISCIPLINA}' => $subject,
            '{PROFESSOR}' => $teachernametitle,
            '{PROFESSORES}' => implode(' / ', $teachernames),
            '{ALUNO}' => $studentname,
            '{ALUNOS}' => implode(' / ', $studentnames),
            '{COORDENACAO}' => implode(' / ', $coordinationnames),
            '{USERNAME_PROFESSOR}' => $teacher->username,
            '{USERNAME_ALUNO}' => $student->username,
            '{PERIODO}' => $period,
            '{CATEGORIA}' => $category->name,
        ];

        $fullnamepattern = (string)self::get_config_value('fullnamepattern', self::DEFAULT_FULLNAME_PATTERN);
        $shortnamepattern = (string)self::get_config_value('shortnamepattern', self::DEFAULT_SHORTNAME_PATTERN);
        $targetfullname = trim(strtr($fullnamepattern, $variables));
        $targetshortname = self::normalise_shortname(strtr($shortnamepattern, $variables));

        if ($targetfullname === '') {
            $errors[] = get_string('dep_emptygeneratedfullname', 'tool_salasatipicas');
        }
        if (\core_text::strlen($targetfullname) > self::MAX_FULLNAME_LENGTH) {
            $errors[] = get_string('dep_fullnametoolong', 'tool_salasatipicas', self::MAX_FULLNAME_LENGTH);
        }
        if ($targetshortname === '') {
            $errors[] = get_string('dep_emptygeneratedshortname', 'tool_salasatipicas');
        }
        if (\core_text::strlen($targetshortname) > self::MAX_SHORTNAME_LENGTH) {
            $errors[] = get_string('dep_shortnametoolong', 'tool_salasatipicas', self::MAX_SHORTNAME_LENGTH);
        }
        if ($DB->record_exists('course', ['shortname' => $targetshortname]) ||
                $DB->record_exists_select('tool_salasat_dep_hist', 'targetshortname = ? AND status <> ?', [$targetshortname, self::STATUS_ERROR])) {
            $errors[] = get_string('dep_duplicatecourse', 'tool_salasatipicas', $targetshortname);
        }

        if ($errors) {
            return ['valid' => false, 'errors' => $errors, 'definition' => null];
        }

        return [
            'valid' => true,
            'errors' => [],
            'definition' => [
                'processnumber' => $processnumber,
                'subject' => $subject,
                'period' => $period,
                // Legacy principal identities remain populated for compatibility and naming/history summaries.
                'teacheruserid' => (int)$teacher->id,
                'studentuserid' => (int)$student->id,
                'teachername' => $teachername,
                'studentname' => $studentname,
                'teacherusername' => $teacher->username,
                'studentusername' => $student->username,
                'teacheruserids' => array_map(fn($u) => (int)$u->id, $teachers),
                'coordinationuserids' => array_map(fn($u) => (int)$u->id, $coordinators),
                'studentuserids' => array_map(fn($u) => (int)$u->id, $students),
                'teacherroleid' => $teacherroleid,
                'coordinationroleid' => $coordinationroleid,
                'studentroleid' => $studentroleid,
                'participants' => $participants,
                'categoryconfigid' => (int)$category->id,
                'coursecategoryid' => (int)$category->categoryid,
                'templatecourseid' => $templateid,
                'targetfullname' => $targetfullname,
                'targetshortname' => $targetshortname,
                'visible' => (int)self::get_config_value('coursevisible', 0),
            ],
        ];
    }

    /** Normalise one or many Moodle user ids. */
    private static function normalise_userid_list($values): array {
        if (!is_array($values)) {
            $values = [$values];
        }
        return array_values(array_unique(array_filter(array_map('intval', $values), fn($id) => $id > 0)));
    }

    /** Normalise a pipe-separated or array username list. */
    public static function normalise_username_list($values): array {
        if (is_string($values)) {
            $values = preg_split('/\s*\|\s*/u', trim($values), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } else if (!is_array($values)) {
            $values = [$values];
        }
        $result = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $result[$value] = $value;
            }
        }
        return array_values($result);
    }

    /** Resolve user ids/usernames into active Moodle users. */
    private static function resolve_users(array $ids, array $usernames, string $type, array &$errors): array {
        global $DB;
        $users = [];
        foreach ($ids as $id) {
            $user = $DB->get_record('user', ['id' => $id, 'deleted' => 0]);
            if (!$user || $user->suspended) {
                $errors[] = get_string('dep_' . $type . 'usernotfound', 'tool_salasatipicas', (string)$id);
                continue;
            }
            $users[(int)$user->id] = $user;
        }
        foreach ($usernames as $username) {
            $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
            if (!$user || $user->suspended) {
                $errors[] = get_string('dep_' . $type . 'usernotfound', 'tool_salasatipicas', $username);
                continue;
            }
            $users[(int)$user->id] = $user;
        }
        return array_values($users);
    }

    /** Build a persisted participant definition. */
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

    /** Resolve a course role from id, shortname, or exact role name. */
    public static function resolve_role_input(string $value, int $defaultroleid = 0): int {
        global $DB;
        $value = trim($value);
        if ($value === '') {
            return $defaultroleid;
        }
        if (ctype_digit($value)) {
            $roleid = (int)$value;
            return $DB->record_exists('role_context_levels', ['roleid' => $roleid, 'contextlevel' => CONTEXT_COURSE]) ? $roleid : 0;
        }
        $role = $DB->get_record('role', ['shortname' => $value]);
        if ($role && $DB->record_exists('role_context_levels', ['roleid' => $role->id, 'contextlevel' => CONTEXT_COURSE])) {
            return (int)$role->id;
        }
        foreach ($DB->get_records('role', null, '', 'id,name,shortname') as $candidate) {
            if (\core_text::strtolower(trim((string)$candidate->name)) === \core_text::strtolower($value) &&
                    $DB->record_exists('role_context_levels', ['roleid' => $candidate->id, 'contextlevel' => CONTEXT_COURSE])) {
                return (int)$candidate->id;
            }
        }
        return 0;
    }

    /**
     * Return the production automation engine.
     *
     * Production mode requires a detached PHP CLI worker. Backup/restore is never
     * executed by PHP-FPM and never depends on Moodle cron or ad-hoc tasks.
     *
     * @return array{available:bool,mode:string,php:string,launcher:string,nohup:string,reason:string}
     */
    public static function get_worker_health(): array {
        global $CFG;

        $script = $CFG->dirroot . '/' . $CFG->admin . '/tool/salasatipicas/cli/create_dependency.php';
        if (!is_file($script)) {
            return [
                'available' => false, 'mode' => '', 'php' => '', 'launcher' => '', 'nohup' => '',
                'reason' => get_string('dep_workerscriptmissing', 'tool_salasatipicas'),
            ];
        }

        $php = self::detect_php_cli();
        if ($php === '') {
            return [
                'available' => false, 'mode' => '', 'php' => '', 'launcher' => '', 'nohup' => '',
                'reason' => get_string('dep_workercliproductionrequired', 'tool_salasatipicas'),
            ];
        }

        if (PHP_OS_FAMILY === 'Windows') {
            if (!self::function_enabled('popen')) {
                return [
                    'available' => false, 'mode' => '', 'php' => $php, 'launcher' => '', 'nohup' => '',
                    'reason' => get_string('dep_workerlaunchfunctionmissing', 'tool_salasatipicas'),
                ];
            }
            return [
                'available' => true, 'mode' => 'cli', 'php' => $php, 'launcher' => 'popen', 'nohup' => '', 'reason' => '',
            ];
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
            return [
                'available' => false, 'mode' => '', 'php' => $php, 'launcher' => $launcher, 'nohup' => $nohup,
                'reason' => get_string('dep_workerlaunchfunctionmissing', 'tool_salasatipicas'),
            ];
        }

        return [
            'available' => true, 'mode' => 'cli', 'php' => $php, 'launcher' => $launcher, 'nohup' => $nohup, 'reason' => '',
        ];
    }

    /**
     * Assert that an independent automation engine is available.
     *
     * @throws \moodle_exception
     */
    public static function assert_worker_available(): void {
        $health = self::get_worker_health();
        if (!$health['available']) {
            throw new \moodle_exception('dep_workerunavailable', 'tool_salasatipicas', '', $health['reason']);
        }
    }

    /**
     * Store one dependency request in history without running backup/restore.
     *
     * The caller then dispatches a detached PHP CLI worker. Heavy backup/restore
     * never runs in the web request.
     *
     * @param array $definition Prepared definition.
     * @param string $source manual|csv|xlsx
     * @return int History id.
     */
    public static function create_dependency_request(array $definition, string $source = 'manual', int $retrycount = 0): int {
        global $DB, $USER;

        self::assert_creation_enabled();
        self::assert_worker_available();

        $factory = \core\lock\lock_config::get_lock_factory('tool_salasatipicas');
        $targetlock = $factory->get_lock(self::target_lock_key((string)$definition['targetshortname']), 10);
        if (!$targetlock) {
            throw new \moodle_exception('dep_targetbusy', 'tool_salasatipicas');
        }

        try {
            // Recheck under a cross-request lock to close the race between preview and confirmation.
            if ($DB->record_exists('course', ['shortname' => (string)$definition['targetshortname']]) ||
                    $DB->record_exists_select('tool_salasat_dep_hist', 'targetshortname = ? AND status <> ?',
                        [(string)$definition['targetshortname'], self::STATUS_ERROR])) {
                throw new \moodle_exception('dep_duplicatecourse', 'tool_salasatipicas', '', $definition['targetshortname']);
            }

            $now = time();
            $history = (object)[
                'processnumber' => $definition['processnumber'],
                'subject' => $definition['subject'],
                'period' => $definition['period'],
                'studentuserid' => $definition['studentuserid'],
                'teacheruserid' => $definition['teacheruserid'],
                'studentname' => $definition['studentname'],
                'teachername' => $definition['teachername'],
                'studentusername' => $definition['studentusername'],
                'teacherusername' => $definition['teacherusername'],
                'categoryconfigid' => $definition['categoryconfigid'],
                'coursecategoryid' => $definition['coursecategoryid'],
                'sourcecourseid' => $definition['templatecourseid'],
                'targetcourseid' => 0,
                'targetfullname' => $definition['targetfullname'],
                'targetshortname' => $definition['targetshortname'],
                'backupid' => '',
                'restoreid' => '',
                'source' => in_array($source, ['manual', 'csv', 'xlsx'], true) ? $source : 'manual',
                'status' => self::STATUS_QUEUED,
                'retrycount' => max(0, $retrycount),
                'message' => get_string('dep_workerqueued', 'tool_salasatipicas'),
                'createdby' => (int)$USER->id,
                'timecreated' => $now,
                'timemodified' => $now,
            ];

            $transaction = $DB->start_delegated_transaction();
            $historyid = (int)$DB->insert_record('tool_salasat_dep_hist', $history);
            $participants = $definition['participants'] ?? [];
            foreach ($participants as $participant) {
                $DB->insert_record('tool_salasat_dep_part', (object)[
                    'historyid' => $historyid,
                    'userid' => (int)$participant['userid'],
                    'username' => (string)$participant['username'],
                    'fullname' => (string)$participant['fullname'],
                    'participanttype' => (string)$participant['participanttype'],
                    'roleid' => (int)$participant['roleid'],
                    'sortorder' => (int)$participant['sortorder'],
                    'timecreated' => $now,
                ]);
            }
            $transaction->allow_commit();
            return $historyid;
        } finally {
            $targetlock->release();
        }
    }

    /**
     * Create and dispatch one dependency request.
     *
     * The detached CLI worker is launched immediately after the request is stored.
     *
     * @param array $definition
     * @param string $source
     * @return int
     */
    public static function create_dependency(array $definition, string $source = 'manual', int $retrycount = 0): int {
        $historyid = self::create_dependency_request($definition, $source, $retrycount);
        $health = self::get_worker_health();

        if (($health['mode'] ?? '') === 'cli') {
            try {
                self::launch_worker($historyid);
            } catch (\Throwable $e) {
                self::mark_error($historyid, $e->getMessage());
                throw $e;
            }
        }

        return $historyid;
    }

    /**
     * Backwards compatible wrapper.
     *
     * @param array $definition
     * @param string $source
     * @return int
     */
    public static function queue_dependency(array $definition, string $source = 'manual'): int {
        return self::create_dependency($definition, $source);
    }

    /**
     * Launch one detached PHP CLI worker.
     *
     * @param int $historyid
     * @return array{pid:int,log:string}
     */
    public static function launch_worker(int $historyid): array {
        global $CFG, $DB;

        $health = self::get_worker_health();
        if (!$health['available'] || ($health['mode'] ?? '') !== 'cli') {
            throw new \moodle_exception('dep_workerunavailable', 'tool_salasatipicas', '', $health['reason']);
        }

        $script = $CFG->dirroot . '/' . $CFG->admin . '/tool/salasatipicas/cli/create_dependency.php';
        $tempdir = !empty($CFG->tempdir) ? $CFG->tempdir : ($CFG->dataroot . DIRECTORY_SEPARATOR . 'temp');
        $logdir = rtrim((string)$tempdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tool_salasatipicas_dep';
        if (!make_writable_directory($logdir)) {
            throw new \moodle_exception('dep_workerlogdirfailed', 'tool_salasatipicas');
        }
        self::cleanup_old_worker_logs($logdir);
        $logfile = $logdir . DIRECTORY_SEPARATOR . 'worker-' . $historyid . '.log';

        $php = escapeshellarg($health['php']);
        $scriptarg = escapeshellarg($script);
        $idarg = '--historyid=' . (int)$historyid;
        $logarg = escapeshellarg($logfile);
        $pid = 0;

        if (PHP_OS_FAMILY === 'Windows') {
            $command = 'start /B "" ' . $php . ' ' . $scriptarg . ' ' . $idarg .
                ' >> ' . $logarg . ' 2>&1';
            $handle = @popen($command, 'r');
            if ($handle === false) {
                throw new \moodle_exception('dep_workerlaunchfailed', 'tool_salasatipicas');
            }
            @pclose($handle);
        } else {
            $command = escapeshellarg($health['nohup']) . ' ' . $php . ' ' . $scriptarg . ' ' . $idarg .
                ' >> ' . $logarg . ' 2>&1 < /dev/null & echo $!';

            if ($health['launcher'] === 'exec') {
                $output = [];
                $returncode = 1;
                @exec($command, $output, $returncode);
                if ($returncode !== 0) {
                    throw new \moodle_exception('dep_workerlaunchfailed', 'tool_salasatipicas');
                }
                if ($output) {
                    $pid = (int)trim((string)end($output));
                }
            } else {
                $output = @shell_exec($command);
                if ($output === null) {
                    throw new \moodle_exception('dep_workerlaunchfailed', 'tool_salasatipicas');
                }
                $pid = (int)trim($output);
            }
        }

        $message = get_string('dep_workerstarted', 'tool_salasatipicas');
        if ($pid > 0) {
            $message .= ' PID ' . $pid . '.';
        }
        $DB->execute(
            'UPDATE {tool_salasat_dep_hist} SET message = ?, timemodified = ? WHERE id = ? AND status = ?',
            [$message, time(), $historyid, self::STATUS_QUEUED]
        );

        return ['pid' => $pid, 'log' => $logfile];
    }


    /**
     * Execute one dependency completely from the CLI worker.
     *
     * This is the heavy part of the automation. It deliberately runs outside the
     * browser, without cron and without Moodle ad-hoc tasks.
     *
     * @param int $historyid
     * @return void
     */
    public static function process_history_worker(int $historyid): void {
        global $CFG, $DB;

        // Hard safety boundary: heavy backup/restore is forbidden in web requests.
        // This prevents a future refactor or legacy endpoint from reintroducing gateway timeouts.
        if (!defined('CLI_SCRIPT') || !CLI_SCRIPT) {
            throw new \moodle_exception('dep_workerclionly', 'tool_salasatipicas');
        }

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        require_once($CFG->dirroot . '/backup/moodle2/backup_plan_builder.class.php');
        require_once($CFG->dirroot . '/backup/util/helper/copy_helper.class.php');
        require_once($CFG->dirroot . '/course/lib.php');

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
            $history = $DB->get_record('tool_salasat_dep_hist', ['id' => $historyid], '*', MUST_EXIST);
            if ($history->status === self::STATUS_READY || $history->status === self::STATUS_ERROR) {
                return;
            }

            // Course backup/restore is intentionally serialised across the plugin. This protects
            // the server if two administrators submit manual creations at the same time.
            self::update_history_message($historyid, get_string('dep_workerwaitingglobal', 'tool_salasatipicas'));
            $globallock = $factory->get_lock('global_creation_worker', HOURSECS);
            if (!$globallock) {
                throw new \moodle_exception('dep_workerbusytimeout', 'tool_salasatipicas');
            }

            // During migration, also respect the global locks used by both legacy plugins.
            // This prevents an old Dependências/REA worker and this central from running heavy
            // backup/restore operations at the same time.
            $legacydepfactory = \core\lock\lock_config::get_lock_factory('tool_salasdependencias');
            $legacyreafactory = \core\lock\lock_config::get_lock_factory('tool_salasrea');
            $legacydeplock = $legacydepfactory->get_lock('global_creation_worker', HOURSECS);
            if (!$legacydeplock) {
                throw new \moodle_exception('dep_workerbusytimeout', 'tool_salasatipicas');
            }
            $legacyrealock = $legacyreafactory->get_lock('global_creation_worker', HOURSECS);
            if (!$legacyrealock) {
                throw new \moodle_exception('dep_workerbusytimeout', 'tool_salasatipicas');
            }

            $targetlock = $factory->get_lock(self::target_lock_key((string)$history->targetshortname), 60);
            if (!$targetlock) {
                throw new \moodle_exception('dep_targetbusy', 'tool_salasatipicas');
            }

            $creator = $DB->get_record('user', ['id' => (int)$history->createdby, 'deleted' => 0], '*', MUST_EXIST);
            \core\cron::setup_user($creator);

            if (!$DB->record_exists('course', ['id' => (int)$history->sourcecourseid])) {
                throw new \moodle_exception('dep_templatenotconfigured', 'tool_salasatipicas');
            }
            if (!$DB->record_exists('course_categories', ['id' => (int)$history->coursecategoryid])) {
                throw new \moodle_exception('dep_invalidmoodlecategory', 'tool_salasatipicas');
            }
            if ($DB->record_exists('course', ['shortname' => (string)$history->targetshortname])) {
                throw new \moodle_exception('dep_duplicatecourse', 'tool_salasatipicas', '', $history->targetshortname);
            }

            $participants = self::get_history_participants($historyid, true);
            if (!$participants) {
                throw new \moodle_exception('dep_participantsmissing', 'tool_salasatipicas');
            }
            foreach ($participants as $participant) {
                if ((int)$participant->userid <= 0 || !$DB->record_exists('user', [
                        'id' => (int)$participant->userid, 'deleted' => 0])) {
                    throw new \moodle_exception('dep_participantusernotfound', 'tool_salasatipicas', '', $participant->username);
                }
                if ((int)$participant->roleid <= 0 || !$DB->record_exists('role_context_levels', [
                        'roleid' => (int)$participant->roleid, 'contextlevel' => CONTEXT_COURSE])) {
                    throw new \moodle_exception('dep_participantroleinvalid', 'tool_salasatipicas', '', $participant->fullname);
                }
            }

            $DB->update_record('tool_salasat_dep_hist', (object)[
                'id' => $historyid,
                'status' => self::STATUS_PROCESSING,
                'message' => get_string('dep_workerprocessing', 'tool_salasatipicas'),
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

            self::update_history_message($historyid, get_string('dep_syncbackup', 'tool_salasatipicas'));

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
            $DB->update_record('tool_salasat_dep_hist', (object)[
                'id' => $historyid,
                'backupid' => $backupid,
                'timemodified' => time(),
            ]);

            $bc->execute_plan();
            if ($bc->get_status() !== \backup::STATUS_FINISHED_OK) {
                throw new \moodle_exception('dep_syncbackupfailed', 'tool_salasatipicas');
            }

            $results = $bc->get_results();
            if (empty($results['backup_destination'])) {
                throw new \moodle_exception('dep_syncbackupfilemissing', 'tool_salasatipicas');
            }

            $backupbasepath = $backupplan->get_basepath();
            $backupfile = $results['backup_destination'];
            $backupfile->extract_to_pathname(
                get_file_packer('application/vnd.moodle.backup'),
                $backupbasepath
            );

            $bc->destroy();
            $bc = null;

            self::update_history_message($historyid, get_string('dep_synccreatecourse', 'tool_salasatipicas'));
            [$placeholderfullname, $placeholdershortname] = \restore_dbops::calculate_course_names(
                0,
                get_string('copyingcourse', 'backup'),
                get_string('copyingcourseshortname', 'backup')
            );
            $targetcourseid = (int)\restore_dbops::create_new_course(
                $placeholderfullname,
                $placeholdershortname,
                (int)$history->coursecategoryid
            );
            $DB->update_record('tool_salasat_dep_hist', (object)[
                'id' => $historyid,
                'targetcourseid' => $targetcourseid,
                'timemodified' => time(),
            ]);

            self::update_history_message($historyid, get_string('dep_syncrestore', 'tool_salasatipicas'));

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
            $DB->update_record('tool_salasat_dep_hist', (object)[
                'id' => $historyid,
                'restoreid' => $restoreid,
                'timemodified' => time(),
            ]);

            $plan = $rc->get_plan();
            $plan->get_setting('course_startdate')->set_value($copydata->startdate);
            $plan->get_setting('course_fullname')->set_value($copydata->fullname);
            $plan->get_setting('course_shortname')->set_value($copydata->shortname);

            $hook = new \core_backup\hook\before_copy_course_execute($plan, $copydata);
            \core\di::get(\core\hook\manager::class)->dispatch($hook);

            $rc->execute_precheck();
            if ($rc->get_status() !== \backup::STATUS_AWAITING) {
                $precheck = $rc->get_precheck_results();
                $details = self::precheck_error_text($precheck);
                throw new \moodle_exception('dep_syncrestoreprecheckfailed', 'tool_salasatipicas', '', null, $details);
            }

            $rc->execute_plan();
            if ($rc->get_status() !== \backup::STATUS_FINISHED_OK) {
                throw new \moodle_exception('dep_syncrestorefailed', 'tool_salasatipicas');
            }

            $course = $DB->get_record('course', ['id' => $targetcourseid], '*', MUST_EXIST);
            $course->visible = (int)$copydata->visible;
            $course->idnumber = (string)$copydata->idnumber;
            $course->enddate = (int)$copydata->enddate;
            $DB->update_record('course', $course);

            $rc->destroy();
            $rc = null;

            self::update_history_message($historyid, get_string('dep_syncenrolparticipants', 'tool_salasatipicas'));
            foreach ($participants as $participant) {
                self::manual_enrol($targetcourseid, (int)$participant->userid, (int)$participant->roleid);
            }

            rebuild_course_cache($targetcourseid, true);
            \cache_helper::purge_by_event('changesincourse');

            $DB->update_record('tool_salasat_dep_hist', (object)[
                'id' => $historyid,
                'status' => self::STATUS_READY,
                'message' => get_string('dep_dependencyready', 'tool_salasatipicas'),
                'timemodified' => time(),
            ]);
            try {
                \tool_salasatipicas\event\dependency_created::create([
                    'objectid' => $historyid,
                    'context' => \context_course::instance($targetcourseid),
                    'other' => ['processnumber' => (string)$history->processnumber],
                ])->trigger();
            } catch (\Throwable $logerror) {
                debugging('tool_salasatipicas audit event: ' . $logerror->getMessage(), DEBUG_DEVELOPER);
            }
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
            $DB->update_record('tool_salasat_dep_hist', (object)[
                'id' => $historyid,
                'targetcourseid' => $targetcourseid,
                'status' => self::STATUS_ERROR,
                'message' => $e->getMessage() . $cleanuperror,
                'timemodified' => time(),
            ]);
            try {
                \tool_salasatipicas\event\dependency_failed::create([
                    'objectid' => $historyid,
                    'context' => \context_system::instance(),
                    'other' => ['processnumber' => isset($history) ? (string)$history->processnumber : ''],
                ])->trigger();
            } catch (\Throwable $logerror) {
                debugging('tool_salasatipicas audit event: ' . $logerror->getMessage(), DEBUG_DEVELOPER);
            }
            throw $e;
        } finally {
            if ($rc) {
                try {
                    $rc->destroy();
                } catch (\Throwable $ignored) {
                }
            }
            if ($bc) {
                try {
                    $bc->destroy();
                } catch (\Throwable $ignored) {
                }
            }
            if ($backupfile) {
                try {
                    $backupfile->delete();
                } catch (\Throwable $ignored) {
                }
            }
            if ($backupbasepath !== '' && empty($CFG->keeptempdirectoriesonbackup)) {
                try {
                    fulldelete($backupbasepath);
                } catch (\Throwable $ignored) {
                }
            }
            if ($targetlock) {
                $targetlock->release();
            }
            if ($legacyrealock) {
                $legacyrealock->release();
            }
            if ($legacydeplock) {
                $legacydeplock->release();
            }
            if ($globallock) {
                $globallock->release();
            }
            $lock->release();
        }
    }

    /**
     * Update history status text.
     *
     * @param int $historyid
     * @param string $message
     */
    private static function update_history_message(int $historyid, string $message): void {
        global $DB;
        $DB->update_record('tool_salasat_dep_hist', (object)[
            'id' => $historyid,
            'message' => $message,
            'timemodified' => time(),
        ]);
    }

    /**
     * Convert restore precheck details into a compact diagnostic string.
     *
     * @param array $precheck
     * @return string
     */
    private static function precheck_error_text(array $precheck): string {
        if (empty($precheck['errors'])) {
            return get_string('dep_syncrestoreprecheckfailed', 'tool_salasatipicas');
        }
        $encoded = json_encode($precheck['errors'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded === false ? get_string('dep_syncrestoreprecheckfailed', 'tool_salasatipicas') : $encoded;
    }

    /**
     * Finish enrolments after Moodle reports that the course restore completed.
     *
     * This is intentionally lightweight and may run from a course_restored event observer
     * or from an admin page refresh. It never performs backup or restore.
     *
     * @param int $historyid
     * @return bool True when the history is ready after this call.
     */
    public static function finalise_history(int $historyid): bool {
        global $DB;

        $history = $DB->get_record('tool_salasat_dep_hist', ['id' => $historyid]);
        if (!$history) {
            return false;
        }
        if ($history->status === self::STATUS_READY) {
            return true;
        }
        if ($history->status === self::STATUS_ERROR) {
            return false;
        }

        $course = $DB->get_record('course', ['id' => (int)$history->targetcourseid]);
        if (!$course || (string)$course->shortname !== (string)$history->targetshortname) {
            return false;
        }

        try {
            $participants = self::get_history_participants($historyid, true);
            if (!$participants) {
                throw new \moodle_exception('dep_participantsmissing', 'tool_salasatipicas');
            }
            foreach ($participants as $participant) {
                self::manual_enrol((int)$course->id, (int)$participant->userid, (int)$participant->roleid);
            }

            $DB->update_record('tool_salasat_dep_hist', (object)[
                'id' => $historyid,
                'status' => self::STATUS_READY,
                'message' => get_string('dep_dependencyready', 'tool_salasatipicas'),
                'timemodified' => time(),
            ]);
            return true;
        } catch (\Throwable $e) {
            self::mark_error($historyid, get_string('dep_postrestoreenrolerror', 'tool_salasatipicas', $e->getMessage()));
            return false;
        }
    }

    /**
     * Refresh only legacy 0.2.1 course-copy rows.
     *
     * New 0.3.x worker rows are updated by the independent CLI process itself and
     * must never be timed out by a page refresh.
     *
     * @return void
     */
    public static function refresh_history_statuses(): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

        $legacy = $DB->get_records('tool_salasat_dep_hist', ['status' => self::STATUS_COPYING], 'timecreated ASC');
        foreach ($legacy as $history) {
            if ((int)$history->targetcourseid > 0) {
                $course = $DB->get_record('course', ['id' => (int)$history->targetcourseid], 'id,shortname');
                if ($course && (string)$course->shortname === (string)$history->targetshortname) {
                    self::finalise_history((int)$history->id);
                    continue;
                }
            }

            foreach ([(string)$history->backupid, (string)$history->restoreid] as $controllerid) {
                if ($controllerid === '') {
                    continue;
                }
                $controller = $DB->get_record('backup_controllers', ['backupid' => $controllerid], 'status');
                if ($controller && (int)$controller->status === \backup::STATUS_FINISHED_ERR) {
                    self::mark_error((int)$history->id, get_string('dep_corecopyfailed', 'tool_salasatipicas'));
                    continue 2;
                }
            }
        }
    }

    /**
     * Return persisted participants for one history row.
     *
     * Existing RC1 rows are supported through a legacy fallback using the principal
     * teacher/student columns, so upgrades do not break retries or old pending rows.
     *
     * @param int $historyid
     * @param bool $legacyfallback
     * @return array
     */
    public static function get_history_participants(int $historyid, bool $legacyfallback = true): array {
        global $DB;

        $records = $DB->get_records('tool_salasat_dep_part', ['historyid' => $historyid], 'sortorder ASC, id ASC');
        if ($records || !$legacyfallback) {
            return array_values($records);
        }

        $history = $DB->get_record('tool_salasat_dep_hist', ['id' => $historyid]);
        if (!$history) {
            return [];
        }
        $now = (int)$history->timecreated;
        $studentroleid = (int)self::get_config_value('studentroleid', self::role_id_by_shortname('student'));
        $teacherroleid = (int)self::get_config_value('teacherroleid', self::role_id_by_shortname('editingteacher'));
        $legacy = [];
        if ((int)$history->teacheruserid > 0) {
            $legacy[] = (object)[
                'id' => 0, 'historyid' => $historyid, 'userid' => (int)$history->teacheruserid,
                'username' => (string)$history->teacherusername, 'fullname' => (string)$history->teachername,
                'participanttype' => 'teacher', 'roleid' => $teacherroleid, 'sortorder' => 0, 'timecreated' => $now,
            ];
        }
        if ((int)$history->studentuserid > 0) {
            $legacy[] = (object)[
                'id' => 0, 'historyid' => $historyid, 'userid' => (int)$history->studentuserid,
                'username' => (string)$history->studentusername, 'fullname' => (string)$history->studentname,
                'participanttype' => 'student', 'roleid' => $studentroleid, 'sortorder' => 1, 'timecreated' => $now,
            ];
        }
        return $legacy;
    }

    /** Get participants for many history rows in one query, grouped by history id. */
    public static function get_participants_for_histories(array $historyids): array {
        global $DB;
        $historyids = array_values(array_unique(array_filter(array_map('intval', $historyids))));
        if (!$historyids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($historyids, SQL_PARAMS_NAMED, 'hid');
        $records = $DB->get_records_select('tool_salasat_dep_part', "historyid {$insql}", $params, 'historyid ASC, sortorder ASC, id ASC');
        $grouped = [];
        foreach ($records as $record) {
            $grouped[(int)$record->historyid][] = $record;
        }
        return $grouped;
    }

    /** Human-readable participant list for previews/history. */
    public static function participants_summary(array $participants, string $type = ''): string {
        global $DB;
        $parts = [];
        $roles = [];
        foreach ($participants as $participant) {
            $participant = (object)$participant;
            if ($type !== '' && (string)$participant->participanttype !== $type) {
                continue;
            }
            $roleid = (int)$participant->roleid;
            if (!isset($roles[$roleid])) {
                $role = $DB->get_record('role', ['id' => $roleid]);
                $roles[$roleid] = $role ? role_get_name($role, \context_system::instance(), ROLENAME_ORIGINAL) : ('#' . $roleid);
            }
            $parts[] = (string)$participant->fullname . ' (' . (string)$participant->username . ') — ' . $roles[$roleid];
        }
        return implode("\n", $parts);
    }

    /**
     * Enrol a user using the manual enrolment method.
     *
     * @param int $courseid
     * @param int $userid
     * @param int $roleid
     */
    public static function manual_enrol(int $courseid, int $userid, int $roleid): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/enrollib.php');

        $plugin = enrol_get_plugin('manual');
        if (!$plugin) {
            throw new \moodle_exception('dep_manualenrolmissing', 'tool_salasatipicas');
        }

        $instance = null;
        foreach (enrol_get_instances($courseid, true) as $candidate) {
            if ($candidate->enrol === 'manual') {
                $instance = $candidate;
                break;
            }
        }
        if (!$instance) {
            $course = get_course($courseid);
            $instanceid = $plugin->add_instance($course, [
                'status' => ENROL_INSTANCE_ENABLED,
                'roleid' => $roleid,
            ]);
            if (!$instanceid) {
                throw new \moodle_exception('dep_cannotcreatemanualenrol', 'tool_salasatipicas');
            }
            $instance = $DB->get_record('enrol', [
                'id' => $instanceid,
                'courseid' => $courseid,
                'enrol' => 'manual',
            ], '*', MUST_EXIST);
        }
        // enrol_plugin::enrol_user() requires the complete enrol instance object, not its id.
        $plugin->enrol_user($instance, $userid, $roleid, 0, 0, ENROL_USER_ACTIVE);
    }

    /**
     * Mark history as error.
     *
     * @param int $historyid
     * @param string $message
     */
    public static function mark_error(int $historyid, string $message): void {
        global $DB;
        $DB->update_record('tool_salasat_dep_hist', (object)[
            'id' => $historyid,
            'status' => self::STATUS_ERROR,
            'message' => $message,
            'timemodified' => time(),
        ]);
    }


    /**
     * Discover a usable PHP CLI binary without requiring manual Moodle configuration.
     *
     * @return string Absolute executable path or empty string.
     */
    private static function detect_php_cli(): string {
        global $CFG;

        $candidates = [];
        $configured = trim((string)($CFG->pathtophp ?? ''));
        if ($configured !== '') {
            $candidates[] = $configured;
        }

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
            if ($candidate !== '' && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Check whether a PHP process-launch function is callable and not disabled.
     *
     * @param string $name
     * @return bool
     */
    private static function function_enabled(string $name): bool {
        if (!function_exists($name)) {
            return false;
        }
        $disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
        return !in_array($name, $disabled, true);
    }

    /**
     * Normalise an official process number.
     *
     * When the administrator enters only the numeric part (for example 852),
     * append the year found in the academic period (for example 02/2026),
     * resulting in 852/2026. Values that already include a slash are kept.
     *
     * @param string $processnumber
     * @param string $period
     * @return string
     */
    public static function normalise_process_number(string $processnumber, string $period): string {
        $processnumber = trim($processnumber);
        if ($processnumber === '' || strpos($processnumber, '/') !== false) {
            return $processnumber;
        }

        if (preg_match('/(?:^|[^0-9])((?:19|20)\\d{2})(?:$|[^0-9])/', $period, $matches)) {
            return $processnumber . '/' . $matches[1];
        }

        return $processnumber;
    }

    /**
     * Return a professor name suitable for the configurable room title.
     *
     * Institutional Moodle accounts may already store prefixes such as
     * "Prof." or "Profa." in the first name. Since the default naming rule
     * already adds "Prof." before {PROFESSOR}, strip a leading teaching title
     * from the variable to avoid names such as "Prof. Prof. Márcio Araya".
     * The original Moodle full name is still preserved in history and review.
     *
     * @param string $fullname
     * @return string
     */
    public static function normalise_teacher_name_for_title(string $fullname): string {
        $fullname = trim($fullname);
        if ($fullname === '') {
            return '';
        }

        $clean = preg_replace(
            '/^(?:prof(?:essor)?a?\\.?|profª\\.?|profº\\.?)\\s+/iu',
            '',
            $fullname
        );
        return trim($clean ?: $fullname);
    }

    /**
     * Create a safe Moodle course shortname.
     *
     * @param string $value
     * @return string
     */
    public static function normalise_shortname(string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = \core_text::specialtoascii($value);
        $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value);
        $value = preg_replace('/-+/', '-', $value);
        return trim($value, '-_. ');
    }

    /**
     * Normalise the institutional semester notation to 01/YYYY or 02/YYYY.
     * Handles the most common transformations introduced by Excel, including
     * jan/26 and fev/26, without silently accepting other months as semesters.
     */
    public static function normalise_period(string $value): string {
        $value = trim($value);
        $value = trim($value, "\\t\\n\\r\\0\\x0B'\\\"");
        if ($value === '') {
            return '';
        }

        $plain = \core_text::strtolower(\core_text::specialtoascii($value));
        $plain = preg_replace('/\\s+/', '', $plain);

        if (preg_match('/^(0?[12])\\/(\\d{4})$/', $plain, $m)) {
            return sprintf('%02d/%04d', (int)$m[1], (int)$m[2]);
        }
        if (preg_match('/^(0?[12])\\/(\\d{2})$/', $plain, $m)) {
            return sprintf('%02d/20%02d', (int)$m[1], (int)$m[2]);
        }

        $months = [
            'jan' => '01', 'janeiro' => '01', 'january' => '01',
            'fev' => '02', 'feb' => '02', 'fevereiro' => '02', 'february' => '02',
        ];
        if (preg_match('/^([a-z]+)[\\/\\-](\\d{2,4})$/', $plain, $m) && isset($months[$m[1]])) {
            $year = strlen($m[2]) === 2 ? '20' . $m[2] : $m[2];
            return $months[$m[1]] . '/' . $year;
        }

        // Excel may export the underlying date as dd/mm/yyyy. Only January and February
        // are meaningful for the institutional 01/02 semester convention.
        if (preg_match('/^\\d{1,2}\\/(0?[12])\\/(\\d{4})$/', $plain, $m)) {
            return sprintf('%02d/%04d', (int)$m[1], (int)$m[2]);
        }
        if (preg_match('/^(\\d{4})-(0?[12])(?:-\\d{1,2})?$/', $plain, $m)) {
            return sprintf('%02d/%04d', (int)$m[2], (int)$m[1]);
        }

        return $value;
    }

    /** Stable cross-request lock key for a target course name. */
    private static function target_lock_key(string $shortname): string {
        return 'target_' . sha1(\core_text::strtolower(trim($shortname)));
    }

    /** Delete worker logs older than 30 days whenever a new worker starts. */
    private static function cleanup_old_worker_logs(string $logdir): void {
        $threshold = time() - (30 * DAYSECS);
        foreach ((array)glob(rtrim($logdir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'worker-*.log') as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime < $threshold) {
                @unlink($file);
            }
        }
    }

    /**
     * Human label for a history status.
     *
     * @param string $status
     * @return string
     */
    public static function status_label(string $status): string {
        $key = 'dep_status_' . $status;
        return get_string_manager()->string_exists($key, 'tool_salasatipicas')
            ? get_string($key, 'tool_salasatipicas')
            : $status;
    }
}
