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
use moodle_exception;
use moodle_url;

/**
 * Modal form to correct the overview of the published version of a template.
 *
 * Arguments: 'versionid'. A draft's overview is edited with the version settings instead.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class overview_form extends dynamic_form {
    /**
     * Define the form.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'versionid', 0);
        $mform->setType('versionid', PARAM_INT);

        $mform->addElement(
            'editor',
            'overview_editor',
            get_string('overview', 'local_appraisal'),
            null,
            ['maxfiles' => 0]
        );
        $mform->setType('overview_editor', PARAM_RAW);
        $mform->addHelpButton('overview_editor', 'overview', 'local_appraisal');
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
     * @throws moodle_exception If the version is not the published one.
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('local/appraisal:managetemplates', $this->get_context_for_dynamic_submission());

        $version = api::get_version($this->optional_param('versionid', 0, PARAM_INT));
        if (!$version->is_published()) {
            throw new moodle_exception('error:notpublished', 'local_appraisal');
        }
    }

    /**
     * Save the overview.
     *
     * @return array The version's id.
     */
    public function process_dynamic_submission(): array {
        $data = $this->get_data();
        $version = api::correct_overview(
            (int) $data->versionid,
            $data->overview_editor['text'],
            (int) $data->overview_editor['format']
        );
        return ['id' => $version->get('id')];
    }

    /**
     * Load the existing overview into the form.
     *
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        $version = api::get_version($this->optional_param('versionid', 0, PARAM_INT));
        $this->set_data([
            'versionid' => $version->get('id'),
            'overview_editor' => [
                'text' => $version->get('overview') ?? '',
                'format' => $version->get('overviewformat'),
            ],
        ]);
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
