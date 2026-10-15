<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
use tool_salasatipicas\form\rea_category_form as category_form;

require_login();
if (!is_siteadmin()) { throw new moodle_exception('rea_siteadminonly', 'tool_salasatipicas'); }
admin_externalpage_setup('tool_salasatipicas');
require_capability('tool/salasatipicas:manage', context_system::instance());

$id = optional_param('id', 0, PARAM_INT);
$PAGE->set_url(new moodle_url('/admin/tool/salasatipicas/rea_category.php', $id ? ['id'=>$id] : []));
$PAGE->set_title(get_string('rea_categoryconfig', 'tool_salasatipicas'));
$PAGE->set_heading(get_string('rea_categoryconfig', 'tool_salasatipicas'));
$PAGE->requires->css(new moodle_url('/admin/tool/salasatipicas/styles.css'));
$form = new category_form($PAGE->url);
if ($id) {
    $record = $DB->get_record('tool_salasat_rea_cat', ['id'=>$id], '*', MUST_EXIST);
    $form->set_data($record);
}
if ($form->is_cancelled()) { redirect(new moodle_url('/admin/tool/salasatipicas/rea_configuration.php')); }
if ($data = $form->get_data()) {
    $now = time();
    if (!empty($data->id)) {
        $data->timemodified = $now; $DB->update_record('tool_salasat_rea_cat', $data);
    } else {
        $data->timecreated = $now; $data->timemodified = $now; $DB->insert_record('tool_salasat_rea_cat', $data);
    }
    redirect(new moodle_url('/admin/tool/salasatipicas/rea_configuration.php'), get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}
echo $OUTPUT->header();
$form->display();
echo $OUTPUT->footer();
