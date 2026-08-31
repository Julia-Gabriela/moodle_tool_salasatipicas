<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_salasatipicas\form\dependency_category_form as category_form;

require_login();
if (!is_siteadmin()) {
    throw new moodle_exception('dep_siteadminonly', 'tool_salasatipicas');
}
admin_externalpage_setup('tool_salasatipicas');
require_capability('tool/salasatipicas:manage', context_system::instance());

$id = optional_param('id', 0, PARAM_INT);
$PAGE->set_url(new moodle_url('/admin/tool/salasatipicas/dependency_category.php', $id ? ['id' => $id] : []));
$PAGE->set_title(get_string($id ? 'dep_editcategoryconfig' : 'dep_addcategoryconfig', 'tool_salasatipicas'));
$PAGE->set_heading(get_string($id ? 'dep_editcategoryconfig' : 'dep_addcategoryconfig', 'tool_salasatipicas'));
$PAGE->requires->css(new moodle_url('/admin/tool/salasatipicas/styles.css'));

$record = null;
if ($id) {
    $record = $DB->get_record('tool_atyp_dep_cat', ['id' => $id], '*', MUST_EXIST);
}
$form = new category_form($PAGE->url);
if ($record) {
    $form->set_data($record);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/admin/tool/salasatipicas/dependency_configuration.php'));
}
if ($data = $form->get_data()) {
    $now = time();
    $save = (object)[
        'name' => trim($data->name),
        'categoryid' => (int)$data->categoryid,
        'templatecourseid' => (int)$data->templatecourseid,
        'active' => !empty($data->active) ? 1 : 0,
        'sortorder' => (int)$data->sortorder,
        'timemodified' => $now,
    ];
    if (!empty($data->id)) {
        $save->id = (int)$data->id;
        $DB->update_record('tool_atyp_dep_cat', $save);
    } else {
        $save->timecreated = $now;
        $DB->insert_record('tool_atyp_dep_cat', $save);
    }
    redirect(new moodle_url('/admin/tool/salasatipicas/dependency_configuration.php'), get_string('changessaved'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo html_writer::start_div('salasat-dep-card');
$form->display();
echo html_writer::end_div();
echo $OUTPUT->footer();
