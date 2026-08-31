<?php
namespace tool_salasatipicas\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

class rea_config_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $roles = $this->_customdata['roles'] ?? [];

        $mform->addElement('header', 'safetyheader', get_string('rea_safetysettings', 'tool_salasatipicas'));
        $mform->addElement('selectyesno', 'creationenabled', get_string('rea_creationenabled', 'tool_salasatipicas'));
        $mform->setType('creationenabled', PARAM_BOOL);
        $mform->addElement('static', 'safetynotice', '', get_string('rea_safetynotice', 'tool_salasatipicas'));

        $mform->addElement('header', 'namingheader', get_string('rea_namingrules', 'tool_salasatipicas'));
        $mform->addElement('textarea', 'fullnamepattern', get_string('rea_fullnamepattern', 'tool_salasatipicas'), ['rows' => 2, 'cols' => 80]);
        $mform->setType('fullnamepattern', PARAM_RAW_TRIMMED);
        $mform->addRule('fullnamepattern', null, 'required', null, 'client');
        $mform->addElement('text', 'shortnamepattern', get_string('rea_shortnamepattern', 'tool_salasatipicas'), ['size' => 80]);
        $mform->setType('shortnamepattern', PARAM_RAW_TRIMMED);
        $mform->addRule('shortnamepattern', null, 'required', null, 'client');
        $mform->addElement('static', 'variables', get_string('rea_availablevariables', 'tool_salasatipicas'), '{PROCESSO}, {ALUNO}, {CURSO}, {SIGLA_CURSO}, {TURNO}, {SEMESTRE}, {TURMA}, {PERIODO}, {PERIODO_CURTO}, {INICIO_LICENCA}, {FIM_LICENCA}, {USERNAME_ALUNO}, {CATEGORIA}');

        $mform->addElement('header', 'templateheader', get_string('rea_templateandroles', 'tool_salasatipicas'));
        $mform->addElement('text', 'defaulttemplateid', get_string('rea_defaulttemplateid', 'tool_salasatipicas'), ['size' => 15]);
        $mform->setType('defaulttemplateid', PARAM_INT);
        $mform->addElement('select', 'studentroleid', get_string('rea_studentrole', 'tool_salasatipicas'), $roles);
        $mform->setType('studentroleid', PARAM_INT);
        $mform->addElement('select', 'teacherroleid', get_string('rea_teacherrole', 'tool_salasatipicas'), $roles);
        $mform->setType('teacherroleid', PARAM_INT);
        $mform->addElement('select', 'coordinationroleid', get_string('rea_coordinationrole', 'tool_salasatipicas'), $roles);
        $mform->setType('coordinationroleid', PARAM_INT);
        $mform->addElement('selectyesno', 'coursevisible', get_string('rea_coursevisible', 'tool_salasatipicas'));
        $mform->setType('coursevisible', PARAM_BOOL);

        $mform->addElement('header', 'markersheader', get_string('rea_templatemarkers', 'tool_salasatipicas'));
        $mform->addElement('text', 'infomarker', get_string('rea_infomarker', 'tool_salasatipicas'), ['size' => 60]);
        $mform->setType('infomarker', PARAM_TEXT);
        $mform->addRule('infomarker', null, 'required', null, 'client');
        $mform->addElement('text', 'materialmarker', get_string('rea_materialmarker', 'tool_salasatipicas'), ['size' => 60]);
        $mform->setType('materialmarker', PARAM_TEXT);
        $mform->addRule('materialmarker', null, 'required', null, 'client');
        $mform->addElement('text', 'assignmentmarker', get_string('rea_assignmentmarker', 'tool_salasatipicas'), ['size' => 60]);
        $mform->setType('assignmentmarker', PARAM_TEXT);
        $mform->addRule('assignmentmarker', null, 'required', null, 'client');
        $mform->addElement('text', 'studentsectionmarker', get_string('rea_studentsectionmarker', 'tool_salasatipicas'), ['size' => 60]);
        $mform->setType('studentsectionmarker', PARAM_TEXT);
        $mform->addRule('studentsectionmarker', null, 'required', null, 'client');
        $mform->addElement('static', 'markernotice', '', get_string('rea_markernotice', 'tool_salasatipicas'));

        $this->add_action_buttons(false, get_string('savechanges'));
    }

    public function validation($data, $files): array {
        global $DB;
        $errors = parent::validation($data, $files);
        $templateid = (int)($data['defaulttemplateid'] ?? 0);
        if ($templateid > 0 && !$DB->record_exists('course', ['id' => $templateid])) {
            $errors['defaulttemplateid'] = get_string('rea_invalidtemplatecourse', 'tool_salasatipicas');
        }
        foreach (['studentroleid', 'teacherroleid', 'coordinationroleid'] as $field) {
            $roleid = (int)($data[$field] ?? 0);
            if ($roleid <= 0 || !$DB->record_exists('role_context_levels', ['roleid' => $roleid, 'contextlevel' => CONTEXT_COURSE])) {
                $errors[$field] = get_string('rea_invalidrole', 'tool_salasatipicas');
            }
        }
        $allowed = ['PROCESSO', 'ALUNO', 'CURSO', 'SIGLA_CURSO', 'TURNO', 'SEMESTRE', 'TURMA', 'PERIODO', 'PERIODO_CURTO', 'INICIO_LICENCA', 'FIM_LICENCA', 'USERNAME_ALUNO', 'CATEGORIA'];
        foreach (['fullnamepattern', 'shortnamepattern'] as $field) {
            preg_match_all('/\{([A-Z_]+)\}/', (string)($data[$field] ?? ''), $matches);
            $unknown = array_diff(array_unique($matches[1] ?? []), $allowed);
            if ($unknown) { $errors[$field] = get_string('rea_unknownpatternvariables', 'tool_salasatipicas', implode(', ', $unknown)); }
        }
        return $errors;
    }
}
