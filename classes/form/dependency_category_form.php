<?php
// This file is part of Moodle - http://moodle.org/

namespace tool_salasatipicas\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Category configuration form.
 *
 * @package tool_salasatipicas
 */
class dependency_category_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'name', get_string('dep_categoryname', 'tool_salasatipicas'), ['size' => 50]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement('text', 'categoryid', get_string('dep_moodlecategoryid', 'tool_salasatipicas'), ['size' => 15]);
        $mform->setType('categoryid', PARAM_INT);
        $mform->addRule('categoryid', null, 'required', null, 'client');

        $mform->addElement('text', 'templatecourseid', get_string('dep_categorytemplateid', 'tool_salasatipicas'), ['size' => 15]);
        $mform->setType('templatecourseid', PARAM_INT);
        $mform->addHelpButton('templatecourseid', 'dep_categorytemplateid', 'tool_salasatipicas');

        $mform->addElement('advcheckbox', 'active', get_string('dep_active', 'tool_salasatipicas'));
        $mform->setDefault('active', 1);

        $mform->addElement('text', 'sortorder', get_string('dep_sortorder', 'tool_salasatipicas'), ['size' => 10]);
        $mform->setType('sortorder', PARAM_INT);
        $mform->setDefault('sortorder', 0);

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    public function validation($data, $files): array {
        global $DB;
        $errors = parent::validation($data, $files);
        $id = (int)($data['id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        $categoryid = (int)($data['categoryid'] ?? 0);
        $templateid = (int)($data['templatecourseid'] ?? 0);

        if ($categoryid <= 0 || !$DB->record_exists('course_categories', ['id' => $categoryid])) {
            $errors['categoryid'] = get_string('dep_invalidmoodlecategory', 'tool_salasatipicas');
        }
        if ($templateid > 0 && !$DB->record_exists('course', ['id' => $templateid])) {
            $errors['templatecourseid'] = get_string('dep_invalidtemplatecourse', 'tool_salasatipicas');
        }
        if ($name !== '') {
            $existing = $DB->get_record('tool_salasat_dep_cat', ['name' => $name]);
            if ($existing && (int)$existing->id !== $id) {
                $errors['name'] = get_string('dep_categorynameexists', 'tool_salasatipicas');
            }
        }
        return $errors;
    }
}
