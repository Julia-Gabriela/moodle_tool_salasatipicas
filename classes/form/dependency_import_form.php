<?php
// This file is part of Moodle - http://moodle.org/

namespace tool_salasatipicas\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Spreadsheet import form.
 *
 * @package tool_salasatipicas
 */
class dependency_import_form extends \moodleform {
    protected function definition(): void {
        $mform = $this->_form;
        $mform->addElement('header', 'importheader', get_string('dep_batchimport', 'tool_salasatipicas'));
        $mform->addElement('filepicker', 'importfile', get_string('dep_importfile', 'tool_salasatipicas'), null, [
            'accepted_types' => ['.csv', '.xlsx'],
            'maxfiles' => 1,
            'subdirs' => 0,
            'maxbytes' => \tool_salasatipicas\local\dependency_importer::MAX_FILE_BYTES,
        ]);
        $mform->addRule('importfile', null, 'required', null, 'client');
        $mform->addElement('static', 'importhelp', '', get_string('dep_importhelp', 'tool_salasatipicas'));
        $this->add_action_buttons(false, get_string('dep_validatefile', 'tool_salasatipicas'));
    }
}
