<?php
namespace tool_salasatipicas\event;

defined('MOODLE_INTERNAL') || die();

class dependency_failed extends \core\event\base {
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'tool_salasat_dep_hist';
    }
    public static function get_name(): string {
        return get_string('dep_eventdependencyfailed', 'tool_salasatipicas');
    }
    public function get_description(): string {
        return "The dependency creation for history id '{$this->objectid}' and process " .
            "'{$this->other['processnumber']}' failed.";
    }
    public function get_url(): \moodle_url {
        return new \moodle_url('/admin/tool/salasatipicas/dependency_history.php');
    }
    protected function validate_data(): void {
        parent::validate_data();
        if (!isset($this->other['processnumber'])) {
            throw new \coding_exception('processnumber must be set in other.');
        }
    }
}
