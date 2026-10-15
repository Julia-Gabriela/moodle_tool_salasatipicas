<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
use tool_salasatipicas\local\rea_manager as manager;

require_login();
if (!is_siteadmin()) { throw new moodle_exception('rea_siteadminonly', 'tool_salasatipicas'); }
admin_externalpage_setup('tool_salasatipicas');
require_capability('tool/salasatipicas:manage', context_system::instance());
$PAGE->set_url(new moodle_url('/admin/tool/salasatipicas/rea_history.php'));
$PAGE->set_title(get_string('rea_history', 'tool_salasatipicas'));
$PAGE->set_heading(get_string('rea_history', 'tool_salasatipicas'));
$PAGE->requires->css(new moodle_url('/admin/tool/salasatipicas/styles.css'));
$queuedid = optional_param('queued', 0, PARAM_INT);
$page = optional_param('page', 0, PARAM_INT); $perpage = 50;

echo $OUTPUT->header();
if ($queuedid) { echo $OUTPUT->notification(get_string('rea_reaworkerstarted', 'tool_salasatipicas'), \core\output\notification::NOTIFY_SUCCESS); }
echo html_writer::start_div('salasrea-toolbar');
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/index.php'), '← ' . get_string('backtocentral', 'tool_salasatipicas'), ['class' => 'btn btn-secondary']);
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/rea.php'), '← ' . get_string('rea_backtomanage', 'tool_salasatipicas'), ['class'=>'btn btn-secondary']);
echo html_writer::end_div();
$total = $DB->count_records('tool_salasat_rea_hist');
$records = $DB->get_records('tool_salasat_rea_hist', null, 'timecreated DESC', '*', $page*$perpage, $perpage);
$participantmap = manager::get_participants_for_histories(array_keys($records));
$active = false;
foreach ($records as $record) { if (in_array($record->status, [manager::STATUS_QUEUED, manager::STATUS_PROCESSING], true)) { $active = true; break; } }
if ($active) { $PAGE->requires->js_init_code('window.setTimeout(function(){ window.location.reload(); }, 5000);'); }
if (!$records) {
    echo $OUTPUT->notification(get_string('rea_nohistory', 'tool_salasatipicas'), \core\output\notification::NOTIFY_INFO);
} else {
    $table = new html_table();
    $table->head = [get_string('date'), get_string('rea_studentdisplayname', 'tool_salasatipicas'), get_string('rea_courseprogram', 'tool_salasatipicas'), get_string('rea_period', 'tool_salasatipicas'), get_string('rea_subjects', 'tool_salasatipicas'), get_string('rea_participantsheader', 'tool_salasatipicas'), get_string('rea_status', 'tool_salasatipicas'), get_string('rea_details', 'tool_salasatipicas')];
    foreach ($records as $record) {
        $subjects = json_decode((string)$record->subjectsjson, true) ?: [];
        $subjectnames = array_map(fn($s) => (string)($s['name'] ?? ''), $subjects);
        $participants = $participantmap[(int)$record->id] ?? [];
        $details = s((string)$record->message);
        if ($record->targetcourseid && $record->status === manager::STATUS_READY) {
            $details .= '<br>' . html_writer::link(new moodle_url('/course/view.php',['id'=>$record->targetcourseid]), get_string('rea_opencourse', 'tool_salasatipicas'));
        }
        $table->data[] = [userdate($record->timecreated), s($record->studentdisplayname), s($record->courseprogram), s($record->period), nl2br(s(implode("\n", $subjectnames))), nl2br(s(manager::participants_summary($participants))), html_writer::span(manager::status_label($record->status), 'salasrea-status salasrea-status-' . $record->status), $details];
    }
    echo html_writer::table($table);
    echo $OUTPUT->paging_bar($total, $page, $perpage, $PAGE->url);
}
echo $OUTPUT->footer();
