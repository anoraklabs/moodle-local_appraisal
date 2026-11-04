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

/**
 * A cohort link was added, so members of one cohort can appraise members of another.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cohort_link_created extends cohort_link_event {
    /**
     * Set the event properties.
     *
     * @return void
     */
    protected function init(): void {
        $this->init_link_event('c');
    }

    /**
     * The event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event:cohortlinkcreated', 'local_appraisal');
    }

    /**
     * Describe what happened.
     *
     * @return string
     */
    public function get_description(): string {
        return get_string('event:cohortlinkcreated_desc', 'local_appraisal', $this->get_description_data());
    }
}
