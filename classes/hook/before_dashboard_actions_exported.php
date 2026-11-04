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

/**
 * Hook: add actions to one person's dashboard.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_appraisal\hook;

use local_appraisal\local\collects_actions;

/**
 * Lets plugins add actions to one person's appraisal dashboard, for example to export their history.
 *
 * Subscribers call add_action() to put a link beside the dashboard's own actions. The plugin ships no
 * subscribers; this is an extension point for other plugins. Once released, the constructor arguments and
 * the methods of collects_actions are public API.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Lets plugins add actions to one person\'s appraisal dashboard.')]
#[\core\attribute\tags('local_appraisal')]
final class before_dashboard_actions_exported {
    use collects_actions;

    /**
     * Create the hook.
     *
     * @param int $appraiseeid The id of the person whose dashboard it is.
     */
    public function __construct(
        /** @var int The id of the person whose dashboard it is. */
        public readonly int $appraiseeid,
    ) {
    }
}
