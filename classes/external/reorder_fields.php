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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_appraisal\api;

/**
 * Store a new layout for a draft template version: the order of its sections and of the fields in each.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reorder_fields extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'versionid' => new external_value(PARAM_INT, 'Template version id'),
            'sections' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Section id, or 0 when the version has no sections'),
                    'fieldids' => new external_multiple_structure(
                        new external_value(PARAM_INT, 'Field id'),
                        'The fields in this section, in the new order'
                    ),
                ]),
                'Every section of the version, in the new order'
            ),
        ]);
    }

    /**
     * Reorder the sections and fields.
     *
     * @param int $versionid
     * @param array $sections Each ['id' => int, 'fieldids' => int[]].
     * @return null
     */
    public static function execute(int $versionid, array $sections): null {
        $params = self::validate_parameters(self::execute_parameters(), [
            'versionid' => $versionid,
            'sections' => $sections,
        ]);

        $context = system::instance();
        self::validate_context($context);
        require_capability('local/appraisal:managetemplates', $context);

        api::reorder_layout($params['versionid'], $params['sections']);
        return null;
    }

    /**
     * Describe the return value.
     *
     * @return null
     */
    public static function execute_returns(): null {
        return null;
    }
}
