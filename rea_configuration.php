<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_salasatipicas\form\rea_config_form as config_form;
use tool_salasatipicas\local\rea_manager as manager;

require_login();
if (!is_siteadmin()) { throw new moodle_exception('rea_siteadminonly', 'tool_salasatipicas'); }
admin_externalpage_setup('tool_salasatipicas');
require_capability('tool/salasatipicas:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/admin/tool/salasatipicas/rea_configuration.php'));
$PAGE->set_title(get_string('rea_settings', 'tool_salasatipicas'));
$PAGE->set_heading(get_string('rea_settings', 'tool_salasatipicas'));
$PAGE->requires->css(new moodle_url('/admin/tool/salasatipicas/styles.css'));

$roles = manager::get_course_role_options();
$form = new config_form($PAGE->url, ['roles' => $roles]);
$studentdefault = manager::role_id_by_shortname('student');
$teacherdefault = manager::role_id_by_shortname('editingteacher');
$current = (object)[
    'creationenabled' => (int)manager::get_config_value('creationenabled', 1),
    'fullnamepattern' => manager::get_config_value('fullnamepattern', manager::DEFAULT_FULLNAME_PATTERN),
    'shortnamepattern' => manager::get_config_value('shortnamepattern', manager::DEFAULT_SHORTNAME_PATTERN),
    'defaulttemplateid' => (int)manager::get_config_value('defaulttemplateid', 0),
    'studentroleid' => (int)manager::get_config_value('studentroleid', $studentdefault),
    'teacherroleid' => (int)manager::get_config_value('teacherroleid', $teacherdefault),
    'coordinationroleid' => (int)manager::get_config_value('coordinationroleid', $teacherdefault),
    'coursevisible' => (int)manager::get_config_value('coursevisible', 0),
    'infomarker' => manager::get_config_value('infomarker', 'Aluno (a):'),
    'materialmarker' => manager::get_config_value('materialmarker', 'Material da Disciplina'),
    'assignmentmarker' => manager::get_config_value('assignmentmarker', 'Entrega da Atividade REA - Disciplina'),
    'studentsectionmarker' => manager::get_config_value('studentsectionmarker', 'Aluno: xxx'),
];
$form->set_data($current);
if ($data = $form->get_data()) {
    foreach (['creationenabled','fullnamepattern','shortnamepattern','defaulttemplateid','studentroleid','teacherroleid','coordinationroleid','coursevisible','infomarker','materialmarker','assignmentmarker','studentsectionmarker'] as $field) {
        set_config('rea_' . $field, is_string($data->{$field}) ? trim($data->{$field}) : $data->{$field}, 'tool_salasatipicas');
    }
    redirect($PAGE->url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo html_writer::start_div('salasat-rea-toolbar');
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/index.php'), '← ' . get_string('backtocentral', 'tool_salasatipicas'), ['class' => 'btn btn-secondary']);
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/rea.php'), '← ' . get_string('rea_backtomanage', 'tool_salasatipicas'), ['class' => 'btn btn-secondary']);
echo html_writer::end_div();
$health = manager::get_worker_health();
echo $OUTPUT->notification($health['available'] ? get_string('rea_workerhealthycli', 'tool_salasatipicas', (object)['php'=>$health['php'],'launcher'=>$health['launcher']]) : get_string('rea_workerunhealthybanner', 'tool_salasatipicas', $health['reason']), $health['available'] ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR);
echo html_writer::start_div('salasat-rea-card'); $form->display(); echo html_writer::end_div();

echo html_writer::start_div('salasat-rea-card mt-4');
echo html_writer::start_div('salasat-rea-toolbar');
echo html_writer::tag('h3', get_string('rea_configuredcategories', 'tool_salasatipicas'));
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/rea_category.php'), get_string('rea_addcategoryconfig', 'tool_salasatipicas'), ['class' => 'btn btn-primary']);
echo html_writer::end_div();
$categories = manager::get_categories(false);
if (!$categories) {
    echo $OUTPUT->notification(get_string('rea_nocategoriesconfigured', 'tool_salasatipicas'), \core\output\notification::NOTIFY_WARNING);
} else {
    $table = new html_table();
    $table->head = [get_string('rea_categoryname', 'tool_salasatipicas'), get_string('rea_moodlecategoryid', 'tool_salasatipicas'), get_string('rea_categorytemplateid', 'tool_salasatipicas'), get_string('rea_active', 'tool_salasatipicas'), get_string('actions')];
    foreach ($categories as $category) {
        $table->data[] = [s($category->name), (int)$category->categoryid, $category->templatecourseid ? (int)$category->templatecourseid : get_string('rea_useglobaltemplate', 'tool_salasatipicas'), $category->active ? get_string('yes') : get_string('no'), html_writer::link(new moodle_url('/admin/tool/salasatipicas/rea_category.php',['id'=>$category->id]), get_string('edit'))];
    }
    echo html_writer::table($table);
}
echo html_writer::end_div();
echo $OUTPUT->footer();
