<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_salasatipicas\form\dependency_config_form as config_form;
use tool_salasatipicas\local\dependency_manager as manager;

require_login();
if (!is_siteadmin()) {
    throw new moodle_exception('dep_siteadminonly', 'tool_salasatipicas');
}
admin_externalpage_setup('tool_salasatipicas');
require_capability('tool/salasatipicas:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/admin/tool/salasatipicas/dependency_configuration.php'));
$PAGE->set_title(get_string('dep_settings', 'tool_salasatipicas'));
$PAGE->set_heading(get_string('dep_settings', 'tool_salasatipicas'));
$PAGE->requires->css(new moodle_url('/admin/tool/salasatipicas/styles.css'));

$roles = manager::get_course_role_options();
$form = new config_form($PAGE->url, ['roles' => $roles]);

$studentdefault = manager::role_id_by_shortname('student');
$teacherdefault = manager::role_id_by_shortname('editingteacher');
$coordinationdefault = (int)manager::get_config_value('coordinationroleid', $teacherdefault);
$current = (object)[
    'fullnamepattern' => manager::get_config_value('fullnamepattern', manager::DEFAULT_FULLNAME_PATTERN),
    'shortnamepattern' => manager::get_config_value('shortnamepattern', manager::DEFAULT_SHORTNAME_PATTERN),
    'defaulttemplateid' => (int)manager::get_config_value('defaulttemplateid', 0),
    'studentroleid' => (int)manager::get_config_value('studentroleid', $studentdefault),
    'teacherroleid' => (int)manager::get_config_value('teacherroleid', $teacherdefault),
    'coordinationroleid' => $coordinationdefault,
    'coursevisible' => (int)manager::get_config_value('coursevisible', 0),
    'creationenabled' => (int)manager::get_config_value('creationenabled', 1),
];
$form->set_data($current);

if ($data = $form->get_data()) {
    set_config('dep_fullnamepattern', trim($data->fullnamepattern), 'tool_salasatipicas');
    set_config('dep_shortnamepattern', trim($data->shortnamepattern), 'tool_salasatipicas');
    set_config('dep_defaulttemplateid', (int)$data->defaulttemplateid, 'tool_salasatipicas');
    set_config('dep_studentroleid', (int)$data->studentroleid, 'tool_salasatipicas');
    set_config('dep_teacherroleid', (int)$data->teacherroleid, 'tool_salasatipicas');
    set_config('dep_coordinationroleid', (int)$data->coordinationroleid, 'tool_salasatipicas');
    set_config('dep_coursevisible', (int)$data->coursevisible, 'tool_salasatipicas');
    set_config('dep_creationenabled', (int)$data->creationenabled, 'tool_salasatipicas');
    redirect($PAGE->url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo html_writer::start_div('salasat-dep-toolbar');
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/index.php'), '← ' . get_string('backtocentral', 'tool_salasatipicas'), ['class' => 'btn btn-secondary']);
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/dependency.php'),
    '← ' . get_string('dep_backtomanage', 'tool_salasatipicas'), ['class' => 'btn btn-secondary']);
echo html_writer::end_div();

$workerhealth = manager::get_worker_health();
if ($workerhealth['available']) {
    $a = (object)[
        'php' => s($workerhealth['php']),
        'launcher' => s($workerhealth['launcher']),
    ];
    echo $OUTPUT->notification(get_string('dep_workerhealthybanner', 'tool_salasatipicas', $a),
        \core\output\notification::NOTIFY_SUCCESS);
} else {
    echo $OUTPUT->notification(get_string('dep_workerunhealthybanner', 'tool_salasatipicas', s($workerhealth['reason'])),
        \core\output\notification::NOTIFY_ERROR);
}

echo html_writer::start_div('salasat-dep-card');
$form->display();
echo html_writer::end_div();

echo html_writer::start_div('salasat-dep-card mt-4');
echo html_writer::start_div('salasat-dep-toolbar');
echo html_writer::tag('h3', get_string('dep_configuredcategories', 'tool_salasatipicas'));
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/dependency_category.php'),
    get_string('dep_addcategoryconfig', 'tool_salasatipicas'), ['class' => 'btn btn-primary']);
echo html_writer::end_div();

$categories = manager::get_categories(false);
if (!$categories) {
    echo $OUTPUT->notification(get_string('dep_nocategoriesconfigured', 'tool_salasatipicas'),
        \core\output\notification::NOTIFY_WARNING);
} else {
    $table = new html_table();
    $table->head = [
        get_string('dep_categoryname', 'tool_salasatipicas'),
        get_string('dep_moodlecategoryid', 'tool_salasatipicas'),
        get_string('dep_categorytemplateid', 'tool_salasatipicas'),
        get_string('dep_active', 'tool_salasatipicas'),
        get_string('actions'),
    ];
    foreach ($categories as $category) {
        $table->data[] = [
            s($category->name),
            (int)$category->categoryid,
            $category->templatecourseid ? (int)$category->templatecourseid : get_string('dep_useglobaltemplate', 'tool_salasatipicas'),
            $category->active ? get_string('yes') : get_string('no'),
            html_writer::link(new moodle_url('/admin/tool/salasatipicas/dependency_category.php', ['id' => $category->id]), get_string('edit')),
        ];
    }
    echo html_writer::table($table);
}
echo html_writer::end_div();

echo $OUTPUT->footer();
