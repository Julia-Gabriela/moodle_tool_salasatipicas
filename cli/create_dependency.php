<?php
// This file is part of Moodle - http://moodle.org/

/**
 * Independent CLI worker for dependency course creation.
 *
 * @package tool_salasatipicas
 */

define('CLI_SCRIPT', true);

require(dirname(__DIR__, 4) . '/config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params([
    'historyid' => null,
    'help' => false,
], [
    'h' => 'help',
]);

if (!empty($options['help'])) {
    $help = "Create one dependency course in an independent CLI worker.\n\n" .
        "Options:\n" .
        "--historyid=ID   Plugin history row to process.\n" .
        "-h, --help       Show this help.\n";
    echo $help;
    exit(0);
}

$historyid = (int)($options['historyid'] ?? 0);
if ($historyid <= 0) {
    cli_error('Missing or invalid --historyid.');
}

try {
    \tool_salasatipicas\local\dependency_manager::process_history_worker($historyid);
    mtrace('tool_salasatipicas: history ' . $historyid . ' finished.');
    exit(0);
} catch (Throwable $e) {
    mtrace('tool_salasatipicas: history ' . $historyid . ' failed: ' . $e->getMessage());
    exit(1);
}
