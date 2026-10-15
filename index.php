<?php
// This file is part of Moodle - http://moodle.org/
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
if (!is_siteadmin()) {
    throw new moodle_exception('siteadminonly', 'tool_salasatipicas');
}
admin_externalpage_setup('tool_salasatipicas');
require_capability('tool/salasatipicas:manage', context_system::instance());
$PAGE->set_url(new moodle_url('/admin/tool/salasatipicas/index.php'));
$PAGE->set_title(get_string('manage', 'tool_salasatipicas'));
$PAGE->set_heading(get_string('manage', 'tool_salasatipicas'));
$PAGE->requires->css(new moodle_url('/admin/tool/salasatipicas/styles.css'));

$depcount = $DB->count_records('tool_salasat_dep_hist');
$reacount = $DB->count_records('tool_salasat_rea_hist');

echo $OUTPUT->header();
echo html_writer::div(get_string('landingintro', 'tool_salasatipicas'), 'salasat-intro');
echo html_writer::start_div('salasat-topactions');
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/history.php'), get_string('globalhistory', 'tool_salasatipicas'), ['class'=>'btn btn-secondary']);
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/configuration.php'), get_string('globalsettings', 'tool_salasatipicas'), ['class'=>'btn btn-secondary']);
echo html_writer::end_div();

echo html_writer::start_div('salasat-module-grid');

// Dependency card.
echo html_writer::start_div('salasat-module-card');
echo html_writer::tag('h2', get_string('dependencycardtitle', 'tool_salasatipicas'));
echo html_writer::tag('p', get_string('dependencycardtext', 'tool_salasatipicas'));
echo html_writer::div($depcount . ' registro(s) no histórico desta central.', 'salasat-count');
echo html_writer::start_div('salasat-card-actions');
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/dependency.php'), get_string('openmodule', 'tool_salasatipicas'), ['class'=>'btn btn-primary']);
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/dependency_configuration.php'), get_string('dep_settingsbutton', 'tool_salasatipicas'), ['class'=>'btn btn-secondary']);
echo html_writer::end_div();
echo html_writer::end_div();

// REA card.
echo html_writer::start_div('salasat-module-card');
echo html_writer::tag('h2', get_string('reacardtitle', 'tool_salasatipicas'));
echo html_writer::tag('p', get_string('reacardtext', 'tool_salasatipicas'));
echo html_writer::div($reacount . ' registro(s) no histórico desta central.', 'salasat-count');
echo html_writer::start_div('salasat-card-actions');
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/rea.php'), get_string('openmodule', 'tool_salasatipicas'), ['class'=>'btn btn-primary']);
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/rea_configuration.php'), get_string('rea_settingsbutton', 'tool_salasatipicas'), ['class'=>'btn btn-secondary']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div();
echo $OUTPUT->footer();
