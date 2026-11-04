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

namespace local_appraisal\external;

use core\context\system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_appraisal\api;

/**
 * Autosave the appraiser's overall comments on a record they own.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_comment extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'recordid' => new external_value(PARAM_INT, 'Record id'),
            'comment' => new external_value(PARAM_RAW, 'The overall comments as plain text; empty clears them'),
        ]);
    }

    /**
     * Save the comments and return the record's new status.
     *
     * @param int $recordid
     * @param string $comment
     * @return array
     */
    public static function execute(int $recordid, string $comment): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['recordid' => $recordid, 'comment' => $comment]);
        $context = system::instance();
        self::validate_context($context);
        require_capability('local/appraisal:appraise', $context);

        $record = api::save_comment($params['recordid'], $params['comment'], (int) $USER->id);

        return [
            'status' => $record->get('status'),
            'statusname' => $record->get_status_name(),
        ];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_INT, 'The record status value'),
            'statusname' => new external_value(PARAM_TEXT, 'The record status name'),
        ]);
    }
}
