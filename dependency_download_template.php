<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../../config.php');

use tool_salasatipicas\local\dependency_manager as manager;

require_login();
if (!is_siteadmin()) {
    throw new moodle_exception('dep_siteadminonly', 'tool_salasatipicas');
}
require_capability('tool/salasatipicas:manage', context_system::instance());
require_sesskey();

$categories = manager::get_categories(true);
$categoryname = $categories ? reset($categories)->name : 'Categoria de exemplo';
$teacherroleid = (int)manager::get_config_value('teacherroleid', manager::role_id_by_shortname('editingteacher'));
$studentroleid = (int)manager::get_config_value('studentroleid', manager::role_id_by_shortname('student'));
$coordroleid = (int)manager::get_config_value('coordinationroleid', $teacherroleid);
$roles = $DB->get_records_list('role', 'id', array_values(array_unique([$teacherroleid, $studentroleid, $coordroleid])), '', 'id,shortname');
$teacherrole = $roles[$teacherroleid]->shortname ?? '';
$studentrole = $roles[$studentroleid]->shortname ?? '';
$coordrole = $roles[$coordroleid]->shortname ?? '';

$filename = 'modelo_dependencias.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'wb');
fputcsv($out, [
    'processo', 'disciplina', 'professor', 'papel_professor', 'coordenacao', 'papel_coordenacao',
    'aluno', 'papel_aluno', 'periodo', 'categoria',
], ';');
fputcsv($out, [
    '322/2026', 'Fundamentos da Computação em Nuvem', 'professor.username', $teacherrole,
    'coordenadora.username|coordenadora2.username', $coordrole,
    'aluno.username|outro.aluno', $studentrole, '02/2026', $categoryname,
], ';');
fclose($out);
exit;
