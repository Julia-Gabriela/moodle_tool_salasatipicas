<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_login();
if (!is_siteadmin()) { throw new moodle_exception('siteadminonly', 'tool_salasatipicas'); }
admin_externalpage_setup('tool_salasatipicas');
require_capability('tool/salasatipicas:manage', context_system::instance());
$PAGE->set_url(new moodle_url('/admin/tool/salasatipicas/configuration.php'));
$PAGE->set_title(get_string('globalsettings','tool_salasatipicas'));
$PAGE->set_heading(get_string('globalsettings','tool_salasatipicas'));
$PAGE->requires->css(new moodle_url('/admin/tool/salasatipicas/styles.css'));
echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/index.php'), '← ' . get_string('backtocentral','tool_salasatipicas'), ['class'=>'btn btn-secondary mb-3']);
echo $OUTPUT->notification(get_string('importedconfiginfo','tool_salasatipicas'), \core\output\notification::NOTIFY_INFO);
echo html_writer::start_div('salasat-module-grid');
echo html_writer::start_div('salasat-module-card');
echo html_writer::tag('h2', get_string('dependencycardtitle','tool_salasatipicas'));
echo html_writer::tag('p', 'Template, papéis, nomenclatura e categorias de Dependência.');
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/dependency_configuration.php'), get_string('dep_settings','tool_salasatipicas'), ['class'=>'btn btn-primary']);
echo html_writer::end_div();
echo html_writer::start_div('salasat-module-card');
echo html_writer::tag('h2', get_string('reacardtitle','tool_salasatipicas'));
echo html_writer::tag('p', 'Template, marcadores, papéis, nomenclatura e categorias de REA.');
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/rea_configuration.php'), get_string('rea_settings','tool_salasatipicas'), ['class'=>'btn btn-primary']);
echo html_writer::end_div();
echo html_writer::end_div();
echo $OUTPUT->footer();
