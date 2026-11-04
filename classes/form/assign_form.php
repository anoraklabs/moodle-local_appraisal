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
 * Pick a template to assign to a user, with an optional cycle label and due date.
 *
 * Custom data: 'userid' and 'templates' (id => name of assignable templates).
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_form extends moodleform {
    /**
     * Define the form.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'userid', $this->_customdata['userid']);
        $mform->setType('userid', PARAM_INT);

        $mform->addElement('select', 'templateid', get_string('template', 'local_appraisal'), $this->_customdata['templates']);
        $mform->setType('templateid', PARAM_INT);
        $mform->addRule('templateid', null, 'required', null, 'client');

        $mform->addElement('text', 'label', get_string('cyclelabel', 'local_appraisal'), ['size' => 40]);
        $mform->setType('label', PARAM_TEXT);
        $mform->addRule('label', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addHelpButton('label', 'cyclelabel', 'local_appraisal');

        $mform->addElement('date_selector', 'duedate', get_string('duedate', 'local_appraisal'), ['optional' => true]);
        $mform->addHelpButton('duedate', 'duedate', 'local_appraisal');

        $this->add_action_buttons(true, get_string('assigntemplate', 'local_appraisal'));
    }

    /**
     * Check the template is still assignable and the label is not too long.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (!isset($this->_customdata['templates'][$data['templateid'] ?? 0])) {
            $errors['templateid'] = get_string('error:cannotassign', 'local_appraisal');
        }
        if (\core_text::strlen(trim($data['label'] ?? '')) > 255) {
            $errors['label'] = get_string('maximumchars', '', 255);
        }
        return $errors;
    }
}
