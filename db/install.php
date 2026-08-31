<?php
// This file is part of Moodle - http://moodle.org/
defined('MOODLE_INTERNAL') || die();

/**
 * Fresh-install defaults for tool_salasatipicas.
 *
 * This hook only stores lightweight configuration defaults. It never launches
 * workers, backup/restore, cron or ad-hoc tasks.
 */
function xmldb_tool_salasatipicas_install(): void {
    global $DB;

    $studentroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'student'], IGNORE_MISSING);
    $teacherroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher'], IGNORE_MISSING);

    $dependencydefaults = [
        'creationenabled' => 1,
        'fullnamepattern' => 'PROCESSO N°{PROCESSO} - {DISCIPLINA} - Prof. {PROFESSOR} - {PERIODO} - Dependência',
        'shortnamepattern' => 'PROCESSO N°{PROCESSO} - {DISCIPLINA} - Prof. {PROFESSOR} - {PERIODO} - Dependência',
        'defaulttemplateid' => 0,
        'studentroleid' => $studentroleid,
        'teacherroleid' => $teacherroleid,
        'coordinationroleid' => $teacherroleid,
        'coursevisible' => 0,
    ];
    foreach ($dependencydefaults as $key => $value) {
        set_config('dep_' . $key, $value, 'tool_salasatipicas');
    }

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
    foreach ($readefaults as $key => $value) {
        set_config('rea_' . $key, $value, 'tool_salasatipicas');
    }
}
