<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_login();
if (!is_siteadmin()) { throw new moodle_exception('siteadminonly', 'tool_salasatipicas'); }
admin_externalpage_setup('tool_salasatipicas');
require_capability('tool/salasatipicas:manage', context_system::instance());
$PAGE->set_url(new moodle_url('/admin/tool/salasatipicas/history.php'));
$PAGE->set_title(get_string('globalhistory','tool_salasatipicas'));
$PAGE->set_heading(get_string('globalhistory','tool_salasatipicas'));
$PAGE->requires->css(new moodle_url('/admin/tool/salasatipicas/styles.css'));
$page = optional_param('page',0,PARAM_INT); $perpage=50;
$rows=[];
foreach ($DB->get_records('tool_atyp_dep_hist', null, 'timecreated DESC') as $r) {
    $rows[]=(object)['type'=>'Dependência','timecreated'=>$r->timecreated,'reference'=>$r->processnumber.' — '.$r->subject,'status'=>$r->status,'message'=>$r->message,'courseid'=>$r->targetcourseid,'detailurl'=>new moodle_url('/admin/tool/salasatipicas/dependency_history.php')];
}
foreach ($DB->get_records('tool_atyp_rea_hist', null, 'timecreated DESC') as $r) {
    $rows[]=(object)['type'=>'REA','timecreated'=>$r->timecreated,'reference'=>$r->studentdisplayname.' — '.$r->courseprogram,'status'=>$r->status,'message'=>$r->message,'courseid'=>$r->targetcourseid,'detailurl'=>new moodle_url('/admin/tool/salasatipicas/rea_history.php')];
}
usort($rows, fn($a,$b)=>$b->timecreated <=> $a->timecreated);
$total=count($rows); $rows=array_slice($rows,$page*$perpage,$perpage);
echo $OUTPUT->header();
echo html_writer::start_div('salasat-topactions');
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/index.php'), '← '.get_string('backtocentral','tool_salasatipicas'), ['class'=>'btn btn-secondary']);
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/dependency_history.php'), 'Histórico de Dependências', ['class'=>'btn btn-secondary']);
echo html_writer::link(new moodle_url('/admin/tool/salasatipicas/rea_history.php'), 'Histórico de REA', ['class'=>'btn btn-secondary']);
echo html_writer::end_div();
if (!$rows) { echo $OUTPUT->notification('Ainda não há registros no histórico desta central.', \core\output\notification::NOTIFY_INFO); }
else {
  $table=new html_table(); $table->head=[get_string('date'),get_string('type','tool_salasatipicas'),get_string('reference','tool_salasatipicas'),get_string('course','tool_salasatipicas'),get_string('details')];
  foreach($rows as $r){
    $course=$r->courseid ? html_writer::link(new moodle_url('/course/view.php',['id'=>$r->courseid]), 'Abrir sala') : '-';
    $details=s((string)$r->message).'<br>'.html_writer::link($r->detailurl,'Ver histórico detalhado');
    $table->data[]=[userdate($r->timecreated),s($r->type),s($r->reference),$course,$details];
  }
  echo html_writer::table($table); echo $OUTPUT->paging_bar($total,$page,$perpage,$PAGE->url);
}
echo $OUTPUT->footer();
