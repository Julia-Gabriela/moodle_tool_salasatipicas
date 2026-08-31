<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Legacy dispatch endpoint retained for bookmarks/caches from 0.3.x.
 * Production candidate never runs backup/restore in a web request; it only
 * launches the detached CLI worker and redirects to History.
 *
 * @package tool_salasatipicas
 */

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
$DB->get_record('tool_atyp_dep_hist', ['id' => $historyid], '*', MUST_EXIST);

try {
    manager::assert_worker_available();
    manager::launch_worker($historyid);
} catch (Throwable $e) {
    manager::mark_error($historyid, $e->getMessage());
}
redirect(new moodle_url('/admin/tool/salasatipicas/dependency_history.php', ['queued' => $historyid]));
