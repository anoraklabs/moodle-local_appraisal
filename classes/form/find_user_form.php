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

namespace local_appraisal\form;

use moodleform;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Search for any user and go to their dashboard (accessalldashboards holders only).
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class find_user_form extends moodleform {
    /**
     * Define the form.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('autocomplete', 'userid', get_string('findauser', 'local_appraisal'), [], [
            'ajax' => 'local_appraisal/user_selector',
            'multiple' => false,
            'noselectionstring' => get_string('findauser_placeholder', 'local_appraisal'),
            'valuehtmlcallback' => function (int|string $value): string {
                $user = \core_user::get_user($value);
                return $user ? s(fullname($user)) : '';
            },
        ]);
        $mform->setType('userid', PARAM_INT);

        $this->add_action_buttons(false, get_string('opendashboard', 'local_appraisal'));
    }
}
