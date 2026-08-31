<?php
// This file is part of Moodle - http://moodle.org/

namespace tool_salasatipicas\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Manual dependency creation form.
 *
 * Supports multiple teachers, multiple coordinators and multiple students.
 * Roles are defined globally in the plugin configuration so the manual form
 * stays visually cleaner and aligned.
 *
 * @package tool_salasatipicas
 */
class dependency_form extends \moodleform {
    /** @var array */
    protected $categoryoptions = [];

    protected function definition(): void {
        $mform = $this->_form;
        $this->categoryoptions = $this->_customdata['categories'] ?? [];
        $creationenabled = !empty($this->_customdata['creationenabled']);


        $mform->addElement('header', 'manualheader', get_string('dep_manualcreation', 'tool_salasatipicas'));
        $mform->addElement('text', 'processnumber', get_string('dep_processnumber', 'tool_salasatipicas'), ['size' => 30]);
        $mform->setType('processnumber', PARAM_TEXT);
        $mform->addRule('processnumber', null, 'required', null, 'client');

        $mform->addElement('text', 'subject', get_string('dep_subject', 'tool_salasatipicas'), ['size' => 60]);
        $mform->setType('subject', PARAM_TEXT);
        $mform->addRule('subject', null, 'required', null, 'client');

        $userattributes = [
            'ajax' => 'core_user/form_user_selector',
            'multiple' => true,
            'showsuggestions' => true,
            'placeholder' => get_string('dep_usersearchplaceholdermultiple', 'tool_salasatipicas'),
            'noselectionstring' => get_string('dep_usersearchnoselectionmultiple', 'tool_salasatipicas'),
            'valuehtmlcallback' => [self::class, 'user_value_html'],
        ];

        $mform->addElement('header', 'participantsheader', get_string('dep_participantsheader', 'tool_salasatipicas'));

        $mform->addElement('autocomplete', 'teacheruserids', get_string('dep_teachers', 'tool_salasatipicas'), [], $userattributes);
        $mform->addRule('teacheruserids', null, 'required', null, 'client');
        $mform->addHelpButton('teacheruserids', 'dep_teachers', 'tool_salasatipicas');

        $mform->addElement('autocomplete', 'coordinationuserids', get_string('dep_coordination', 'tool_salasatipicas'), [], $userattributes);
        $mform->addHelpButton('coordinationuserids', 'dep_coordination', 'tool_salasatipicas');

        $mform->addElement('autocomplete', 'studentuserids', get_string('dep_students', 'tool_salasatipicas'), [], $userattributes);
        $mform->addRule('studentuserids', null, 'required', null, 'client');
        $mform->addHelpButton('studentuserids', 'dep_students', 'tool_salasatipicas');

        $mform->addElement('text', 'period', get_string('dep_period', 'tool_salasatipicas'), ['size' => 20]);
        $mform->setType('period', PARAM_TEXT);
        $mform->addRule('period', null, 'required', null, 'client');
        $mform->addHelpButton('period', 'dep_period', 'tool_salasatipicas');

        $mform->addElement('select', 'categoryconfigid', get_string('dep_destinationcategory', 'tool_salasatipicas'), $this->categoryoptions);
        $mform->setType('categoryconfigid', PARAM_INT);
        $mform->addRule('categoryconfigid', null, 'required', null, 'client');

        $mform->addElement(
            'static',
            'namepatternhint',
            get_string('dep_namepreviewlabel', 'tool_salasatipicas'),
            get_string('dep_namepreviewhintmultiple', 'tool_salasatipicas')
        );

        $label = $creationenabled
            ? get_string('dep_reviewdependency', 'tool_salasatipicas')
            : get_string('dep_validatedependency', 'tool_salasatipicas');
        $this->add_action_buttons(false, $label);
    }

    /**
     * Render a selected AJAX value with the same useful details shown in the suggestions.
     *
     * @param int|string $userid
     * @return string|false
     */
    public static function user_value_html($userid) {
        global $DB;

        $userid = clean_param($userid, PARAM_INT);
        if ($userid <= 0) {
            return false;
        }

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id,username,firstname,lastname,email');
        if (!$user) {
            return false;
        }

        $label = fullname($user) . ' — ' . $user->username;
        if (trim((string)$user->email) !== '') {
            $label .= ' — ' . $user->email;
        }
        return s($label);
    }

    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (empty($this->categoryoptions)) {
            $errors['categoryconfigid'] = get_string('dep_nocategoriesconfigured', 'tool_salasatipicas');
        }
        $period = \tool_salasatipicas\local\dependency_manager::normalise_period((string)($data['period'] ?? ''));
        if (!preg_match('/^(?:01|02)\/(?:19|20)\d{2}$/', $period)) {
            $errors['period'] = get_string('dep_invalidperiod', 'tool_salasatipicas');
        }
        return $errors;
    }
}
