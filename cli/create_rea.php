<?php
// Independent CLI worker for REA course creation.

define('CLI_SCRIPT', true);
require(dirname(__DIR__, 4) . '/config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params([
    'historyid' => null,
    'help' => false,
], ['h' => 'help']);

if (!empty($options['help'])) {
    echo "Create one REA course in an independent CLI worker.\n\n";
    echo "--historyid=ID   History row to process.\n";
    echo "-h, --help       Show this help.\n";
    exit(0);
}

$historyid = (int)($options['historyid'] ?? 0);
if ($historyid <= 0) {
    cli_error('Missing or invalid --historyid.');
}

try {
    \tool_salasatipicas\local\rea_manager::process_history_worker($historyid);
    mtrace('tool_salasatipicas: history ' . $historyid . ' finished.');
    exit(0);
} catch (Throwable $e) {
    mtrace('tool_salasatipicas: history ' . $historyid . ' failed: ' . $e->getMessage());
    exit(1);
}
