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
use core_user\fields;

/**
 * Find a user to appraise: name and identity field search for accessalldashboards holders.
 *
 * Excludes deleted and suspended users, the guest user and the searcher.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_users extends external_api {
    /** @var int Most results returned for one query. */
    const LIMIT = 30;

    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_RAW, 'Text to search names and visible identity fields for'),
        ]);
    }

    /**
     * Search.
     *
     * @param string $query
     * @return array
     */
    public static function execute(string $query): array {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/lib/datalib.php');

        $params = self::validate_parameters(self::execute_parameters(), ['query' => $query]);
        $context = system::instance();
        self::validate_context($context);
        require_capability('local/appraisal:accessalldashboards', $context);

        $query = trim($params['query']);
        if ($query === '') {
            return [];
        }

        $identityfields = fields::for_identity($context, false)->get_required_fields();
        $namefields = fields::for_name()->get_required_fields();
        [$where, $sqlparams] = users_search_sql(
            $query,
            'u',
            USER_SEARCH_CONTAINS,
            $identityfields,
            [$USER->id, $CFG->siteguest]
        );
        $where .= ' AND u.suspended = 0';
        $selectfields = implode(', ', array_unique(array_merge(
            ['u.id', 'u.username'],
            array_map(fn(string $f): string => "u.{$f}", array_merge($namefields, $identityfields))
        )));
        $users = $DB->get_records_sql("SELECT {$selectfields}
                                         FROM {user} u
                                        WHERE {$where}
                                     ORDER BY u.lastname, u.firstname", $sqlparams, 0, self::LIMIT);

        $results = [];
        foreach ($users as $user) {
            $extra = [];
            foreach ($identityfields as $field) {
                if (!empty($user->$field)) {
                    $extra[] = $user->$field;
                }
            }
            $results[] = [
                'id' => (int) $user->id,
                'fullname' => fullname($user),
                'extra' => implode(', ', $extra),
            ];
        }
        return $results;
    }

    /**
     * Describe the return value.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'id' => new external_value(PARAM_INT, 'User id'),
            'fullname' => new external_value(PARAM_TEXT, 'Full name'),
            'extra' => new external_value(PARAM_TEXT, 'Identity fields the viewer may see, comma separated'),
        ]));
    }
}
