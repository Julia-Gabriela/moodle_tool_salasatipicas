<?php
// This file is part of Moodle - http://moodle.org/
defined('MOODLE_INTERNAL') || die();

if (is_siteadmin()) {
    $ADMIN->add('courses', new admin_externalpage(
        'tool_salasatipicas',
        get_string('manage', 'tool_salasatipicas'),
        new moodle_url('/admin/tool/salasatipicas/index.php'),
        'tool/salasatipicas:manage'
    ));
}
