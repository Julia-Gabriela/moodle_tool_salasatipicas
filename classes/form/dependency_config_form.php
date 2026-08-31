<?php
// This file is part of Moodle - http://moodle.org/

namespace tool_salasatipicas\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * General plugin configuration form.
 *
 * @package tool_salasatipicas
 */
class dependency_config_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $roles = $this->_customdata['roles'] ?? [];

        $mform->addElement('header', 'safetyheader', get_string('dep_safetysettings', 'tool_salasatipicas'));
        $mform->addElement('selectyesno', 'creationenabled', get_string('dep_creationenabled', 'tool_salasatipicas'));
        $mform->setType('creationenabled', PARAM_BOOL);
        $mform->setDefault('creationenabled', 1);
        $mform->addHelpButton('creationenabled', 'dep_creationenabled', 'tool_salasatipicas');
        $mform->addElement('static', 'safetynotice', '', get_string('dep_safetynotice', 'tool_salasatipicas'));

        $mform->addElement('header', 'namingheader', get_string('dep_namingrules', 'tool_salasatipicas'));
        $mform->addElement('textarea', 'fullnamepattern', get_string('dep_fullnamepattern', 'tool_salasatipicas'), [
            'rows' => 3,
            'cols' => 90,
        ]);
        $mform->setType('fullnamepattern', PARAM_RAW_TRIMMED);
        $mform->addRule('fullnamepattern', null, 'required', null, 'client');
        $mform->addHelpButton('fullnamepattern', 'dep_patterns', 'tool_salasatipicas');

        $mform->addElement('text', 'shortnamepattern', get_string('dep_shortnamepattern', 'tool_salasatipicas'), ['size' => 80]);
        $mform->setType('shortnamepattern', PARAM_RAW_TRIMMED);
        $mform->addRule('shortnamepattern', null, 'required', null, 'client');
        $mform->addHelpButton('shortnamepattern', 'dep_patterns', 'tool_salasatipicas');

        $mform->addElement('header', 'templateheader', get_string('dep_templateandroles', 'tool_salasatipicas'));
        $mform->addElement('text', 'defaulttemplateid', get_string('dep_defaulttemplateid', 'tool_salasatipicas'), ['size' => 15]);
        $mform->setType('defaulttemplateid', PARAM_INT);
        $mform->addHelpButton('defaulttemplateid', 'dep_defaulttemplateid', 'tool_salasatipicas');

        $mform->addElement('select', 'studentroleid', get_string('dep_studentrole', 'tool_salasatipicas'), $roles);
        $mform->setType('studentroleid', PARAM_INT);
        $mform->addElement('select', 'teacherroleid', get_string('dep_teacherrole', 'tool_salasatipicas'), $roles);
        $mform->setType('teacherroleid', PARAM_INT);
        $mform->addElement('select', 'coordinationroleid', get_string('dep_coordinationdefaultrole', 'tool_salasatipicas'), $roles);
        $mform->setType('coordinationroleid', PARAM_INT);

        $mform->addElement('selectyesno', 'coursevisible', get_string('dep_coursevisible', 'tool_salasatipicas'));
        $mform->setType('coursevisible', PARAM_BOOL);

        $this->add_action_buttons(false, get_string('savechanges'));
    }

    public function validation($data, $files): array {
        global $DB;
        $errors = parent::validation($data, $files);

        $templateid = (int)($data['defaulttemplateid'] ?? 0);
        if ($templateid > 0 && !$DB->record_exists('course', ['id' => $templateid])) {
            $errors['defaulttemplateid'] = get_string('dep_invalidtemplatecourse', 'tool_salasatipicas');
        }
        foreach (['studentroleid', 'teacherroleid', 'coordinationroleid'] as $field) {
            $roleid = (int)($data[$field] ?? 0);
            if ($roleid <= 0 || !$DB->record_exists('role', ['id' => $roleid]) ||
                    !$DB->record_exists('role_context_levels', ['roleid' => $roleid, 'contextlevel' => CONTEXT_COURSE])) {
                $errors[$field] = get_string('dep_invalidrole', 'tool_salasatipicas');
            }
        }

        $allowed = ['PROCESSO', 'DISCIPLINA', 'PROFESSOR', 'PROFESSORES', 'ALUNO', 'ALUNOS', 'COORDENACAO',
            'USERNAME_PROFESSOR', 'USERNAME_ALUNO', 'PERIODO', 'CATEGORIA'];
        foreach (['fullnamepattern', 'shortnamepattern'] as $field) {
            preg_match_all('/\{([A-Z_]+)\}/', (string)($data[$field] ?? ''), $matches);
            $unknown = array_values(array_diff(array_unique($matches[1] ?? []), $allowed));
            if ($unknown) {
                $errors[$field] = get_string('dep_unknownpatternvariables', 'tool_salasatipicas', implode(', ', $unknown));
            }
        }
        return $errors;
    }
}
