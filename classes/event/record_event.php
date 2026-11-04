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
 * Base class for events about a record: objectid is the record, relateduserid the appraisee.
 *
 * 'other' carries assignmentid and templateid.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class record_event extends base {
    /**
     * Set the shared event properties.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_appraisal_record';
    }

    /**
     * The record page.
     *
     * @return moodle_url
     */
    public function get_url(): moodle_url {
        return new moodle_url('/local/appraisal/record.php', ['id' => $this->objectid]);
    }

    /**
     * Describe the event in plain words, from a lang string given the user, record and appraisee ids.
     *
     * @param string $stringkey
     * @return string
     */
    protected function describe(string $stringkey): string {
        return get_string($stringkey, 'local_appraisal', (object) [
            'userid' => $this->userid,
            'recordid' => $this->objectid,
            'relateduserid' => $this->relateduserid,
        ]);
    }

    /**
     * Validate the custom data.
     *
     * @return void
     * @throws \coding_exception
     */
    protected function validate_data(): void {
        parent::validate_data();
        if (!isset($this->other['assignmentid']) || !isset($this->other['templateid'])) {
            throw new \coding_exception("The 'assignmentid' and 'templateid' values must be set in other.");
        }
        if (!isset($this->relateduserid)) {
            throw new \coding_exception("The 'relateduserid' (appraisee) must be set.");
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
