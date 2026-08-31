<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_salasatipicas\local\dependency_manager as manager;

require_login();
if (!is_siteadmin()) {
    throw new moodle_exception('dep_siteadminonly', 'tool_salasatipicas');
}
admin_externalpage_setup('tool_salasatipicas');
require_capability('tool/salasatipicas:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/admin/tool/salasatipicas/dependency_history.php'));
$PAGE->set_title(get_string('dep_history', 'tool_salasatipicas'));
$PAGE->set_heading(get_string('dep_history', 'tool_salasatipicas'));
$PAGE->requires->css(new moodle_url('/admin/tool/salasatipicas/styles.css'));

// Refresh only lightweight status/enrolment information; never run backup/restore here.
manager::refresh_history_statuses();
$queuedid = optional_param('queued', 0, PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;

echo $OUTPUT->header();

if ($queuedid > 0) {
    $queuedhistory = $DB->get_record('tool_atyp_dep_hist', ['id' => $queuedid], 'id,targetfullname,status');
    if ($queuedhistory) {
        echo $OUTPUT->notification(
            get_string('dep_dependencyworkerstarted', 'tool_salasatipicas', $queuedhistory->targetfullname),
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}
echo html_writer::start_div('salasat-dep-toolbar');
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/index.php'), '← ' . get_string('backtocentral', 'tool_salasatipicas'), ['class' => 'btn btn-secondary']);
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/dependency.php'),
    '← ' . get_string('dep_backtomanage', 'tool_salasatipicas'), ['class' => 'btn btn-secondary']);
echo html_writer::end_div();

$totalrecords = $DB->count_records('tool_atyp_dep_hist');
$records = $DB->get_records('tool_atyp_dep_hist', null, 'timecreated DESC', '*', $page * $perpage, $perpage);
$participantmap = manager::get_participants_for_histories(array_keys($records));
$hasactiveworker = false;
foreach ($records as $record) {
    if (in_array((string)$record->status, [manager::STATUS_QUEUED, manager::STATUS_PROCESSING], true)) {
        $hasactiveworker = true;
        break;
    }
}
if ($hasactiveworker) {
    // This is only a lightweight page refresh. The worker updates DB state independently.
    $PAGE->requires->js_init_code('window.setTimeout(function(){ window.location.reload(); }, 5000);');
}
if (!$records) {
    echo $OUTPUT->notification(get_string('dep_nohistory', 'tool_salasatipicas'),
        \core\output\notification::NOTIFY_INFO);
} else {
    $table = new html_table();
    $table->head = [
        get_string('date'),
        get_string('dep_processnumber', 'tool_salasatipicas'),
        get_string('dep_subject', 'tool_salasatipicas'),
        get_string('dep_students', 'tool_salasatipicas'),
        get_string('dep_staffandcoordination', 'tool_salasatipicas'),
        get_string('dep_source', 'tool_salasatipicas'),
        get_string('dep_status', 'tool_salasatipicas'),
        get_string('dep_details', 'tool_salasatipicas'),
    ];
    foreach ($records as $record) {
        $detail = s((string)$record->message);
        if ((int)$record->retrycount > 0) {
            $detail .= '<br><small>' . get_string('dep_retrycountlabel', 'tool_salasatipicas', (int)$record->retrycount) . '</small>';
        }
        if ($record->targetcourseid && $record->status === manager::STATUS_READY) {
            $courseurl = new moodle_url('/course/view.php', ['id' => $record->targetcourseid]);
            $detail .= '<br>' . html_writer::link($courseurl, get_string('dep_opencourse', 'tool_salasatipicas'));
        }
        if ((string)$record->status === manager::STATUS_ERROR) {
            $detail .= html_writer::start_tag('form', [
                'method' => 'post',
                'action' => (new moodle_url('/admin/tool/salasatipicas/dependency_retry.php'))->out(false),
                'class' => 'mt-2',
            ]);
            $detail .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $detail .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'historyid', 'value' => (int)$record->id]);
            $detail .= html_writer::tag('button', get_string('dep_retrycreation', 'tool_salasatipicas'), [
                'type' => 'submit', 'class' => 'btn btn-sm btn-outline-primary',
            ]);
            $detail .= html_writer::end_tag('form');
        }
        $statusclass = 'salasat-dep-status salasat-dep-status-' . preg_replace('/[^a-z0-9_-]/', '', (string)$record->status);
        $statushtml = html_writer::span(manager::status_label($record->status), $statusclass);
        $participants = $participantmap[(int)$record->id] ?? manager::get_history_participants((int)$record->id, true);
        $studentsummary = manager::participants_summary($participants, 'student');
        $stafflines = [];
        $teachersummary = manager::participants_summary($participants, 'teacher');
        $coordsummary = manager::participants_summary($participants, 'coordination');
        if ($teachersummary !== '') {
            $stafflines[] = $teachersummary;
        }
        if ($coordsummary !== '') {
            $stafflines[] = get_string('dep_coordination', 'tool_salasatipicas') . ': ' . $coordsummary;
        }
        $table->data[] = [
            userdate($record->timecreated),
            s($record->processnumber),
            s($record->subject),
            $studentsummary !== '' ? nl2br(s($studentsummary)) : '-',
            $stafflines ? nl2br(s(implode("
", $stafflines))) : '-',
            s(strtoupper($record->source)),
            $statushtml,
            $detail,
        ];
    }
    echo html_writer::table($table);
    echo $OUTPUT->paging_bar($totalrecords, $page, $perpage, $PAGE->url);
}

echo $OUTPUT->footer();
