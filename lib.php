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
 * Callbacks for local_appraisal.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_user\output\myprofile\category;
use core_user\output\myprofile\node;
use core_user\output\myprofile\tree;
use local_appraisal\access;

/**
 * Add the plugin's entry points to a user's own profile page.
 *
 * @param tree $tree
 * @param stdClass $user The user whose profile is shown.
 * @param bool $iscurrentuser Whether that is the viewer.
 * @param stdClass|null $course
 * @return bool
 */
function local_appraisal_myprofile_navigation(tree $tree, stdClass $user, bool $iscurrentuser, ?stdClass $course): bool {
    if (!$iscurrentuser) {
        return false;
    }

    $nodes = [];
    if (has_capability('local/appraisal:completeown', \core\context\system::instance())) {
        $nodes[] = new node(
            'local_appraisal',
            'myappraisals',
            get_string('myappraisals', 'local_appraisal'),
            null,
            new moodle_url('/local/appraisal/dashboard.php', ['userid' => $user->id])
        );
    }
    if (access::is_appraiser((int) $user->id)) {
        $nodes[] = new node(
            'local_appraisal',
            'peopleiappraise',
            get_string('peopleiappraise', 'local_appraisal'),
            null,
            new moodle_url('/local/appraisal/people.php')
        );
    }
    if (!$nodes) {
        return false;
    }

    $tree->add_category(new category('local_appraisal', get_string('pluginname', 'local_appraisal'), 'contact'));
    foreach ($nodes as $node) {
        $tree->add_node($node);
    }
    return true;
}
