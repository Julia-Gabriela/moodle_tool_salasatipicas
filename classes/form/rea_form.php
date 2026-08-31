<?php
// This file is part of Moodle - http://moodle.org/

namespace tool_salasatipicas\form;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/formslib.php');

/** Manual REA creation form. */
class rea_form extends \moodleform {
    private array $categoryoptions = [];

    protected function definition(): void {
        $mform = $this->_form;
        $this->categoryoptions = $this->_customdata['categories'] ?? [];
        $creationenabled = !empty($this->_customdata['creationenabled']);

        $mform->addElement('header', 'studentdataheader', get_string('rea_studentdataheader', 'tool_salasatipicas'));
        $mform->addElement('text', 'studentdisplayname', get_string('rea_studentdisplayname', 'tool_salasatipicas'), ['size' => 60]);
        $mform->setType('studentdisplayname', PARAM_TEXT);
        $mform->addRule('studentdisplayname', null, 'required', null, 'client');

        $mform->addElement('text', 'courseprogram', get_string('rea_courseprogram', 'tool_salasatipicas'), ['size' => 60]);
        $mform->setType('courseprogram', PARAM_TEXT);
        $mform->addRule('courseprogram', null, 'required', null, 'client');

        $mform->addElement('header', 'namingdataheader', get_string('rea_namingdataheader', 'tool_salasatipicas'));
        $mform->addElement('text', 'processnumber', get_string('rea_processnumber', 'tool_salasatipicas'), ['size' => 20, 'placeholder' => '581']);
        $mform->setType('processnumber', PARAM_TEXT);
        $mform->addRule('processnumber', null, 'required', null, 'client');

        $mform->addElement('text', 'courseabbr', get_string('rea_courseabbr', 'tool_salasatipicas'), ['size' => 20, 'placeholder' => 'IAML']);
        $mform->setType('courseabbr', PARAM_ALPHANUMEXT);
        $mform->addRule('courseabbr', null, 'required', null, 'client');

        $mform->addElement('select', 'shift', get_string('rea_shift', 'tool_salasatipicas'), [
            'MAT' => get_string('rea_shiftmat', 'tool_salasatipicas'),
            'NOT' => get_string('rea_shiftnot', 'tool_salasatipicas'),
        ]);
        $mform->setType('shift', PARAM_ALPHA);

        $semesteroptions = [];
        for ($i = 1; $i <= 10; $i++) {
            $semesteroptions[(string)$i] = (string)$i;
        }
        $mform->addElement('select', 'semester', get_string('rea_semester', 'tool_salasatipicas'), $semesteroptions);
        $mform->setType('semester', PARAM_INT);

        $mform->addElement('select', 'classgroup', get_string('rea_classgroup', 'tool_salasatipicas'), [
            'A' => 'A',
            'B' => 'B',
        ]);
        $mform->setType('classgroup', PARAM_ALPHA);

        $mform->addElement('text', 'phone', get_string('rea_phone', 'tool_salasatipicas'), ['size' => 35]);
        $mform->setType('phone', PARAM_TEXT);

        $mform->addElement('text', 'contactemail', get_string('rea_contactemail', 'tool_salasatipicas'), ['size' => 60]);
        $mform->setType('contactemail', PARAM_EMAIL);
        $mform->addRule('contactemail', null, 'required', null, 'client');

        $mform->addElement('date_selector', 'licensestart', get_string('rea_licensestart', 'tool_salasatipicas'));
        $mform->addElement('date_selector', 'licenseend', get_string('rea_licenseend', 'tool_salasatipicas'));

        $mform->addElement('text', 'period', get_string('rea_period', 'tool_salasatipicas'), ['size' => 20, 'placeholder' => '02/2026']);
        $mform->setType('period', PARAM_TEXT);
        $mform->addRule('period', null, 'required', null, 'client');

        $userattrsingle = [
            'ajax' => 'core_user/form_user_selector',
            'multiple' => false,
            'showsuggestions' => true,
            'placeholder' => get_string('rea_usersearchplaceholder', 'tool_salasatipicas'),
            'valuehtmlcallback' => [self::class, 'user_value_html'],
        ];
        $userattrmultiple = $userattrsingle;
        $userattrmultiple['multiple'] = true;
        $userattrmultiple['placeholder'] = get_string('rea_usersearchplaceholdermultiple', 'tool_salasatipicas');

        $mform->addElement('header', 'participantsheader', get_string('rea_participantsheader', 'tool_salasatipicas'));
        $mform->addElement('autocomplete', 'moodlestudentuserid', get_string('rea_moodlestudent', 'tool_salasatipicas'), [], $userattrsingle);
        $mform->addRule('moodlestudentuserid', null, 'required', null, 'client');
        $mform->addHelpButton('moodlestudentuserid', 'rea_moodlestudent', 'tool_salasatipicas');

        $mform->addElement('autocomplete', 'coordinationuserids', get_string('rea_coordination', 'tool_salasatipicas'), [], $userattrmultiple);
        $mform->addHelpButton('coordinationuserids', 'rea_coordination', 'tool_salasatipicas');

        $mform->addElement('header', 'subjectsheader', get_string('rea_subjectsheader', 'tool_salasatipicas'));
        $repeatarray = [
            $mform->createElement('text', 'subjectname', get_string('rea_subjectname', 'tool_salasatipicas'), ['size' => 55]),
            $mform->createElement('autocomplete', 'subjectteacheruserids', get_string('rea_subjectteachers', 'tool_salasatipicas'), [], $userattrmultiple),
            $mform->createElement('submit', 'delete_subject', get_string('rea_removesubject', 'tool_salasatipicas'), [], false),
        ];
        $repeatoptions = [
            'subjectname' => ['type' => PARAM_TEXT],
            'subjectteacheruserids' => ['type' => PARAM_INT],
        ];
        $this->repeat_elements(
            $repeatarray,
            1,
            $repeatoptions,
            'subject_repeats',
            'add_subject_fields',
            1,
            get_string('rea_addsubject', 'tool_salasatipicas'),
            true,
            'delete_subject'
        );

        $mform->addElement('header', 'destinationheader', get_string('rea_destinationheader', 'tool_salasatipicas'));
        $mform->addElement('select', 'categoryconfigid', get_string('rea_destinationcategory', 'tool_salasatipicas'), $this->categoryoptions);
        $mform->setType('categoryconfigid', PARAM_INT);
        $mform->addRule('categoryconfigid', null, 'required', null, 'client');

        $mform->addElement('static', 'namehint', get_string('rea_namepreviewlabel', 'tool_salasatipicas'), get_string('rea_namepreviewhint', 'tool_salasatipicas'));
        $this->add_action_buttons(false, $creationenabled ? get_string('rea_reviewrea', 'tool_salasatipicas') : get_string('rea_validaterea', 'tool_salasatipicas'));
    }

    public static function user_value_html($userid) {
        global $DB;
        $userid = clean_param($userid, PARAM_INT);
        if ($userid <= 0) { return false; }
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id,username,firstname,lastname,email');
        if (!$user) { return false; }
        $label = fullname($user) . ' — ' . $user->username;
        if (trim((string)$user->email) !== '') { $label .= ' — ' . $user->email; }
        return s($label);
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (!$this->categoryoptions) {
            $errors['categoryconfigid'] = get_string('rea_nocategoriesconfigured', 'tool_salasatipicas');
        }
        if (!validate_email(trim((string)($data['contactemail'] ?? '')))) {
            $errors['contactemail'] = get_string('rea_invalidcontactemail', 'tool_salasatipicas');
        }
        if ((int)($data['licenseend'] ?? 0) < (int)($data['licensestart'] ?? 0)) {
            $errors['licenseend'] = get_string('rea_invalidlicensedates', 'tool_salasatipicas');
        }
        $period = \tool_salasatipicas\local\rea_manager::normalise_period((string)($data['period'] ?? ''));
        if (!preg_match('/^(?:01|02)\/(?:19|20)\d{2}$/', $period)) {
            $errors['period'] = get_string('rea_invalidperiod', 'tool_salasatipicas');
        }
        $hasvalidsubject = false;
        foreach ((array)($data['subjectname'] ?? []) as $idx => $name) {
            if (trim((string)$name) === '') { continue; }
            $hasvalidsubject = true;
            if (empty($data['subjectteacheruserids'][$idx])) {
                $errors['subjectteacheruserids[' . $idx . ']'] = get_string('rea_subjectteacherrequired', 'tool_salasatipicas', $idx + 1);
            }
        }
        if (!$hasvalidsubject) {
            $errors['subjectname[0]'] = get_string('rea_atleastonesubject', 'tool_salasatipicas');
        }
        return $errors;
    }
}
