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

/**
 * Behat data generator for local_appraisal.
 *
 * @package    local_appraisal
 * @category   test
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_appraisal\api;
use local_appraisal\scale;
use local_appraisal\template;
use local_appraisal\template_field;
use local_appraisal\template_version;

/**
 * Behat data generator for local_appraisal.
 *
 * Supports steps of the form: Given the following "local_appraisal > [entity]" exist:, where the entity is scales,
 * templates, appraisals or cohortlinks. Everything is created through the plugin's own data generator, so it obeys the
 * same rules as data made on the site.
 *
 * @package    local_appraisal
 * @category   test
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_appraisal_generator extends behat_generator_base {
    /**
     * The entities that can be created from a Behat table.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'scales' => [
                'singular' => 'scale',
                'datagenerator' => 'scale',
                'required' => ['name'],
            ],
            'templates' => [
                'singular' => 'template',
                'datagenerator' => 'template',
                'required' => ['name'],
            ],
            'appraisals' => [
                'singular' => 'appraisal',
                'datagenerator' => 'appraisal',
                'required' => ['template', 'appraisee', 'appraiser'],
            ],
            'cohortlinks' => [
                'singular' => 'cohortlink',
                'datagenerator' => 'cohort_link',
                'required' => ['appraisercohort', 'appraiseecohort'],
            ],
        ];
    }

    /**
     * Create a grading scale with its levels.
     *
     * Columns: name (required); levels, the level labels from lowest to highest separated by semicolons (default
     * "Needs improvement;Meets expectations;Exceeds expectations"), given the values 1, 2, 3 and so on.
     *
     * @param array $data One row of the Behat table.
     * @return void
     */
    protected function process_scale(array $data): void {
        $labels = $this->split_list($data['levels'] ?? '');
        if (!$labels) {
            $labels = ['Needs improvement', 'Meets expectations', 'Exceeds expectations'];
        }
        $levels = [];
        foreach (array_values($labels) as $position => $label) {
            $levels[$label] = $position + 1;
        }
        $this->componentdatagenerator->create_scale(['name' => $data['name'], 'levels' => $levels]);
    }

    /**
     * Create a published template, version 1, with its fields.
     *
     * Columns: name (required); fields, the labels of short text fields completed by the appraiser, separated by
     * semicolons (default one field, unless appraiseefields is given); appraiseefields, the labels of short text fields
     * completed by the appraisee, which come first; scale, the name of a grading scale, which adds a required graded
     * rating field "Overall performance" on it, completed by the appraiser; comments, the overall comments setting (off,
     * optional or required; default optional); overallgrade (off, optional or required; default off) with overallscale,
     * the name of the scale it uses; reminderdays, how many days before the due date the reminder goes out (default none).
     *
     * @param array $data One row of the Behat table.
     * @return void
     * @throws Exception If the scale does not exist, or a setting is not recognised.
     */
    protected function process_template(array $data): void {
        $fields = [];
        foreach ($this->split_list($data['appraiseefields'] ?? '') as $label) {
            $fields[] = [
                'type' => template_field::TYPE_SHORTTEXT,
                'label' => $label,
                'completedby' => template_field::COMPLETEDBY_APPRAISEE,
            ];
        }
        $labels = $this->split_list($data['fields'] ?? '');
        if (!$labels && !$fields) {
            $labels = ['What went well this year?'];
        }
        foreach ($labels as $label) {
            $fields[] = ['type' => template_field::TYPE_SHORTTEXT, 'label' => $label];
        }

        $scalename = trim($data['scale'] ?? '');
        if ($scalename !== '') {
            $fields[] = [
                'type' => template_field::TYPE_SCALE,
                'label' => 'Overall performance',
                'scaleid' => $this->find_scale_id($scalename),
            ];
        }

        $record = [
            'name' => $data['name'],
            'fields' => $fields,
            'commentsmode' => $this->get_mode(
                $data['comments'] ?? 'optional',
                [
                    'off' => template_version::COMMENTS_OFF,
                    'optional' => template_version::COMMENTS_OPTIONAL,
                    'required' => template_version::COMMENTS_REQUIRED,
                ]
            ),
            'gradingmode' => $this->get_mode(
                $data['overallgrade'] ?? 'off',
                [
                    'off' => template_version::GRADE_OFF,
                    'optional' => template_version::GRADE_OPTIONAL,
                    'required' => template_version::GRADE_REQUIRED,
                ]
            ),
        ];
        if (trim($data['reminderdays'] ?? '') !== '') {
            $record['reminderdays'] = (int) $data['reminderdays'];
        }
        $overallscale = trim($data['overallscale'] ?? '');
        if ($overallscale !== '') {
            $record['overallscaleid'] = $this->find_scale_id($overallscale);
        }

        $this->componentdatagenerator->create_template($record);
    }

    /**
     * Assign a published template to a user, which starts their first, Not started, appraisal.
     *
     * Columns: template (required), the template's name; appraisee (required), a username; appraiser (required), the
     * username of the owner; label, the cycle label; duedate, a date in words that PHP's strtotime() understands, such as
     * "+5 days", which becomes the end of that day in the site timezone. It goes through api::assign_template(), so
     * the appraiser needs an access gate to the appraisee, such as a cohort link.
     *
     * @param array $data One row of the Behat table.
     * @return void
     * @throws Exception If the template or a user does not exist, or the due date cannot be read.
     */
    protected function process_appraisal(array $data): void {
        global $DB;

        $template = template::get_record(['name' => $data['template']]);
        if (!$template) {
            throw new Exception('There is no local_appraisal template named "' . $data['template'] . '".');
        }
        $duedate = null;
        if (trim($data['duedate'] ?? '') !== '') {
            $day = strtotime($data['duedate']);
            if ($day === false) {
                throw new Exception('Unrecognised due date "' . $data['duedate'] . '".');
            }
            $duedate = api::due_date_from_day($day);
        }
        $this->componentdatagenerator->create_appraisal([
            'templateid' => $template->get('id'),
            'userid' => $DB->get_field('user', 'id', ['username' => $data['appraisee']], MUST_EXIST),
            'appraiserid' => $DB->get_field('user', 'id', ['username' => $data['appraiser']], MUST_EXIST),
            'label' => $data['label'] ?? null,
            'duedate' => $duedate,
        ]);
    }

    /**
     * Link two cohorts, so members of the first can appraise members of the second.
     *
     * Columns: appraisercohort and appraiseecohort, each the ID number of a cohort.
     *
     * @param array $data One row of the Behat table.
     * @return void
     */
    protected function process_cohort_link(array $data): void {
        global $DB;

        $this->componentdatagenerator->create_cohort_link([
            'appraisercohortid' => $DB->get_field('cohort', 'id', ['idnumber' => $data['appraisercohort']], MUST_EXIST),
            'appraiseecohortid' => $DB->get_field('cohort', 'id', ['idnumber' => $data['appraiseecohort']], MUST_EXIST),
        ]);
    }

    /**
     * The ID of a grading scale, from its name.
     *
     * @param string $name The scale's name.
     * @return int
     * @throws Exception If there is no such scale.
     */
    protected function find_scale_id(string $name): int {
        $scale = scale::get_record(['name' => $name]);
        if (!$scale) {
            throw new Exception('There is no local_appraisal grading scale named "' . $name . '".');
        }
        return $scale->get('id');
    }

    /**
     * Turn a mode word from a Behat table into its constant.
     *
     * @param string $word The word, for example "required".
     * @param int[] $modes The constants keyed by word.
     * @return int
     * @throws Exception If the word is not one of the keys.
     */
    protected function get_mode(string $word, array $modes): int {
        $word = strtolower(trim($word));
        if (!isset($modes[$word])) {
            throw new Exception('Unrecognised setting "' . $word . '", use one of: ' . implode(', ', array_keys($modes)) . '.');
        }
        return $modes[$word];
    }

    /**
     * Split a semicolon-separated cell into trimmed, non-empty items.
     *
     * @param string $value The cell.
     * @return string[]
     */
    protected function split_list(string $value): array {
        return array_values(array_filter(array_map('trim', explode(';', $value)), fn(string $item): bool => $item !== ''));
    }
}
