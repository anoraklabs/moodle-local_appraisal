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
use local_appraisal\template_field;
use local_appraisal\template_version;
use moodle_exception;
use moodle_url;

/**
 * Modal form to add or edit a field on a draft, or correct the wording of one on the published version.
 *
 * Arguments: 'versionid', 'id' (0 to add), and optionally 'sectionid' to preselect the section of a new field.
 * The version's status decides what can be edited: everything on a draft; on the published version only the label,
 * description and option labels.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class field_form extends dynamic_form {
    /**
     * Define the form.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'versionid', 0);
        $mform->setType('versionid', PARAM_INT);

        $mform->addElement('textarea', 'label', get_string('fieldlabel', 'local_appraisal'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('label', PARAM_TEXT);
        $mform->addRule('label', null, 'required', null, 'client');
        $mform->addHelpButton('label', 'fieldlabel', 'local_appraisal');

        $mform->addElement(
            'textarea',
            'description',
            get_string('fielddescription', 'local_appraisal'),
            ['rows' => 2, 'cols' => 60]
        );
        $mform->setType('description', PARAM_TEXT);
        $mform->addHelpButton('description', 'fielddescription', 'local_appraisal');

        $version = $this->get_version();
        if ($version->is_draft()) {
            $this->add_draft_elements($version);
        } else {
            $this->add_wording_elements();
        }
    }

    /**
     * Add the elements only a draft can change: type, who completes it, required, type settings and section.
     *
     * @param template_version $version
     * @return void
     */
    private function add_draft_elements(template_version $version): void {
        $mform = $this->_form;

        $types = [];
        foreach (array_keys(template_field::TYPES) as $type) {
            $types[$type] = template_field::type_name($type);
        }
        $mform->addElement('select', 'type', get_string('fieldtype', 'local_appraisal'), $types);
        $mform->setType('type', PARAM_INT);
        $mform->setDefault('type', template_field::TYPE_SHORTTEXT);

        $mform->addElement(
            'select',
            'completedby',
            get_string('completedby', 'local_appraisal'),
            template_field::get_completedby_options()
        );
        $mform->setType('completedby', PARAM_INT);
        $mform->setDefault('completedby', template_field::COMPLETEDBY_APPRAISER);
        $mform->addHelpButton('completedby', 'completedby', 'local_appraisal');

        $mform->addElement('advcheckbox', 'required', get_string('fieldrequired', 'local_appraisal'));
        $mform->setDefault('required', 1);

        $choicetypes = [template_field::TYPE_SINGLECHOICE, template_field::TYPE_MULTICHOICE];
        $this->add_options_element();
        $mform->hideIf('options', 'type', 'in', array_diff(array_keys(template_field::TYPES), $choicetypes));

        $mform->addElement('select', 'display', get_string('displayas', 'local_appraisal'), [
            'radio' => get_string('displayradio', 'local_appraisal'),
            'select' => get_string('displayselect', 'local_appraisal'),
        ]);
        $mform->setType('display', PARAM_ALPHA);
        $mform->hideIf('display', 'type', 'noteq', template_field::TYPE_SINGLECHOICE);

        $scales = [];
        foreach (api::get_usable_scales() as $scale) {
            $scales[$scale->get('id')] = format_string($scale->get('name'));
        }
        // The scale already on the field stays selectable even if it has since been archived.
        $currentscale = $this->get_field()?->get('scaleid');
        if ($currentscale && !isset($scales[$currentscale])) {
            $scales[$currentscale] = format_string(api::get_scale($currentscale)->get('name'));
        }
        $mform->addElement(
            'select',
            'scaleid',
            get_string('gradingscale', 'local_appraisal'),
            ['' => get_string('choosedots')] + $scales
        );
        $mform->setType('scaleid', PARAM_INT);
        $mform->hideIf('scaleid', 'type', 'noteq', template_field::TYPE_SCALE);
        $mform->addHelpButton('scaleid', 'gradingscale', 'local_appraisal');

        $mform->addElement('text', 'min', get_string('numbermin', 'local_appraisal'), ['size' => 10]);
        $mform->setType('min', PARAM_RAW);
        $mform->hideIf('min', 'type', 'noteq', template_field::TYPE_NUMBER);
        $mform->addElement('text', 'max', get_string('numbermax', 'local_appraisal'), ['size' => 10]);
        $mform->setType('max', PARAM_RAW);
        $mform->hideIf('max', 'type', 'noteq', template_field::TYPE_NUMBER);
        $mform->addElement('text', 'decimals', get_string('numberdecimals', 'local_appraisal'), ['size' => 3]);
        $mform->setType('decimals', PARAM_RAW);
        $mform->hideIf('decimals', 'type', 'noteq', template_field::TYPE_NUMBER);

        $sections = [];
        foreach (api::get_sections($version->get('id')) as $section) {
            $sections[$section->get('id')] = format_string($section->get('name'));
        }
        if ($sections) {
            $mform->addElement('select', 'sectionid', get_string('section', 'local_appraisal'), $sections);
            $mform->setType('sectionid', PARAM_INT);
        }
    }

    /**
     * Add the elements shown when correcting a published field: the option labels of a choice field.
     *
     * @return void
     */
    private function add_wording_elements(): void {
        $field = $this->get_field();
        if ($field && $field->has_options()) {
            $this->add_options_element();
            $this->_form->addElement('static', 'optionsfixed', '', get_string('optionswordingonly', 'local_appraisal'));
        }
    }

    /**
     * Add the options textarea, one label per line.
     *
     * @return void
     */
    private function add_options_element(): void {
        $mform = $this->_form;
        $mform->addElement('textarea', 'options', get_string('fieldoptions', 'local_appraisal'), ['rows' => 6, 'cols' => 60]);
        $mform->setType('options', PARAM_TEXT);
        $mform->addHelpButton('options', 'fieldoptions', 'local_appraisal');
    }

    /**
     * Check the label isn't blank once trimmed, and the type settings are valid.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (trim($data['label'] ?? '') === '') {
            $errors['label'] = get_string('required');
        }

        $version = $this->get_version();
        if ($version->is_draft()) {
            $type = (int) ($data['type'] ?? 0);
            if ($type === template_field::TYPE_SCALE && empty($data['scaleid'])) {
                $errors['scaleid'] = get_string('error:scalerequired', 'local_appraisal');
            }
            if ($type === template_field::TYPE_NUMBER) {
                $errors += $this->validate_number_settings($data);
            }
            if (!$errors) {
                try {
                    template_field::build_configdata($type, $this->collect_settings($data), $this->get_existing_configdata());
                } catch (moodle_exception $e) {
                    $key = $type === template_field::TYPE_NUMBER ? 'min' : 'options';
                    $errors[$key] = $e->getMessage();
                }
            }
        } else if ($this->get_field()?->has_options() && !$errors) {
            try {
                template_field::relabel_options(
                    $this->get_existing_configdata(),
                    template_field::parse_option_labels($data['options'] ?? '')
                );
            } catch (moodle_exception $e) {
                $errors['options'] = $e->getMessage();
            }
        }
        return $errors;
    }

    /**
     * Check the number type's minimum, maximum and decimal places.
     *
     * Strict parsing gives false for text that is not a number, and null for an empty value, which is allowed here.
     *
     * @param array $data
     * @return array Errors keyed by element name.
     */
    private function validate_number_settings(array $data): array {
        $errors = [];
        foreach (['min', 'max'] as $name) {
            if (trim($data[$name] ?? '') === '') {
                continue;
            }
            $value = unformat_float($data[$name], true);
            if ($value === false || $value === null) {
                $errors[$name] = get_string('err_numeric', 'form');
            } else if (abs($value) >= 1.0E+15) {
                $errors[$name] = get_string('error:numberrange', 'local_appraisal');
            }
        }
        $decimals = trim($data['decimals'] ?? '');
        if ($decimals !== '' && (!ctype_digit($decimals) || (int) $decimals > 5)) {
            $errors['decimals'] = get_string('error:numberdecimals', 'local_appraisal');
        }
        return $errors;
    }

    /**
     * The type settings submitted, in the shape template_field::build_configdata() takes.
     *
     * @param array $data
     * @return array
     */
    private function collect_settings(array $data): array {
        $min = trim($data['min'] ?? '');
        $max = trim($data['max'] ?? '');
        return [
            'options' => $data['options'] ?? '',
            'display' => $data['display'] ?? 'radio',
            'min' => $min === '' ? null : unformat_float($min, true),
            'max' => $max === '' ? null : unformat_float($max, true),
            'decimals' => trim($data['decimals'] ?? '') === '' ? null : (int) $data['decimals'],
        ];
    }

    /**
     * The version the field belongs to.
     *
     * @return template_version
     */
    private function get_version(): template_version {
        return template_version::get_record(['id' => $this->optional_param('versionid', 0, PARAM_INT)], MUST_EXIST);
    }

    /**
     * The field being edited, or null when adding one.
     *
     * @return template_field|null
     * @throws moodle_exception If the field is on another version.
     */
    private function get_field(): ?template_field {
        $fieldid = $this->optional_param('id', 0, PARAM_INT);
        if (!$fieldid) {
            return null;
        }
        $field = template_field::get_record(['id' => $fieldid], MUST_EXIST);
        if ($field->get('template_versionid') !== $this->optional_param('versionid', 0, PARAM_INT)) {
            throw new moodle_exception('error:fieldmismatch', 'local_appraisal');
        }
        return $field;
    }

    /**
     * The configdata of the field being edited, or null when adding one.
     *
     * @return string|null
     */
    private function get_existing_configdata(): ?string {
        return $this->get_field()?->get('configdata');
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
     * @throws moodle_exception If the version can't be edited.
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('local/appraisal:managetemplates', $this->get_context_for_dynamic_submission());

        $version = $this->get_version();
        $fieldid = $this->optional_param('id', 0, PARAM_INT);
        if ($version->is_draft()) {
            return;
        }
        if ($version->is_published() && $fieldid) {
            return;
        }
        throw new moodle_exception('error:notdraft', 'local_appraisal');
    }

    /**
     * Save the field.
     *
     * @return array The saved field's id.
     */
    public function process_dynamic_submission(): array {
        $data = $this->get_data();
        $label = trim($data->label);
        $description = trim($data->description) === '' ? null : trim($data->description);

        if ($this->get_version()->is_draft()) {
            $type = (int) $data->type;
            $configdata = template_field::build_configdata(
                $type,
                $this->collect_settings((array) $data),
                $this->get_existing_configdata()
            );
            $scaleid = $type === template_field::TYPE_SCALE ? (int) $data->scaleid : null;
            $sectionid = !empty($data->sectionid) ? (int) $data->sectionid : null;
            if ($data->id) {
                $field = api::update_field(
                    (int) $data->id,
                    $type,
                    $label,
                    (int) $data->completedby,
                    !empty($data->required),
                    $sectionid,
                    $scaleid,
                    $configdata,
                    $description
                );
            } else {
                $field = api::add_field(
                    (int) $data->versionid,
                    $type,
                    $label,
                    (int) $data->completedby,
                    !empty($data->required),
                    $sectionid,
                    $scaleid,
                    $configdata,
                    $description
                );
            }
        } else {
            $optionlabels = $this->get_field()->has_options()
                ? template_field::parse_option_labels($data->options ?? '')
                : null;
            $field = api::correct_field_wording((int) $data->id, $label, $description, $optionlabels);
        }

        return ['id' => $field->get('id')];
    }

    /**
     * Load the existing field into the form.
     *
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        $data = [
            'id' => $this->optional_param('id', 0, PARAM_INT),
            'versionid' => $this->optional_param('versionid', 0, PARAM_INT),
        ];
        $field = $this->get_field();
        if ($field) {
            $data['label'] = $field->get('label');
            $data['description'] = $field->get('description');
            $data['type'] = $field->get('type');
            $data['completedby'] = $field->get('completedby');
            $data['required'] = (int) $field->get('required');
            $data['sectionid'] = $field->get('sectionid');
            $data['scaleid'] = $field->get('scaleid');
            $config = $field->get_config();
            if ($field->has_options()) {
                $data['options'] = implode("\n", $field->get_options());
                $data['display'] = $config['display'] ?? 'radio';
            }
            foreach (['min', 'max', 'decimals'] as $name) {
                if (isset($config[$name])) {
                    $data[$name] = $name === 'decimals' ? (string) $config[$name] : format_float((float) $config[$name], -1);
                }
            }
        } else {
            // The section whose Add field button opened the form.
            $data['sectionid'] = $this->optional_param('sectionid', 0, PARAM_INT) ?: null;
        }
        $this->set_data($data);
    }

    /**
     * The page to return to if JavaScript is unavailable.
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/local/appraisal/templateversion.php', [
            'id' => $this->optional_param('versionid', 0, PARAM_INT),
        ]);
    }
}
