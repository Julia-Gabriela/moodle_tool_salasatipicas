<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_salasatipicas\form\dependency_form;
use tool_salasatipicas\form\dependency_import_form as import_form;
use tool_salasatipicas\local\dependency_importer as importer;
use tool_salasatipicas\local\dependency_manager as manager;

require_login();
if (!is_siteadmin()) {
    throw new moodle_exception('dep_siteadminonly', 'tool_salasatipicas');
}
admin_externalpage_setup('tool_salasatipicas');
require_capability('tool/salasatipicas:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/admin/tool/salasatipicas/dependency.php'));
$PAGE->set_title(get_string('dep_manage', 'tool_salasatipicas'));
$PAGE->set_heading(get_string('dep_manage', 'tool_salasatipicas'));
$PAGE->requires->css(new moodle_url('/admin/tool/salasatipicas/styles.css'));

// Lightweight status refresh only; never runs backup/restore in this request.
manager::refresh_history_statuses();
$creationenabled = manager::creation_enabled();
$workerhealth = manager::get_worker_health();
$creationavailable = $creationenabled && $workerhealth['available'];
$action = optional_param('action', '', PARAM_ALPHA);
$queueerror = optional_param('queueerror', '', PARAM_TEXT);
$requestedpreviewtoken = optional_param('previewtoken', '', PARAM_ALPHANUM);

// Final manual confirmation. No course is created before this explicit POST.
if ($action === 'confirmmanual') {
    require_sesskey();
    if (!$creationenabled) {
        redirect($PAGE->url, get_string('dep_creationdisabled', 'tool_salasatipicas'), null,
            \core\output\notification::NOTIFY_WARNING);
    }

    $token = required_param('token', PARAM_ALPHANUM);
    $payload = $SESSION->tool_salasatipicas_dep_manual[$token] ?? null;
    if (!$payload || ($payload['time'] ?? 0) < time() - 7200) {
        unset($SESSION->tool_salasatipicas_dep_manual[$token]);
        redirect($PAGE->url, get_string('dep_manualsessionexpired', 'tool_salasatipicas'), null,
            \core\output\notification::NOTIFY_ERROR);
    }

    // Revalidate against the current Moodle/config state at the moment of creation.
    $prepared = manager::prepare_definition($payload['raw']);
    if (!$prepared['valid']) {
        unset($SESSION->tool_salasatipicas_dep_manual[$token]);
        redirect($PAGE->url, implode('<br>', array_map('s', $prepared['errors'])), null,
            \core\output\notification::NOTIFY_ERROR);
    }

    // Consume the preview token and register the request. Heavy backup/restore is
    // never executed before the browser has been released.
    unset($SESSION->tool_salasatipicas_dep_manual[$token]);
    $historyid = 0;
    try {
        $historyid = manager::create_dependency_request($prepared['definition'], 'manual');
        // Production mode is CLI-only: this call only spawns a detached process and returns.
        // Backup/restore never runs inside the web request.
        manager::launch_worker($historyid);
        redirect(new moodle_url('/admin/tool/salasatipicas/dependency_history.php', ['queued' => $historyid]));
    } catch (Throwable $e) {
        if ($historyid > 0) {
            manager::mark_error($historyid, $e->getMessage());
        }
        redirect(new moodle_url('/admin/tool/salasatipicas/dependency.php', [
            'queueerror' => clean_param($e->getMessage(), PARAM_TEXT),
        ]));
    }
}

// Final import confirmation. The administrative creation switch is checked again server-side.
if ($action === 'confirmimport') {
    require_sesskey();
    if (!$creationenabled) {
        redirect($PAGE->url, get_string('dep_creationdisabled', 'tool_salasatipicas'), null,
            \core\output\notification::NOTIFY_WARNING);
    }

    $token = required_param('token', PARAM_ALPHANUM);
    if (empty($SESSION->tool_salasatipicas_dep_import[$token])) {
        redirect($PAGE->url, get_string('dep_importsessionexpired', 'tool_salasatipicas'), null,
            \core\output\notification::NOTIFY_ERROR);
    }
    $payload = $SESSION->tool_salasatipicas_dep_import[$token];
    if (($payload['time'] ?? 0) < time() - 7200) {
        unset($SESSION->tool_salasatipicas_dep_import[$token]);
        redirect($PAGE->url, get_string('dep_importsessionexpired', 'tool_salasatipicas'), null,
            \core\output\notification::NOTIFY_ERROR);
    }
    try {
        manager::assert_worker_available();
    } catch (Throwable $e) {
        redirect($PAGE->url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    // The progress screen starts one independent worker per row; it never waits for backup/restore.
    redirect(new moodle_url('/admin/tool/salasatipicas/dependency_import_process.php', ['token' => $token]));
}

$categories = manager::get_category_options();
$roles = manager::get_course_role_options();
$defaultteacherroleid = (int)manager::get_config_value('teacherroleid', manager::role_id_by_shortname('editingteacher'));
$defaultstudentroleid = (int)manager::get_config_value('studentroleid', manager::role_id_by_shortname('student'));
$defaultcoordinationroleid = (int)manager::get_config_value('coordinationroleid', $defaultteacherroleid);
$manualform = new dependency_form($PAGE->url, [
    'categories' => $categories,
    'teacherrolelabel' => $roles[$defaultteacherroleid] ?? ('#' . $defaultteacherroleid),
    'studentrolelabel' => $roles[$defaultstudentroleid] ?? ('#' . $defaultstudentroleid),
    'coordinationrolelabel' => $roles[$defaultcoordinationroleid] ?? ('#' . $defaultcoordinationroleid),
    'creationenabled' => $creationenabled,
]);
$importform = new import_form($PAGE->url);
$manualpreview = null;
$manualtoken = null;
$importpreview = null;
$importtoken = null;

// Manual review uses POST/Redirect/GET so the browser lands on the confirmation block
// instead of returning to the top of the page after validation.
if ($requestedpreviewtoken !== '') {
    $payload = $SESSION->tool_salasatipicas_dep_manual[$requestedpreviewtoken] ?? null;
    if (!$payload || ($payload['time'] ?? 0) < time() - 7200) {
        unset($SESSION->tool_salasatipicas_dep_manual[$requestedpreviewtoken]);
        redirect($PAGE->url, get_string('dep_manualsessionexpired', 'tool_salasatipicas'), null,
            \core\output\notification::NOTIFY_ERROR);
    }
    $prepared = manager::prepare_definition($payload['raw']);
    if (!$prepared['valid']) {
        unset($SESSION->tool_salasatipicas_dep_manual[$requestedpreviewtoken]);
        redirect($PAGE->url, implode('<br>', array_map('s', $prepared['errors'])), null,
            \core\output\notification::NOTIFY_ERROR);
    }
    $manualpreview = $prepared['definition'];
    $manualtoken = $requestedpreviewtoken;
}

// First manual submit is validation/preview only. It never creates a course.
if ($data = $manualform->get_data()) {
    $prepared = manager::prepare_definition($data);
    if (!$prepared['valid']) {
        redirect($PAGE->url, implode('<br>', array_map('s', $prepared['errors'])), null,
            \core\output\notification::NOTIFY_ERROR);
    }

    $raw = [
        'processnumber' => trim((string)$data->processnumber),
        'subject' => trim((string)$data->subject),
        'teacheruserids' => array_values(array_filter(array_map('intval', (array)$data->teacheruserids))),
        'coordinationuserids' => array_values(array_filter(array_map('intval', (array)($data->coordinationuserids ?? [])))),
        'studentuserids' => array_values(array_filter(array_map('intval', (array)$data->studentuserids))),
        'period' => trim((string)$data->period),
        'categoryconfigid' => (int)$data->categoryconfigid,
    ];

    $manualtoken = random_string(24);
    if (!isset($SESSION->tool_salasatipicas_dep_manual)) {
        $SESSION->tool_salasatipicas_dep_manual = [];
    }
    $SESSION->tool_salasatipicas_dep_manual[$manualtoken] = [
        'raw' => $raw,
        'time' => time(),
    ];
    $reviewurl = new moodle_url('/admin/tool/salasatipicas/dependency.php', ['previewtoken' => $manualtoken]);
    $reviewurl->set_anchor('salasdep-confirmation');
    redirect($reviewurl);
}

// File upload only parses and validates. It never creates a course at this stage.
if ($data = $importform->get_data()) {
    $filename = (string)$importform->get_new_filename('importfile');
    $tempfile = $importform->save_temp_file('importfile');
    try {
        $importpreview = importer::parse($tempfile, $filename);
        $validrows = [];
        foreach ($importpreview['rows'] as $row) {
            if ($row['valid']) {
                $validrows[] = $row['raw'];
            }
        }
        $importtoken = random_string(24);
        if (!isset($SESSION->tool_salasatipicas_dep_import)) {
            $SESSION->tool_salasatipicas_dep_import = [];
        }
        $SESSION->tool_salasatipicas_dep_import[$importtoken] = [
            'source' => $importpreview['source'],
            'rows' => $validrows,
            'time' => time(),
        ];
    } catch (Throwable $e) {
        @unlink($tempfile);
        redirect($PAGE->url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    @unlink($tempfile);
}

// Clear old preview sessions (2 hours).
foreach (['tool_salasatipicas_dep_manual', 'tool_salasatipicas_dep_import'] as $sessionkey) {
    if (!empty($SESSION->{$sessionkey}) && is_array($SESSION->{$sessionkey})) {
        foreach ($SESSION->{$sessionkey} as $token => $payload) {
            if (($payload['time'] ?? 0) < time() - 7200) {
                unset($SESSION->{$sessionkey}[$token]);
            }
        }
    }
}

echo $OUTPUT->header();

echo html_writer::start_div('salasdep-toolbar');
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/index.php'), '← ' . get_string('backtocentral', 'tool_salasatipicas'), ['class' => 'btn btn-secondary']);
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/dependency_configuration.php'),
    get_string('dep_settingsbutton', 'tool_salasatipicas'), ['class' => 'btn btn-secondary']);
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/dependency_history.php'),
    get_string('dep_history', 'tool_salasatipicas'), ['class' => 'btn btn-secondary']);
echo html_writer::end_div();

echo html_writer::div(get_string('dep_intro', 'tool_salasatipicas'), 'salasdep-intro');

if ($queueerror !== '') {
    echo $OUTPUT->notification(get_string('dep_creationerror', 'tool_salasatipicas', s($queueerror)),
        \core\output\notification::NOTIFY_ERROR);
}

if ($creationenabled) {
    if ($workerhealth['available']) {
        $a = (object)[
            'mode' => s($workerhealth['mode'] ?? ''),
            'php' => s($workerhealth['php'] ?? ''),
            'launcher' => s($workerhealth['launcher'] ?? ''),
        ];
        echo $OUTPUT->notification(get_string('dep_workerhealthycli', 'tool_salasatipicas', $a),
            \core\output\notification::NOTIFY_SUCCESS);
    } else {
        echo $OUTPUT->notification(get_string('dep_workerunhealthybanner', 'tool_salasatipicas', s($workerhealth['reason'])),
            \core\output\notification::NOTIFY_ERROR);
    }
} else {
    echo $OUTPUT->notification(get_string('dep_safemodeactive', 'tool_salasatipicas'),
        \core\output\notification::NOTIFY_SUCCESS);
}

if (empty($categories)) {
    echo $OUTPUT->notification(get_string('dep_nocategoriesconfiguredlong', 'tool_salasatipicas'),
        \core\output\notification::NOTIFY_WARNING);
}

$currentpattern = manager::get_config_value('fullnamepattern', manager::DEFAULT_FULLNAME_PATTERN);
echo html_writer::div(
    html_writer::tag('strong', get_string('dep_currentnamepattern', 'tool_salasatipicas')) . '<br>' . s($currentpattern),
    'salasdep-pattern'
);

echo html_writer::start_div('salasdep-grid');
echo html_writer::start_div('salasdep-card');
$manualform->display();
echo html_writer::end_div();

echo html_writer::start_div('salasdep-card');
$modellinks = html_writer::link(new moodle_url('/admin/tool/salasatipicas/dependency_download_template_xlsx.php', ['sesskey' => sesskey()]),
    get_string('dep_downloadmodelxlsx', 'tool_salasatipicas'), ['class' => 'btn btn-link p-0 mr-3']);
$modellinks .= html_writer::link(new moodle_url('/admin/tool/salasatipicas/dependency_download_template.php', ['sesskey' => sesskey()]),
    get_string('dep_downloadmodelcsv', 'tool_salasatipicas'), ['class' => 'btn btn-link p-0']);
echo html_writer::div($modellinks, 'mb-3');
$importform->display();
echo html_writer::end_div();
echo html_writer::end_div();

// Manual validation result / final confirmation.
if ($manualpreview) {
    echo html_writer::start_div('salasdep-preview mt-4', ['id' => 'salasdep-confirmation']);
    echo html_writer::tag('h3', $creationenabled
        ? get_string('dep_confirmmanualtitle', 'tool_salasatipicas')
        : get_string('dep_manualvalidationtitle', 'tool_salasatipicas'));

    $category = $DB->get_record('tool_salasat_dep_cat', ['id' => $manualpreview['categoryconfigid']]);
    $table = new html_table();
    $table->head = [get_string('dep_field', 'tool_salasatipicas'), get_string('dep_value', 'tool_salasatipicas')];
    $table->data = [
        [get_string('dep_processnumber', 'tool_salasatipicas'), s($manualpreview['processnumber'])],
        [get_string('dep_subject', 'tool_salasatipicas'), s($manualpreview['subject'])],
        [get_string('dep_teachers', 'tool_salasatipicas'), nl2br(s(manager::participants_summary($manualpreview['participants'], 'teacher')))],
        [get_string('dep_coordination', 'tool_salasatipicas'), ($coordsummary = manager::participants_summary($manualpreview['participants'], 'coordination')) !== '' ? nl2br(s($coordsummary)) : '-'],
        [get_string('dep_students', 'tool_salasatipicas'), nl2br(s(manager::participants_summary($manualpreview['participants'], 'student')))],
        [get_string('dep_period', 'tool_salasatipicas'), s($manualpreview['period'])],
        [get_string('dep_destinationcategory', 'tool_salasatipicas'), $category ? s($category->name) . ' (ID ' . (int)$category->categoryid . ')' : '-'],
        [get_string('dep_templatecourse', 'tool_salasatipicas'), (int)$manualpreview['templatecourseid']],
        [get_string('dep_generatedfullname', 'tool_salasatipicas'), s($manualpreview['targetfullname'])],
        [get_string('dep_generatedshortname', 'tool_salasatipicas'), s($manualpreview['targetshortname'])],
    ];
    echo html_writer::table($table);

    if ($creationavailable) {
        echo $OUTPUT->notification(get_string('dep_confirmmanualwarning', 'tool_salasatipicas'),
            \core\output\notification::NOTIFY_WARNING);
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false), 'class' => 'mt-3']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'confirmmanual']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'token', 'value' => $manualtoken]);
        echo html_writer::tag('button', get_string('dep_confirmcreation', 'tool_salasatipicas'), [
            'type' => 'submit', 'class' => 'btn btn-success salasdep-confirm-create',
        ]);
        echo html_writer::link($PAGE->url, get_string('cancel'), ['class' => 'btn btn-secondary ml-2']);
        echo html_writer::end_tag('form');
    } else {
        $msg = $creationenabled
            ? get_string('dep_workervalidationonlynotice', 'tool_salasatipicas')
            : get_string('dep_validationonlynotice', 'tool_salasatipicas');
        echo $OUTPUT->notification($msg, \core\output\notification::NOTIFY_WARNING);
    }
    echo html_writer::end_div();
}

if ($importpreview) {
    $validcount = count(array_filter($importpreview['rows'], fn($row) => $row['valid']));
    echo html_writer::start_div('salasdep-preview mt-4');
    echo html_writer::tag('h3', get_string('dep_importpreview', 'tool_salasatipicas'));
    echo html_writer::tag('p', get_string('dep_previewsummary', 'tool_salasatipicas', (object)[
        'valid' => $validcount,
        'total' => count($importpreview['rows']),
    ]));

    $table = new html_table();
    $table->head = [
        get_string('dep_line', 'tool_salasatipicas'),
        get_string('dep_processnumber', 'tool_salasatipicas'),
        get_string('dep_subject', 'tool_salasatipicas'),
        get_string('dep_teachers', 'tool_salasatipicas'),
        get_string('dep_coordination', 'tool_salasatipicas'),
        get_string('dep_students', 'tool_salasatipicas'),
        get_string('dep_status', 'tool_salasatipicas'),
        get_string('dep_details', 'tool_salasatipicas'),
    ];
    foreach ($importpreview['rows'] as $row) {
        $status = $row['valid'] ? get_string('dep_validrow', 'tool_salasatipicas') : get_string('dep_invalidrow', 'tool_salasatipicas');
        $detail = $row['valid'] ? s($row['fullname']) : s(implode(' | ', $row['errors']));
        $table->data[] = [
            $row['line'],
            s($row['raw']['processnumber']),
            s($row['raw']['subject']),
            s(implode(' | ', (array)($row['raw']['teacherusernames'] ?? []))),
            s(implode(' | ', (array)($row['raw']['coordinationusernames'] ?? []))),
            s(implode(' | ', (array)($row['raw']['studentusernames'] ?? []))),
            $status,
            $detail,
        ];
    }
    echo html_writer::table($table);

    if ($validcount > 0 && $creationavailable) {
        echo $OUTPUT->notification(get_string('dep_confirmimportwarning', 'tool_salasatipicas', $validcount),
            \core\output\notification::NOTIFY_WARNING);
        $confirmurl = new moodle_url('/admin/tool/salasatipicas/dependency.php');
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $confirmurl->out(false), 'class' => 'mt-3']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'confirmimport']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'token', 'value' => $importtoken]);
        echo html_writer::tag('button', get_string('dep_createvalidrows', 'tool_salasatipicas', $validcount), [
            'type' => 'submit', 'class' => 'btn btn-success salasdep-confirm-create',
        ]);
        echo html_writer::end_tag('form');
    } else if ($validcount > 0) {
        $msg = $creationenabled
            ? get_string('dep_workervalidationonlynotice', 'tool_salasatipicas')
            : get_string('dep_validationonlynotice', 'tool_salasatipicas');
        echo $OUTPUT->notification($msg, \core\output\notification::NOTIFY_WARNING);
    }
    echo html_writer::end_div();
}

$recent = $DB->get_records('tool_salasat_dep_hist', null, 'timecreated DESC', '*', 0, 8);
if ($recent) {
    echo html_writer::tag('h3', get_string('dep_recentdependencies', 'tool_salasatipicas'), ['class' => 'mt-4']);
    $table = new html_table();
    $table->head = [get_string('dep_processnumber', 'tool_salasatipicas'), get_string('dep_subject', 'tool_salasatipicas'),
        get_string('dep_teacher', 'tool_salasatipicas'), get_string('dep_status', 'tool_salasatipicas')];
    foreach ($recent as $record) {
        $table->data[] = [s($record->processnumber), s($record->subject), s($record->teachername), manager::status_label($record->status)];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
