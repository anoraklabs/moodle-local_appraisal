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
 * Shared behaviour of the events that widen or narrow who can reach whose appraisals through a cohort link.
 *
 * Both events carry the two cohort ids in 'other', because the link row is gone by the time a deletion is read.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class cohort_link_event extends base {
    /**
     * Set the properties every cohort link event shares.
     *
     * @param string $crud The CRUD letter.
     * @return void
     */
    protected function init_link_event(string $crud): void {
        $this->data['crud'] = $crud;
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_appraisal_cohort_link';
    }

    /**
     * The Appraiser cohorts page.
     *
     * @return moodle_url
     */
    public function get_url(): moodle_url {
        return new moodle_url('/local/appraisal/cohortlinks.php');
    }

    /**
     * The values the description templates take.
     *
     * @return \stdClass
     */
    protected function get_description_data(): \stdClass {
        return (object) [
            'userid' => $this->userid,
            'linkid' => $this->objectid,
            'appraisercohortid' => $this->other['appraisercohortid'],
            'appraiseecohortid' => $this->other['appraiseecohortid'],
        ];
    }

    /**
     * Validate the custom data.
     *
     * @return void
     * @throws \coding_exception
     */
    protected function validate_data(): void {
        parent::validate_data();
        foreach (['appraisercohortid', 'appraiseecohortid'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '$key' value must be set in other.");
            }
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
