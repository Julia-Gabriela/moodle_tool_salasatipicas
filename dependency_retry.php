<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_salasatipicas\local\dependency_manager as manager;

require_login();
if (!is_siteadmin()) {
    throw new moodle_exception('dep_siteadminonly', 'tool_salasatipicas');
}
require_capability('tool/salasatipicas:manage', context_system::instance());
require_sesskey();

$historyid = required_param('historyid', PARAM_INT);
$history = $DB->get_record('tool_atyp_dep_hist', ['id' => $historyid], '*', MUST_EXIST);
if ((string)$history->status !== manager::STATUS_ERROR) {
    redirect(new moodle_url('/admin/tool/salasatipicas/dependency_history.php'),
        get_string('dep_retryonlyerrors', 'tool_salasatipicas'), null, \core\output\notification::NOTIFY_WARNING);
}

$participants = manager::get_history_participants((int)$history->id, true);
$teacherids = [];
$studentids = [];
$coordinationids = [];
$teacherroleid = 0;
$studentroleid = 0;
$coordinationroleid = 0;
foreach ($participants as $participant) {
    switch ((string)$participant->participanttype) {
        case 'teacher':
            $teacherids[] = (int)$participant->userid;
            $teacherroleid = $teacherroleid ?: (int)$participant->roleid;
            break;
        case 'coordination':
            $coordinationids[] = (int)$participant->userid;
            $coordinationroleid = $coordinationroleid ?: (int)$participant->roleid;
            break;
        case 'student':
        default:
            $studentids[] = (int)$participant->userid;
            $studentroleid = $studentroleid ?: (int)$participant->roleid;
            break;
    }
}
$prepared = manager::prepare_definition([
    'processnumber' => $history->processnumber,
    'subject' => $history->subject,
    'period' => $history->period,
    'teacheruserids' => $teacherids,
    'coordinationuserids' => $coordinationids,
    'studentuserids' => $studentids,
    'teacherroleid' => $teacherroleid,
    'coordinationroleid' => $coordinationroleid,
    'studentroleid' => $studentroleid,
    'categoryconfigid' => $history->categoryconfigid,
]);
if (!$prepared['valid']) {
    redirect(new moodle_url('/admin/tool/salasatipicas/dependency_history.php'),
        implode(' | ', $prepared['errors']), null, \core\output\notification::NOTIFY_ERROR);
}

$newid = 0;
try {
    $newid = manager::create_dependency_request(
        $prepared['definition'],
        in_array($history->source, ['manual', 'csv', 'xlsx'], true) ? $history->source : 'manual',
        ((int)$history->retrycount) + 1
    );
    manager::launch_worker($newid);
    redirect(new moodle_url('/admin/tool/salasatipicas/dependency_history.php', ['queued' => $newid]));
} catch (Throwable $e) {
    if ($newid > 0) {
        manager::mark_error($newid, $e->getMessage());
    }
    redirect(new moodle_url('/admin/tool/salasatipicas/dependency_history.php'), $e->getMessage(), null,
        \core\output\notification::NOTIFY_ERROR);
}
