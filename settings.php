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
 * Admin tree entries for local_appraisal.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$ADMIN->add('localplugins', new admin_category('local_appraisal', get_string('pluginname', 'local_appraisal')));

$settings = new admin_settingpage('local_appraisal_settings', get_string('settings'));
$ADMIN->add('local_appraisal', $settings);

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_configcheckbox(
        'local_appraisal/profileaccessenabled',
        get_string('profileaccessenabled', 'local_appraisal'),
        get_string('profileaccessenabled_desc', 'local_appraisal'),
        0
    ));

    $description = get_string('appraiserfield_desc', 'local_appraisal');
    if (\local_appraisal\access::is_appraiser_field_user_editable()) {
        $description = html_writer::div(get_string('appraiserfieldeditable', 'local_appraisal'), 'alert alert-warning')
            . $description;
    }
    $settings->add(new admin_setting_configselect(
        'local_appraisal/appraiserfield',
        get_string('appraiserfield', 'local_appraisal'),
        $description,
        '',
        \local_appraisal\access::get_appraiser_field_options()
    ));
}

$ADMIN->add('local_appraisal', new admin_externalpage(
    'local_appraisal_scales',
    get_string('gradingscales', 'local_appraisal'),
    new moodle_url('/local/appraisal/scales.php'),
    'local/appraisal:managetemplates'
));

$ADMIN->add('local_appraisal', new admin_externalpage(
    'local_appraisal_templates',
    get_string('managetemplates', 'local_appraisal'),
    new moodle_url('/local/appraisal/templates.php'),
    'local/appraisal:managetemplates'
));

$ADMIN->add('local_appraisal', new admin_externalpage(
    'local_appraisal_cohortlinks',
    get_string('appraisercohorts', 'local_appraisal'),
    new moodle_url('/local/appraisal/cohortlinks.php'),
    'local/appraisal:managecohortlinks'
));
