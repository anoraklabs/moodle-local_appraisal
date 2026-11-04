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

use html_writer;
use local_appraisal\api;
use local_appraisal\record;
use local_appraisal\template_version;
use moodleform;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Sign-off confirmation: the overall grade, when the version has one, and the completion date (today by default, or
 * backdated).
 *
 * Custom data: 'record' (the record being signed off), 'version' (its template_version), 'levels' (the scale levels of
 * the overall grade, in order, or an empty array) and 'message' (the confirmation text, already safe to print as HTML).
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signoff_form extends moodleform {
    /**
     * Define the form.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('static', 'message', '', $this->_customdata['message']);

        /** @var template_version $version */
        $version = $this->_customdata['version'];
        $mode = $version->get('overallgrademode');
        if ($mode !== template_version::GRADE_OFF) {
            $options = ['' => get_string('choosedots')];
            $descriptions = [];
            foreach ($this->_customdata['levels'] as $level) {
                // Only the label and description are shown, never the level's numeric value.
                $options[$level->get('id')] = format_string($level->get('label'));
                if ($level->get('description') !== null && $level->get('description') !== '') {
                    $descriptions[] = html_writer::tag('dt', s($level->get('label')))
                        . html_writer::tag('dd', s($level->get('description')));
                }
            }
            $mform->addElement('select', 'levelid', get_string('overallgrade', 'local_appraisal'), $options);
            $mform->setType('levelid', PARAM_INT);
            if ($mode === template_version::GRADE_REQUIRED) {
                $mform->addRule('levelid', get_string('required'), 'required', null, 'client');
            }
            if ($descriptions) {
                $mform->addElement('static', 'leveldescriptions', '', html_writer::tag('dl', implode('', $descriptions)));
            }
        }

        $mform->addElement('date_selector', 'completiondate', get_string('completiondate', 'local_appraisal'), [
            'stopyear' => (int) userdate(time(), '%Y'),
        ]);
        $mform->setDefault('completiondate', time());
        $mform->addHelpButton('completiondate', 'completiondate', 'local_appraisal');

        $this->add_action_buttons(true, get_string('signoff', 'local_appraisal'));
    }

    /**
     * Check the overall grade and the completion date are allowed.
     *
     * @param array $data
     * @param array $files
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        /** @var record $record */
        $record = $this->_customdata['record'];
        /** @var template_version $version */
        $version = $this->_customdata['version'];
        $levelid = empty($data['levelid']) ? null : (int) $data['levelid'];
        if ($error = api::get_overall_grade_error($version, $levelid)) {
            $errors['levelid'] = get_string($error, 'local_appraisal');
        }
        $now = time();
        $completiondate = api::completion_date_from_day((int) $data['completiondate'], $now);
        if ($error = api::get_completion_date_error($record, $completiondate, $now)) {
            $errors['completiondate'] = get_string($error, 'local_appraisal');
        }
        return $errors;
    }
}
