<?php
defined('MOODLE_INTERNAL') || die();
$capabilities = [
    'tool/salasatipicas:manage' => [
        'riskbitmask' => RISK_DATALOSS | RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
    ],
];
