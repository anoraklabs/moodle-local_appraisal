<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_appraisal\event;

use core\event\base;
use moodle_url;

/**
 * A user's appraisal was deleted: the assignment with every cycle on it.
 *
 * The template name and counts are kept in the event, since the rows they came from no longer exist.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assignment_deleted extends base {
    /**
     * Set the event properties.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_appraisal_assignment';
    }

    /**
     * The event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event:assignmentdeleted', 'local_appraisal');
    }

    /**
     * Describe what happened.
     *
     * @return string
     */
    public function get_description(): string {
        return get_string('event:assignmentdeleted_desc', 'local_appraisal', (object) [
            'userid' => $this->userid,
            'assignmentid' => $this->objectid,
            'relateduserid' => $this->relateduserid,
            'templateid' => $this->other['templateid'],
            'templatename' => $this->other['templatename'],
            'records' => $this->other['records'],
            'completed' => $this->other['completed'],
        ]);
    }

    /**
     * The appraisee's dashboard.
     *
     * @return moodle_url
     */
    public function get_url(): moodle_url {
        return new moodle_url('/local/appraisal/dashboard.php', ['userid' => $this->relateduserid]);
    }

    /**
     * Validate the custom data.
     *
     * @return void
     * @throws \coding_exception
     */
    protected function validate_data(): void {
        parent::validate_data();
        foreach (['templateid', 'templatename', 'records', 'completed'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '{$key}' value must be set in other.");
            }
        }
        if (!isset($this->relateduserid)) {
            throw new \coding_exception("The 'relateduserid' must be set.");
        }
    }

    /**
     * Map the object for restore, which is not supported for audit events.
     *
     * @return false
     */
    public static function get_objectid_mapping(): false {
        return false;
    }

    /**
     * Map the other data for restore, which is not supported for audit events.
     *
     * @return false
     */
    public static function get_other_mapping(): false {
        return false;
    }
}
