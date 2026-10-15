<?php
// This file is part of Moodle - http://moodle.org/
defined('MOODLE_INTERNAL') || die();

/**
 * Fresh-install defaults. This function performs default configuration only.
 * It never launches CLI workers, backup/restore, cron, or ad-hoc tasks.
 */
function xmldb_tool_salasatipicas_install(): void {
    global $DB;

    $studentroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student'], IGNORE_MISSING);
    $teacherroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher'], IGNORE_MISSING);

    // Dependency defaults: independent configuration.
    $depdefaults = [
        'creationenabled' => 1,
        'fullnamepattern' => 'PROCESSO N°{PROCESSO} - {DISCIPLINA} - Prof. {PROFESSOR} - {PERIODO} - Dependência',
        'shortnamepattern' => 'PROCESSO N°{PROCESSO} - {DISCIPLINA} - Prof. {PROFESSOR} - {PERIODO} - Dependência',
        'defaulttemplateid' => 0,
        'studentroleid' => $studentroleid,
        'teacherroleid' => $teacherroleid,
        'coordinationroleid' => $teacherroleid,
        'coursevisible' => 0,
    ];
    foreach ($depdefaults as $key => $default) {
        set_config('dep_' . $key, $default, 'tool_salasatipicas');
    }

    // REA defaults: independent configuration.
    $readefaults = [
        'creationenabled' => 1,
        'fullnamepattern' => 'PROCESSO N° {PROCESSO} - {ALUNO} - {SIGLA_CURSO}- {TURNO}{SEMESTRE}{TURMA} - {PERIODO_CURTO} - REA',
        'shortnamepattern' => 'PROCESSO N° {PROCESSO} - {ALUNO} - {SIGLA_CURSO}- {TURNO}{SEMESTRE}{TURMA} - {PERIODO_CURTO} - REA',
        'defaulttemplateid' => 0,
        'studentroleid' => $studentroleid,
        'teacherroleid' => $teacherroleid,
        'coordinationroleid' => $teacherroleid,
        'coursevisible' => 0,
        'infomarker' => 'Aluno (a):',
        'materialmarker' => 'Material da Disciplina',
        'assignmentmarker' => 'Entrega da Atividade REA - Disciplina',
        'studentsectionmarker' => 'Aluno: xxx',
    ];
    foreach ($readefaults as $key => $default) {
        set_config('rea_' . $key, $default, 'tool_salasatipicas');
    }

}
