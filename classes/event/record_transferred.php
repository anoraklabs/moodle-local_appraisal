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
 * An appraiser took ownership of an appraisal cycle.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class record_transferred extends record_event {
    /**
     * Set the event properties.
     *
     * @return void
     */
    protected function init(): void {
        parent::init();
        $this->data['crud'] = 'u';
    }

    /**
     * The event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event:recordtransferred', 'local_appraisal');
    }

    /**
     * Describe what happened.
     *
     * @return string
     */
    public function get_description(): string {
        return $this->describe('event:recordtransferred_desc');
    }
}
