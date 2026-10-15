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

$token = required_param('token', PARAM_ALPHANUM);
$action = optional_param('action', '', PARAM_ALPHA);

/**
 * Output a JSON response and stop.
 *
 * @param array $data
 */
function tool_salasatipicas_json(array $data): void {
    @header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Poll one previously started row. This endpoint is intentionally lightweight.
if ($action === 'status') {
    require_sesskey();
    $historyid = required_param('historyid', PARAM_INT);
    $history = $DB->get_record('tool_salasat_dep_hist', ['id' => $historyid], '*', MUST_EXIST);

    $complete = in_array((string)$history->status, [manager::STATUS_READY, manager::STATUS_ERROR], true);
    tool_salasatipicas_json([
        'ok' => true,
        'complete' => $complete,
        'success' => ((string)$history->status === manager::STATUS_READY),
        'status' => (string)$history->status,
        'message' => (string)$history->message,
        'courseid' => (int)$history->targetcourseid,
        'historyid' => (int)$history->id,
    ]);
}

// Start one independent CLI automation per request. The worker is detached from PHP-FPM.
if ($action === 'processrow') {
    require_sesskey();
    $index = required_param('index', PARAM_INT);

    if (!manager::creation_enabled()) {
        tool_salasatipicas_json([
            'ok' => false,
            'fatal' => true,
            'message' => get_string('dep_creationdisabled', 'tool_salasatipicas'),
        ]);
    }

    if (empty($SESSION->tool_salasatipicas_dep_import[$token])) {
        tool_salasatipicas_json([
            'ok' => false,
            'fatal' => true,
            'message' => get_string('dep_importsessionexpired', 'tool_salasatipicas'),
        ]);
    }

    $payload = $SESSION->tool_salasatipicas_dep_import[$token];
    if (($payload['time'] ?? 0) < time() - 7200) {
        tool_salasatipicas_json([
            'ok' => false,
            'fatal' => true,
            'message' => get_string('dep_importsessionexpired', 'tool_salasatipicas'),
        ]);
    }

    $rows = array_values($payload['rows'] ?? []);
    $total = count($rows);
    if ($index < 0 || $index >= $total) {
        tool_salasatipicas_json([
            'ok' => false,
            'fatal' => true,
            'message' => get_string('dep_invalidimportindex', 'tool_salasatipicas'),
        ]);
    }

    $raw = $rows[$index];
    $result = [
        'index' => $index,
        'processnumber' => (string)($raw['processnumber'] ?? ''),
        'subject' => (string)($raw['subject'] ?? ''),
        'ok' => false,
        'queued' => false,
        'message' => '',
        'courseid' => 0,
    ];

    try {
        // Revalidate while the session is still available. No heavy operation happens here.
        $prepared = manager::prepare_definition($raw);
        if (!$prepared['valid']) {
            throw new moodle_exception('dep_importrowinvalidnow', 'tool_salasatipicas', '', null,
                implode(' | ', $prepared['errors']));
        }

        $historyid = manager::create_dependency_request($prepared['definition'], (string)$payload['source']);
        $history = $DB->get_record('tool_salasat_dep_hist', ['id' => $historyid], '*', MUST_EXIST);
        $result['ok'] = true;
        $result['queued'] = true;
        $result['courseid'] = 0;
        $result['historyid'] = $historyid;
        $result['processnumber'] = (string)$history->processnumber;
        $result['subject'] = (string)$history->subject;
        $result['message'] = get_string('dep_importrowworkerstarted', 'tool_salasatipicas', $history->targetfullname);

        $response = [
            'ok' => true,
            'fatal' => false,
            'complete' => false,
            'completed' => $index,
            'total' => $total,
            'result' => $result,
            'historyid' => $historyid,
            'wait' => true,
            'message' => $result['message'],
        ];

        // Production mode is detached CLI only. This launch is intentionally lightweight;
        // backup/restore runs only in the detached CLI process.
        manager::launch_worker($historyid);
    } catch (Throwable $e) {
        if (!empty($historyid)) {
            manager::mark_error((int)$historyid, $e->getMessage());
        }
        $result['ok'] = false;
        $result['message'] = $e->getMessage();
        $fatal = $e instanceof moodle_exception && in_array($e->errorcode, [
            'workerunavailable',
            'creationdisabled',
        ], true);
        tool_salasatipicas_json([
            'ok' => true,
            'fatal' => $fatal,
            'complete' => false,
            'completed' => $index + 1,
            'total' => $total,
            'result' => $result,
            'message' => $result['message'],
        ]);
    }

    tool_salasatipicas_json([
        'ok' => true,
        'fatal' => false,
        'complete' => false,
        'completed' => $index,
        'total' => $total,
        'result' => $result,
        'historyid' => $historyid ?? 0,
        'wait' => true,
        'message' => $result['message'],
    ]);
}

admin_externalpage_setup('tool_salasatipicas');
$PAGE->set_url(new moodle_url('/admin/tool/salasatipicas/dependency_import_process.php', ['token' => $token]));
$PAGE->set_title(get_string('dep_importprocesstitle', 'tool_salasatipicas'));
$PAGE->set_heading(get_string('dep_importprocesstitle', 'tool_salasatipicas'));
$PAGE->requires->css(new moodle_url('/admin/tool/salasatipicas/styles.css'));

if (empty($SESSION->tool_salasatipicas_dep_import[$token])) {
    redirect(new moodle_url('/admin/tool/salasatipicas/dependency.php'),
        get_string('dep_importsessionexpired', 'tool_salasatipicas'), null,
        \core\output\notification::NOTIFY_ERROR);
}
$payload = $SESSION->tool_salasatipicas_dep_import[$token];
if (($payload['time'] ?? 0) < time() - 7200) {
    redirect(new moodle_url('/admin/tool/salasatipicas/dependency.php'),
        get_string('dep_importsessionexpired', 'tool_salasatipicas'), null,
        \core\output\notification::NOTIFY_ERROR);
}

try {
    manager::assert_worker_available();
} catch (Throwable $e) {
    redirect(new moodle_url('/admin/tool/salasatipicas/dependency.php'), $e->getMessage(), null,
        \core\output\notification::NOTIFY_ERROR);
}

$total = count($payload['rows'] ?? []);

$PAGE->requires->js_call_amd('tool_salasatipicas/import_runner', 'init', [[
    'endpoint' => (new moodle_url('/admin/tool/salasatipicas/dependency_import_process.php'))->out(false),
    'token' => $token,
    'sesskey' => sesskey(),
    'total' => $total,
    'historyurl' => (new moodle_url('/admin/tool/salasatipicas/dependency_history.php'))->out(false),
    'manageurl' => (new moodle_url('/admin/tool/salasatipicas/dependency.php'))->out(false),
    'labels' => [
        'processing' => get_string('dep_importworkerprocessing', 'tool_salasatipicas'),
        'complete' => get_string('dep_importworkerfinishedtitle', 'tool_salasatipicas'),
        'fatal' => get_string('dep_importfatal', 'tool_salasatipicas'),
        'history' => get_string('dep_history', 'tool_salasatipicas'),
        'manage' => get_string('dep_backtomanage', 'tool_salasatipicas'),
    ],
]]);

echo $OUTPUT->header();

echo html_writer::start_div('salasdep-toolbar');
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/dependency.php'),
    '← ' . get_string('dep_backtomanage', 'tool_salasatipicas'), ['class' => 'btn btn-secondary']);
echo html_writer::end_div();

echo $OUTPUT->notification(get_string('dep_workerimportnotice', 'tool_salasatipicas'),
    \core\output\notification::NOTIFY_INFO);

echo html_writer::start_div('salasdep-preview salasdep-import-runner', ['id' => 'salasdep-import-runner']);
echo html_writer::tag('h3', get_string('dep_importprocesstitle', 'tool_salasatipicas'));
echo html_writer::tag('p', get_string('dep_importworkerprocessdescription', 'tool_salasatipicas', $total));

echo html_writer::start_div('progress salasdep-progress-wrap');
echo html_writer::div('', 'progress-bar progress-bar-striped progress-bar-animated', [
    'id' => 'salasdep-progress-bar',
    'role' => 'progressbar',
    'style' => 'width: 0%',
    'aria-valuemin' => '0',
    'aria-valuemax' => (string)$total,
    'aria-valuenow' => '0',
]);
echo html_writer::end_div();

echo html_writer::div(
    get_string('dep_importprogresscount', 'tool_salasatipicas', (object)['completed' => 0, 'total' => $total]),
    'salasdep-progress-label mt-2',
    ['id' => 'salasdep-progress-label']
);
echo html_writer::div(get_string('dep_importworkerprocessing', 'tool_salasatipicas'), 'alert alert-info mt-3', [
    'id' => 'salasdep-import-status',
]);
echo html_writer::tag('div', '', ['id' => 'salasdep-import-results', 'class' => 'mt-3']);
echo html_writer::tag('div', '', ['id' => 'salasdep-import-actions', 'class' => 'mt-3']);
echo html_writer::end_div();

echo $OUTPUT->footer();
