<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_salasatipicas\form\rea_form;
use tool_salasatipicas\local\rea_manager as manager;

require_login();
if (!is_siteadmin()) {
    throw new moodle_exception('rea_siteadminonly', 'tool_salasatipicas');
}
admin_externalpage_setup('tool_salasatipicas');
require_capability('tool/salasatipicas:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/admin/tool/salasatipicas/rea.php'));
$PAGE->set_title(get_string('rea_manage', 'tool_salasatipicas'));
$PAGE->set_heading(get_string('rea_manage', 'tool_salasatipicas'));
$PAGE->requires->css(new moodle_url('/admin/tool/salasatipicas/styles.css'));
$PAGE->requires->js_init_code(<<<'JS'
(function() {
    var key = 'tool_salasatipicas_subject_scroll';
    try {
        var saved = window.sessionStorage.getItem(key);
        if (saved !== null) {
            window.sessionStorage.removeItem(key);
            window.addEventListener('load', function() {
                var y = parseInt(saved, 10);
                if (!Number.isNaN(y)) {
                    window.scrollTo(0, y);
                }
            }, {once: true});
        }
        document.addEventListener('click', function(event) {
            var button = event.target.closest('button, input[type=\"submit\"]');
            if (!button) {
                return;
            }
            var name = button.getAttribute('name') || '';
            if (name === 'add_subject_fields' || name.indexOf('delete_subject') === 0) {
                window.sessionStorage.setItem(key, String(window.scrollY));
            }
        }, true);
    } catch (e) {
        // sessionStorage is only a UX enhancement; form submission continues normally if unavailable.
    }
})();
JS);

$action = optional_param('action', '', PARAM_ALPHA);
$queueerror = optional_param('queueerror', '', PARAM_TEXT);
$requestedpreviewtoken = optional_param('previewtoken', '', PARAM_ALPHANUM);
$creationenabled = manager::creation_enabled();
$workerhealth = manager::get_worker_health();
$creationavailable = $creationenabled && $workerhealth['available'];

if ($action === 'confirmmanual') {
    require_sesskey();
    if (!$creationenabled) {
        redirect($PAGE->url, get_string('rea_creationdisabled', 'tool_salasatipicas'), null, \core\output\notification::NOTIFY_WARNING);
    }
    $token = required_param('token', PARAM_ALPHANUM);
    $payload = $SESSION->tool_salasatipicas_rea_manual[$token] ?? null;
    if (!$payload || ($payload['time'] ?? 0) < time() - 7200) {
        unset($SESSION->tool_salasatipicas_rea_manual[$token]);
        redirect($PAGE->url, get_string('rea_manualsessionexpired', 'tool_salasatipicas'), null, \core\output\notification::NOTIFY_ERROR);
    }
    $prepared = manager::prepare_definition($payload['raw']);
    if (!$prepared['valid']) {
        unset($SESSION->tool_salasatipicas_rea_manual[$token]);
        redirect($PAGE->url, implode('<br>', array_map('s', $prepared['errors'])), null, \core\output\notification::NOTIFY_ERROR);
    }
    unset($SESSION->tool_salasatipicas_rea_manual[$token]);
    $historyid = 0;
    try {
        $historyid = manager::create_request($prepared['definition'], 'manual');
        manager::launch_worker($historyid);
        redirect(new moodle_url('/admin/tool/salasatipicas/rea_history.php', ['queued' => $historyid]));
    } catch (Throwable $e) {
        if ($historyid > 0 && $DB->record_exists('tool_atyp_rea_hist', ['id' => $historyid])) {
            $DB->update_record('tool_atyp_rea_hist', (object)[
                'id' => $historyid, 'status' => manager::STATUS_ERROR, 'message' => $e->getMessage(), 'timemodified' => time(),
            ]);
        }
        redirect(new moodle_url('/admin/tool/salasatipicas/rea.php', ['queueerror' => clean_param($e->getMessage(), PARAM_TEXT)]));
    }
}

$categories = manager::get_category_options();
$form = new rea_form($PAGE->url, ['categories' => $categories, 'creationenabled' => $creationenabled]);
$preview = null;
$previewtoken = null;

// Reviews use POST/Redirect/GET so the browser lands directly on the confirmation block
// instead of returning to the top of a long form.
if ($requestedpreviewtoken !== '') {
    $payload = $SESSION->tool_salasatipicas_rea_manual[$requestedpreviewtoken] ?? null;
    if (!$payload || ($payload['time'] ?? 0) < time() - 7200) {
        unset($SESSION->tool_salasatipicas_rea_manual[$requestedpreviewtoken]);
        redirect($PAGE->url, get_string('rea_manualsessionexpired', 'tool_salasatipicas'), null, \core\output\notification::NOTIFY_ERROR);
    }
    $prepared = manager::prepare_definition($payload['raw']);
    if (!$prepared['valid']) {
        unset($SESSION->tool_salasatipicas_rea_manual[$requestedpreviewtoken]);
        redirect($PAGE->url, implode('<br>', array_map('s', $prepared['errors'])), null, \core\output\notification::NOTIFY_ERROR);
    }
    $preview = $prepared['definition'];
    $previewtoken = $requestedpreviewtoken;
}

if ($data = $form->get_data()) {
    $raw = [
        'studentdisplayname' => trim((string)$data->studentdisplayname),
        'courseprogram' => trim((string)$data->courseprogram),
        'processnumber' => trim((string)$data->processnumber),
        'courseabbr' => trim((string)$data->courseabbr),
        'shift' => trim((string)$data->shift),
        'semester' => (int)$data->semester,
        'classgroup' => trim((string)$data->classgroup),
        'phone' => trim((string)($data->phone ?? '')),
        'contactemail' => trim((string)$data->contactemail),
        'licensestart' => (int)$data->licensestart,
        'licenseend' => (int)$data->licenseend,
        'period' => trim((string)$data->period),
        'moodlestudentuserid' => (int)$data->moodlestudentuserid,
        'coordinationuserids' => array_values(array_filter(array_map('intval', (array)($data->coordinationuserids ?? [])))),
        'subjectname' => array_values((array)($data->subjectname ?? [])),
        'subjectteacheruserids' => array_values((array)($data->subjectteacheruserids ?? [])),
        'categoryconfigid' => (int)$data->categoryconfigid,
    ];
    $prepared = manager::prepare_definition($raw);
    if (!$prepared['valid']) {
        redirect($PAGE->url, implode('<br>', array_map('s', $prepared['errors'])), null, \core\output\notification::NOTIFY_ERROR);
    }
    $previewtoken = random_string(24);
    if (!isset($SESSION->tool_salasatipicas_rea_manual)) {
        $SESSION->tool_salasatipicas_rea_manual = [];
    }
    $SESSION->tool_salasatipicas_rea_manual[$previewtoken] = ['raw' => $raw, 'time' => time()];
    $reviewurl = new moodle_url('/admin/tool/salasatipicas/rea.php', ['previewtoken' => $previewtoken]);
    $reviewurl->set_anchor('salasat-rea-confirmation');
    redirect($reviewurl);
}

if (!empty($SESSION->tool_salasatipicas_rea_manual) && is_array($SESSION->tool_salasatipicas_rea_manual)) {
    foreach ($SESSION->tool_salasatipicas_rea_manual as $token => $payload) {
        if (($payload['time'] ?? 0) < time() - 7200) {
            unset($SESSION->tool_salasatipicas_rea_manual[$token]);
        }
    }
}

echo $OUTPUT->header();

echo html_writer::start_div('salasat-rea-toolbar');
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/index.php'), '← ' . get_string('backtocentral', 'tool_salasatipicas'), ['class' => 'btn btn-secondary']);
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/rea_configuration.php'), get_string('rea_settingsbutton', 'tool_salasatipicas'), ['class' => 'btn btn-secondary']);
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/rea_history.php'), get_string('rea_history', 'tool_salasatipicas'), ['class' => 'btn btn-secondary']);
echo html_writer::end_div();

echo html_writer::div(get_string('rea_intro', 'tool_salasatipicas'), 'salasat-rea-intro');

if ($queueerror !== '') {
    echo $OUTPUT->notification(get_string('rea_creationerror', 'tool_salasatipicas', s($queueerror)), \core\output\notification::NOTIFY_ERROR);
}
if ($creationenabled && $workerhealth['available']) {
    $a = (object)['php' => s($workerhealth['php']), 'launcher' => s($workerhealth['launcher'])];
    echo $OUTPUT->notification(get_string('rea_workerhealthycli', 'tool_salasatipicas', $a), \core\output\notification::NOTIFY_SUCCESS);
} else if ($creationenabled) {
    echo $OUTPUT->notification(get_string('rea_workerunhealthybanner', 'tool_salasatipicas', s($workerhealth['reason'])), \core\output\notification::NOTIFY_ERROR);
} else {
    echo $OUTPUT->notification(get_string('rea_creationdisabled', 'tool_salasatipicas'), \core\output\notification::NOTIFY_WARNING);
}
if (!$categories) {
    echo $OUTPUT->notification(get_string('rea_nocategoriesconfiguredlong', 'tool_salasatipicas'), \core\output\notification::NOTIFY_WARNING);
}

$currentpattern = manager::get_config_value('fullnamepattern', manager::DEFAULT_FULLNAME_PATTERN);
echo html_writer::div(html_writer::tag('strong', get_string('rea_currentnamepattern', 'tool_salasatipicas')) . '<br>' . s($currentpattern), 'salasat-rea-pattern');

echo html_writer::start_div('salasat-rea-card salasat-rea-maincard');
$form->display();
echo html_writer::end_div();

if ($preview) {
    echo html_writer::start_div('salasat-rea-preview mt-4', ['id' => 'salasat-rea-confirmation']);
    echo html_writer::tag('h3', get_string('rea_confirmmanualtitle', 'tool_salasatipicas'));
    $category = $DB->get_record('tool_atyp_rea_cat', ['id' => $preview['categoryconfigid']]);
    $subjectlines = [];
    foreach ($preview['subjects'] as $subject) {
        $teachers = array_map(fn($t) => $t['fullname'] . ' (' . $t['username'] . ')', $subject['teachers']);
        $subjectlines[] = $subject['name'] . ' — ' . implode(' / ', $teachers);
    }
    $table = new html_table();
    $table->head = [get_string('rea_field', 'tool_salasatipicas'), get_string('rea_value', 'tool_salasatipicas')];
    $table->data = [
        [get_string('rea_studentdisplayname', 'tool_salasatipicas'), s($preview['studentdisplayname'])],
        [get_string('rea_courseprogram', 'tool_salasatipicas'), s($preview['courseprogram'])],
        [get_string('rea_processnumber', 'tool_salasatipicas'), s($preview['processnumber'])],
        [get_string('rea_courseabbr', 'tool_salasatipicas'), s($preview['courseabbr'])],
        [get_string('rea_shift', 'tool_salasatipicas'), s($preview['shift'])],
        [get_string('rea_semester', 'tool_salasatipicas'), s((string)$preview['semester'])],
        [get_string('rea_classgroup', 'tool_salasatipicas'), s($preview['classgroup'])],
        [get_string('rea_phone', 'tool_salasatipicas'), s($preview['phone'])],
        [get_string('rea_contactemail', 'tool_salasatipicas'), s($preview['contactemail'])],
        [get_string('rea_licenseperiod', 'tool_salasatipicas'), userdate($preview['licensestart'], '%d/%m/%Y') . ' a ' . userdate($preview['licenseend'], '%d/%m/%Y')],
        [get_string('rea_period', 'tool_salasatipicas'), s($preview['period'])],
        [get_string('rea_moodlestudent', 'tool_salasatipicas'), nl2br(s(manager::participants_summary($preview['participants'], 'student')))],
        [get_string('rea_coordination', 'tool_salasatipicas'), ($c = manager::participants_summary($preview['participants'], 'coordination')) !== '' ? nl2br(s($c)) : '-'],
        [get_string('rea_subjectsandteacherslabel', 'tool_salasatipicas'), nl2br(s(implode("\n", $subjectlines)))],
        [get_string('rea_destinationcategory', 'tool_salasatipicas'), $category ? s($category->name) . ' (ID ' . (int)$category->categoryid . ')' : '-'],
        [get_string('rea_templatecourse', 'tool_salasatipicas'), (int)$preview['templatecourseid']],
        [get_string('rea_generatedfullname', 'tool_salasatipicas'), s($preview['targetfullname'])],
        [get_string('rea_generatedshortname', 'tool_salasatipicas'), s($preview['targetshortname'])],
    ];
    echo html_writer::table($table);
    if ($creationavailable) {
        echo $OUTPUT->notification(get_string('rea_confirmmanualwarning', 'tool_salasatipicas'), \core\output\notification::NOTIFY_WARNING);
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false), 'class' => 'mt-3']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'confirmmanual']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'token', 'value' => $previewtoken]);
        echo html_writer::tag('button', get_string('rea_confirmcreation', 'tool_salasatipicas'), ['type' => 'submit', 'class' => 'btn btn-success']);
        echo html_writer::link($PAGE->url, get_string('cancel'), ['class' => 'btn btn-secondary ml-2']);
        echo html_writer::end_tag('form');
    }
    echo html_writer::end_div();
}

$recent = $DB->get_records('tool_atyp_rea_hist', null, 'timecreated DESC', '*', 0, 8);
if ($recent) {
    echo html_writer::tag('h3', get_string('rea_recentreas', 'tool_salasatipicas'), ['class' => 'mt-4']);
    $table = new html_table();
    $table->head = [get_string('rea_studentdisplayname', 'tool_salasatipicas'), get_string('rea_courseprogram', 'tool_salasatipicas'), get_string('rea_period', 'tool_salasatipicas'), get_string('rea_status', 'tool_salasatipicas')];
    foreach ($recent as $record) {
        $table->data[] = [s($record->studentdisplayname), s($record->courseprogram), s($record->period), manager::status_label($record->status)];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
