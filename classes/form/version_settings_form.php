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
use local_appraisal\template_version;
use moodleform;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Overview, overall comments, overall grade and due date reminder settings for a draft version.
 *
 * Custom data: 'currentscaleid' (int|null) the overall grade scale already saved on the version, which stays
 * selectable even if it has since been archived.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class version_settings_form extends moodleform {
    /**
     * Define the form.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        $mform->addElement(
            'editor',
            'overview_editor',
            get_string('overview', 'local_appraisal'),
            null,
            ['maxfiles' => 0]
        );
        $mform->setType('overview_editor', PARAM_RAW);
        $mform->addHelpButton('overview_editor', 'overview', 'local_appraisal');

        $mform->addElement(
            'select',
            'commentsmode',
            get_string('commentsmode', 'local_appraisal'),
            template_version::get_comments_mode_options()
        );
        $mform->setType('commentsmode', PARAM_INT);
        $mform->setDefault('commentsmode', template_version::COMMENTS_OPTIONAL);
        $mform->addHelpButton('commentsmode', 'commentsmode', 'local_appraisal');

        $mform->addElement(
            'select',
            'overallgrademode',
            get_string('overallgrademode', 'local_appraisal'),
            template_version::get_grade_mode_options()
        );
        $mform->setType('overallgrademode', PARAM_INT);
        $mform->setDefault('overallgrademode', template_version::GRADE_OFF);
        $mform->addHelpButton('overallgrademode', 'overallgrademode', 'local_appraisal');

        $scales = [];
        foreach (api::get_usable_scales() as $scale) {
            $scales[$scale->get('id')] = format_string($scale->get('name'));
        }
        $currentscaleid = $this->_customdata['currentscaleid'] ?? null;
        if ($currentscaleid && !isset($scales[$currentscaleid])) {
            $scales[$currentscaleid] = format_string(api::get_scale($currentscaleid)->get('name'));
        }
        $mform->addElement(
            'select',
            'overallscaleid',
            get_string('overallscale', 'local_appraisal'),
            ['' => get_string('choosedots')] + $scales
        );
        $mform->setType('overallscaleid', PARAM_INT);
        $mform->hideIf('overallscaleid', 'overallgrademode', 'eq', template_version::GRADE_OFF);
        $mform->addHelpButton('overallscaleid', 'overallscale', 'local_appraisal');

        $mform->addElement('advcheckbox', 'sendreminder', get_string('sendreminder', 'local_appraisal'));
        $mform->addElement('text', 'reminderdays', get_string('reminderdays', 'local_appraisal'), ['size' => 4]);
        $mform->setType('reminderdays', PARAM_INT);
        $mform->hideIf('reminderdays', 'sendreminder', 'notchecked');
        $mform->addHelpButton('reminderdays', 'reminderdays', 'local_appraisal');

        $this->add_action_buttons(false, get_string('savesettings', 'local_appraisal'));
    }

    /**
     * The HTML id of the form element, so a button elsewhere on the page can submit it with the form attribute.
     *
     * @return string
     */
    public function get_html_id(): string {
        return (string) $this->_form->getAttribute('id');
    }

    /**
     * Validate the settings.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if ((int) $data['overallgrademode'] !== template_version::GRADE_OFF && empty($data['overallscaleid'])) {
            $errors['overallscaleid'] = get_string('error:overallscalerequired', 'local_appraisal');
        }
        if (!empty($data['sendreminder']) && (int) ($data['reminderdays'] ?? 0) < 1) {
            $errors['reminderdays'] = get_string('error:reminderdayspositive', 'local_appraisal');
        }

        return $errors;
    }
}
