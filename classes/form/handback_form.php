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

use local_appraisal\api;
use moodleform;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Hand a Submitted record back to the appraisee, with an optional note to them.
 *
 * Custom data: 'message' (the confirmation text, already safe to print as HTML).
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class handback_form extends moodleform {
    /**
     * Define the form.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('static', 'message', '', $this->_customdata['message']);

        $mform->addElement('textarea', 'comment', get_string('handbackcomment', 'local_appraisal'), [
            'rows' => 5,
            'cols' => 60,
            'maxlength' => api::LONGTEXT_MAXLENGTH,
        ]);
        $mform->setType('comment', PARAM_TEXT);
        $mform->addHelpButton('comment', 'handbackcomment', 'local_appraisal');

        $this->add_action_buttons(true, get_string('handback', 'local_appraisal'));
    }

    /**
     * Check the note is not too long.
     *
     * @param array $data
     * @param array $files
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (\core_text::strlen(trim($data['comment'] ?? '')) > api::LONGTEXT_MAXLENGTH) {
            $errors['comment'] = get_string('error:answertoolong', 'local_appraisal', api::LONGTEXT_MAXLENGTH);
        }
        return $errors;
    }
}
