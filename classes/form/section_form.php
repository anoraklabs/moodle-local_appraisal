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
use local_appraisal\template_section;
use local_appraisal\template_version;
use moodle_exception;
use moodle_url;

/**
 * Modal form to add or edit a section on a draft, or correct the wording of one on the published version.
 *
 * Arguments: 'versionid', and 'id' (0 to add). The version's status decides what can be edited.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class section_form extends dynamic_form {
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

        $mform->addElement('text', 'name', get_string('sectionname', 'local_appraisal'), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement(
            'editor',
            'description_editor',
            get_string('sectiondescription', 'local_appraisal'),
            null,
            ['maxfiles' => 0]
        );
        $mform->setType('description_editor', PARAM_RAW);
        $mform->addHelpButton('description_editor', 'sectiondescription', 'local_appraisal');
    }

    /**
     * Check the name isn't blank once trimmed.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (trim($data['name'] ?? '') === '') {
            $errors['name'] = get_string('required');
        } else if (\core_text::strlen(trim($data['name'])) > 255) {
            $errors['name'] = get_string('maximumchars', '', 255);
        }
        return $errors;
    }

    /**
     * The version the section belongs to.
     *
     * @return template_version
     */
    private function get_version(): template_version {
        return template_version::get_record(['id' => $this->optional_param('versionid', 0, PARAM_INT)], MUST_EXIST);
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
        $sectionid = $this->optional_param('id', 0, PARAM_INT);
        if ($version->is_draft()) {
            return;
        }
        if ($version->is_published() && $sectionid) {
            return;
        }
        throw new moodle_exception('error:notdraft', 'local_appraisal');
    }

    /**
     * Save the section.
     *
     * @return array The saved section's id.
     */
    public function process_dynamic_submission(): array {
        $data = $this->get_data();
        $name = trim($data->name);
        $description = $data->description_editor['text'];
        $format = (int) $data->description_editor['format'];

        if ($this->get_version()->is_draft()) {
            if ($data->id) {
                $this->require_section_in_version($data->id, $data->versionid);
                $section = api::update_section($data->id, $name, $description, $format);
            } else {
                $section = api::add_section($data->versionid, $name, $description, $format);
            }
        } else {
            $this->require_section_in_version($data->id, $data->versionid);
            $section = api::correct_section_wording($data->id, $name, $description, $format);
        }

        return ['id' => $section->get('id')];
    }

    /**
     * Load the existing section into the form.
     *
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        $data = [
            'id' => $this->optional_param('id', 0, PARAM_INT),
            'versionid' => $this->optional_param('versionid', 0, PARAM_INT),
        ];
        if ($data['id']) {
            $section = $this->require_section_in_version($data['id'], $data['versionid']);
            $data['name'] = $section->get('name');
            $data['description_editor'] = [
                'text' => $section->get('description') ?? '',
                'format' => $section->get('descriptionformat'),
            ];
        }
        $this->set_data($data);
    }

    /**
     * Load a section and check it belongs to the version the form was opened for.
     *
     * @param int $sectionid
     * @param int $versionid
     * @return template_section
     * @throws moodle_exception If the section is on another version.
     */
    private function require_section_in_version(int $sectionid, int $versionid): template_section {
        $section = template_section::get_record(['id' => $sectionid], MUST_EXIST);
        if ($section->get('template_versionid') !== $versionid) {
            throw new moodle_exception('error:sectionmismatch', 'local_appraisal');
        }
        return $section;
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
