<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../../config.php');

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use tool_salasatipicas\local\dependency_importer as importer;
use tool_salasatipicas\local\dependency_manager as manager;

require_login();
if (!is_siteadmin()) {
    throw new moodle_exception('dep_siteadminonly', 'tool_salasatipicas');
}
require_capability('tool/salasatipicas:manage', context_system::instance());
require_sesskey();

$categories = manager::get_categories(true);
$categoryname = $categories ? reset($categories)->name : 'Categoria configurada';
$teacherroleid = (int)manager::get_config_value('teacherroleid', manager::role_id_by_shortname('editingteacher'));
$studentroleid = (int)manager::get_config_value('studentroleid', manager::role_id_by_shortname('student'));
$coordroleid = (int)manager::get_config_value('coordinationroleid', $teacherroleid);
$rolelookup = $DB->get_records('role', null, 'sortorder ASC, name ASC');
$teacherrole = isset($rolelookup[$teacherroleid]) ? $rolelookup[$teacherroleid]->shortname : '';
$studentrole = isset($rolelookup[$studentroleid]) ? $rolelookup[$studentroleid]->shortname : '';
$coordrole = isset($rolelookup[$coordroleid]) ? $rolelookup[$coordroleid]->shortname : '';

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Dependencias');
$headers = [
    'processo', 'disciplina', 'professor', 'papel_professor', 'coordenacao', 'papel_coordenacao',
    'aluno', 'papel_aluno', 'periodo', 'categoria',
];
foreach ($headers as $i => $header) {
    $column = Coordinate::stringFromColumnIndex($i + 1);
    $sheet->setCellValueExplicit($column . '1', $header, DataType::TYPE_STRING);
}
$example = [
    '322/2026', 'Fundamentos da Computação em Nuvem', 'professor.username', $teacherrole,
    'coordenadora.username|coordenadora2.username', $coordrole,
    '08324762183|outro.aluno', $studentrole, '02/2026', $categoryname,
];
foreach ($example as $i => $value) {
    $column = Coordinate::stringFromColumnIndex($i + 1);
    $sheet->setCellValueExplicit($column . '2', $value, DataType::TYPE_STRING);
}

// Force all import columns to Text so Excel preserves usernames and semester formatting.
$sheet->getStyle('A1:J' . (importer::MAX_ROWS + 1))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
$sheet->getStyle('A1:J1')->getFont()->setBold(true);
$sheet->freezePane('A2');
$sheet->setAutoFilter('A1:J1');
$widths = ['A' => 18, 'B' => 42, 'C' => 34, 'D' => 22, 'E' => 42, 'F' => 24, 'G' => 34, 'H' => 22, 'I' => 16, 'J' => 42];
foreach ($widths as $column => $width) {
    $sheet->getColumnDimension($column)->setWidth($width);
}

$info = $spreadsheet->createSheet();
$info->setTitle('Instrucoes');
$instructions = [
    ['MODELO DE DEPENDÊNCIAS'],
    ['Não altere os nomes das colunas da aba Dependencias.'],
    ['A linha 2 é apenas um exemplo: substitua pelos dados reais antes de importar.'],
    ['Para inserir VÁRIAS pessoas no mesmo campo, separe usernames com | (barra vertical). Ex.: prof1|prof2.'],
    ['professor e aluno continuam obrigatórios. coordenacao é opcional.'],
    ['papel_professor, papel_coordenacao e papel_aluno aceitam o ID ou o shortname do papel Moodle. Se vazios, usam os padrões configurados no plugin.'],
    ['O primeiro professor informado é o professor principal usado na variável {PROFESSOR} do nome da sala.'],
    ['As colunas estão formatadas como Texto para preservar zeros iniciais e o período 01/AAAA ou 02/AAAA.'],
    ['Categoria deve ser um dos nomes configurados abaixo ou o ID Moodle da categoria.'],
    ['Máximo de linhas por arquivo: ' . importer::MAX_ROWS . '. Tamanho máximo: 2 MiB.'],
    ['Mantenha a página de progresso aberta até o lote terminar; as salas são processadas sequencialmente.'],
    [],
    ['Categorias ativas:'],
];
$row = 1;
foreach ($instructions as $values) {
    $info->setCellValueExplicit('A' . $row, $values[0] ?? '', DataType::TYPE_STRING);
    $row++;
}
foreach ($categories as $category) {
    $info->setCellValueExplicit('A' . $row, (string)$category->name, DataType::TYPE_STRING);
    $info->setCellValueExplicit('B' . $row, (string)$category->categoryid, DataType::TYPE_STRING);
    $row++;
}
$row += 2;
$info->setCellValueExplicit('A' . $row, 'Papéis disponíveis no contexto de curso:', DataType::TYPE_STRING);
$info->getStyle('A' . $row)->getFont()->setBold(true);
$row++;
foreach ($rolelookup as $role) {
    if (!$DB->record_exists('role_context_levels', ['roleid' => $role->id, 'contextlevel' => CONTEXT_COURSE])) {
        continue;
    }
    $info->setCellValueExplicit('A' . $row, role_get_name($role, context_system::instance(), ROLENAME_ORIGINAL), DataType::TYPE_STRING);
    $info->setCellValueExplicit('B' . $row, (string)$role->shortname, DataType::TYPE_STRING);
    $info->setCellValueExplicit('C' . $row, (string)$role->id, DataType::TYPE_STRING);
    $row++;
}
$info->getColumnDimension('A')->setWidth(110);
$info->getColumnDimension('B')->setWidth(30);
$info->getColumnDimension('C')->setWidth(12);
$info->getStyle('A1')->getFont()->setBold(true);
$info->getStyle('A13')->getFont()->setBold(true);

$spreadsheet->setActiveSheetIndex(0);
$filename = 'modelo_dependencias.xlsx';
@header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
@header('Content-Disposition: attachment; filename="' . $filename . '"');
@header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
@header('Pragma: public');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
$spreadsheet->disconnectWorksheets();
exit;
