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
 * Modal form to link two cohorts: members of the appraiser cohort can appraise members of the appraisee cohort.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cohort_link_form extends dynamic_form {
    /**
     * Define the form.
     *
     * @return void
     */
    protected function definition(): void {
        global $DB;

        $mform = $this->_form;

        // Every cohort on the site, hidden ones included: a cohort's visibility only affects enrolment pickers.
        // The empty first option leaves both pickers unselected until the user chooses.
        $options = ['' => ''];
        foreach ($DB->get_records('cohort', null, 'name, id', 'id, name, idnumber') as $cohort) {
            $label = format_string($cohort->name);
            $options[$cohort->id] = $cohort->idnumber !== null && $cohort->idnumber !== ''
                ? $label . ' (' . s($cohort->idnumber) . ')'
                : $label;
        }

        $mform->addElement(
            'autocomplete',
            'appraisercohortid',
            get_string('appraisercohort', 'local_appraisal'),
            $options,
            ['placeholder' => get_string('choosecohort', 'local_appraisal')]
        );
        $mform->setType('appraisercohortid', PARAM_INT);
        $mform->addRule('appraisercohortid', null, 'required', null, 'client');
        $mform->addHelpButton('appraisercohortid', 'appraisercohort', 'local_appraisal');

        $mform->addElement(
            'autocomplete',
            'appraiseecohortid',
            get_string('appraiseecohort', 'local_appraisal'),
            $options,
            ['placeholder' => get_string('choosecohort', 'local_appraisal')]
        );
        $mform->setType('appraiseecohortid', PARAM_INT);
        $mform->addRule('appraiseecohortid', null, 'required', null, 'client');
        $mform->addHelpButton('appraiseecohortid', 'appraiseecohort', 'local_appraisal');
    }

    /**
     * Check the two cohorts differ and are not already linked, so the user sees the problem on the form.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $appraiser = (int) ($data['appraisercohortid'] ?? 0);
        $appraisee = (int) ($data['appraiseecohortid'] ?? 0);
        if (!$appraiser) {
            $errors['appraisercohortid'] = get_string('required');
        }
        if (!$appraisee) {
            $errors['appraiseecohortid'] = get_string('required');
        }
        if ($appraiser && $appraiser === $appraisee) {
            $errors['appraiseecohortid'] = get_string('error:selflink', 'local_appraisal');
        } else if ($appraiser && $appraisee) {
            foreach (api::get_cohort_links() as $link) {
                if ($link->get('appraisercohortid') === $appraiser && $link->get('appraiseecohortid') === $appraisee) {
                    $errors['appraiseecohortid'] = get_string('error:linkexists', 'local_appraisal');
                }
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
     * @throws moodle_exception If the user lacks managecohortlinks.
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('local/appraisal:managecohortlinks', $this->get_context_for_dynamic_submission());
    }

    /**
     * Save the link.
     *
     * @return array The new link's id.
     */
    public function process_dynamic_submission(): array {
        $data = $this->get_data();
        $link = api::create_cohort_link((int) $data->appraisercohortid, (int) $data->appraiseecohortid);
        return ['id' => $link->get('id')];
    }

    /**
     * Nothing to load: the form always adds a new link.
     *
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
    }

    /**
     * The page to return to if JavaScript is unavailable.
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/local/appraisal/cohortlinks.php');
    }
}
