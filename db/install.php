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
 * Post-installation steps for local_appraisal.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Creates the Appraisal administrator role and grants it access to every user's dashboard.
 *
 * @return bool
 */
function xmldb_local_appraisal_install(): bool {
    global $DB;

    // Moodle registers plugin capabilities after this function runs; assign_capability() needs them now.
    update_capabilities('local_appraisal');

    // The role survives an uninstall, so a reinstall reuses it rather than failing on the duplicate shortname.
    $roleid = $DB->get_field('role', 'id', ['shortname' => 'appraisaladmin']);
    if (!$roleid) {
        $roleid = create_role(
            get_string('appraisaladminrole', 'local_appraisal'),
            'appraisaladmin',
            get_string('appraisaladminroledescription', 'local_appraisal')
        );
    }

    set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
    assign_capability(
        'local/appraisal:accessalldashboards',
        CAP_ALLOW,
        $roleid,
        \core\context\system::instance()->id,
        true
    );

    return true;
}
