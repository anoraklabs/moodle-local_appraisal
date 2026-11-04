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

use context;
use core\context\system;
use core_form\dynamic_form;
use local_appraisal\api;
use local_appraisal\scale_level;
use moodle_exception;
use moodle_url;

/**
 * Modal form to add or edit a level of a grading scale.
 *
 * Arguments: 'scaleid' and 'id' (0 to add). On a locked scale the value is shown but cannot be changed.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scale_level_form extends dynamic_form {
    /**
     * Define the form.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'scaleid', 0);
        $mform->setType('scaleid', PARAM_INT);

        $mform->addElement('text', 'label', get_string('levellabel', 'local_appraisal'), ['size' => 40]);
        $mform->setType('label', PARAM_TEXT);
        $mform->addRule('label', null, 'required', null, 'client');
        $mform->addRule('label', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement('text', 'value', get_string('levelvalue', 'local_appraisal'), ['size' => 10]);
        $mform->setType('value', PARAM_RAW);
        $mform->addRule('value', null, 'required', null, 'client');
        $mform->addHelpButton('value', 'levelvalue', 'local_appraisal');
        if (api::is_scale_locked($this->optional_param('scaleid', 0, PARAM_INT))) {
            $mform->freeze('value');
        }

        $mform->addElement(
            'textarea',
            'description',
            get_string('leveldescription', 'local_appraisal'),
            ['rows' => 3, 'cols' => 40]
        );
        $mform->setType('description', PARAM_TEXT);
        $mform->addHelpButton('description', 'leveldescription', 'local_appraisal');
    }

    /**
     * Check the label isn't blank and the value is a number.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (trim($data['label'] ?? '') === '') {
            $errors['label'] = get_string('required');
        } else if (\core_text::strlen(trim($data['label'])) > 255) {
            $errors['label'] = get_string('maximumchars', '', 255);
        }
        $scaleid = (int) ($data['scaleid'] ?? 0);
        if (!api::is_scale_locked($scaleid)) {
            // Strict parsing gives false for text that is not a number, and null for an empty value.
            $value = unformat_float($data['value'] ?? '', true);
            if ($value === null || $value === false) {
                $errors['value'] = get_string('err_numeric', 'form');
            } else if (!api::level_value_in_range((float) $value)) {
                $errors['value'] = get_string('error:levelvaluerange', 'local_appraisal');
            } else if (!api::level_value_fits($scaleid, (int) ($data['id'] ?? 0), (float) $value)) {
                $errors['value'] = get_string('error:levelvalueascending', 'local_appraisal');
            }
        }
        return $errors;
    }

    /**
     * The context the form runs in.
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        return system::instance();
    }

    /**
     * Check the user may use this form.
     *
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('local/appraisal:managetemplates', $this->get_context_for_dynamic_submission());
    }

    /**
     * Save the level.
     *
     * @return array The saved level's id.
     */
    public function process_dynamic_submission(): array {
        $data = $this->get_data();
        $label = trim($data->label);
        $description = trim($data->description) === '' ? null : trim($data->description);
        // A frozen value on a locked scale is not submitted, and the API ignores it there anyway.
        $value = (float) unformat_float($data->value ?? '', true);

        if ($data->id) {
            $this->require_level_in_scale((int) $data->id, (int) $data->scaleid);
            $level = api::update_scale_level((int) $data->id, $label, $value, $description);
        } else {
            $level = api::add_scale_level((int) $data->scaleid, $label, $value, $description);
        }

        return ['id' => $level->get('id')];
    }

    /**
     * Load the existing level into the form.
     *
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        $data = [
            'id' => $this->optional_param('id', 0, PARAM_INT),
            'scaleid' => $this->optional_param('scaleid', 0, PARAM_INT),
        ];
        if ($data['id']) {
            $level = $this->require_level_in_scale($data['id'], $data['scaleid']);
            $data['label'] = $level->get('label');
            $data['value'] = format_float((float) $level->get('value'), 2);
            $data['description'] = $level->get('description');
        } else {
            $levels = api::get_scale_levels($data['scaleid']);
            $highest = $levels ? (float) end($levels)->get('value') : 0.0;
            $data['value'] = format_float($levels ? floor($highest) + 1 : 1, 2);
        }
        $this->set_data($data);
    }

    /**
     * Load a level and check it belongs to the scale the form was opened for.
     *
     * @param int $levelid
     * @param int $scaleid
     * @return scale_level
     * @throws moodle_exception If the level is on another scale.
     */
    private function require_level_in_scale(int $levelid, int $scaleid): scale_level {
        $level = scale_level::get_record(['id' => $levelid], MUST_EXIST);
        if ($level->get('scaleid') !== $scaleid) {
            throw new moodle_exception('error:levelmismatch', 'local_appraisal');
        }
        return $level;
    }

    /**
     * The page to return to if JavaScript is unavailable.
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/local/appraisal/scale.php', ['id' => $this->optional_param('scaleid', 0, PARAM_INT)]);
    }
}
