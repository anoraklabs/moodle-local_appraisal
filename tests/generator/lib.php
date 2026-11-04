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

use local_appraisal\api;
use local_appraisal\cohort_link;
use local_appraisal\record;
use local_appraisal\scale;
use local_appraisal\template_field;
use local_appraisal\template_version;

/**
 * Data generator for local_appraisal.
 *
 * Everything goes through {@see api}, so a generated scale, template or appraisal obeys the same rules as one made on
 * the site. Get it in a test with `$this->getDataGenerator()->get_plugin_generator('local_appraisal')`.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_appraisal_generator extends component_generator_base {
    /** @var int Counter that keeps generated names unique within a test. */
    protected int $counter = 0;

    /**
     * Reset the counter between tests.
     *
     * @return void
     */
    public function reset(): void {
        $this->counter = 0;
        parent::reset();
    }

    /**
     * Create a grading scale with its levels.
     *
     * @param array $record Any of 'name', 'description' and 'levels'. Levels are label => value pairs in ascending
     *     value order and default to Low 1, Medium 2, High 3.
     * @return scale
     */
    public function create_scale(array $record = []): scale {
        $this->counter++;
        $name = $record['name'] ?? 'Scale ' . $this->counter;
        $scale = api::create_scale($name, $record['description'] ?? null);
        $levels = $record['levels'] ?? ['Low' => 1, 'Medium' => 2, 'High' => 3];
        foreach ($levels as $label => $value) {
            api::add_scale_level($scale->get('id'), (string) $label, (float) $value);
        }
        return $scale;
    }

    /**
     * Create a template with one version and its fields, published unless told otherwise.
     *
     * Each entry of 'fields' takes 'type' (a template_field TYPE_* constant, default short text), 'label',
     * 'completedby' (COMPLETEDBY_*, default appraiser), 'required' (default true), 'scaleid', 'options' (one label
     * per line, for the choice types), 'display', 'min', 'max' and 'decimals'.
     *
     * @param array $record Any of 'name', 'fields', 'overview', 'commentsmode' (template_version COMMENTS_*, default
     *     optional), 'gradingmode' (GRADE_*, default off), 'overallscaleid', 'reminderdays' and 'publish' (default
     *     true).
     * @return stdClass With template, version (the draft, or the published version) and fields (template_field
     *     objects keyed by label, in order).
     */
    public function create_template(array $record = []): stdClass {
        $this->counter++;
        $template = api::create_template($record['name'] ?? 'Template ' . $this->counter);
        $version = api::get_draft($template->get('id'));
        $versionid = $version->get('id');

        $gradingmode = $record['gradingmode'] ?? template_version::GRADE_OFF;
        api::update_version_settings(
            $versionid,
            $record['overview'] ?? null,
            FORMAT_HTML,
            $record['commentsmode'] ?? template_version::COMMENTS_OPTIONAL,
            $gradingmode,
            $gradingmode === template_version::GRADE_OFF ? null : ($record['overallscaleid'] ?? null),
            $record['reminderdays'] ?? null
        );

        $fields = [];
        foreach ($record['fields'] ?? [] as $definition) {
            $type = $definition['type'] ?? template_field::TYPE_SHORTTEXT;
            $label = $definition['label'] ?? 'Field ' . (count($fields) + 1);
            $settings = array_intersect_key($definition, array_flip(['options', 'display', 'min', 'max', 'decimals']));
            $fields[$label] = api::add_field(
                $versionid,
                $type,
                $label,
                $definition['completedby'] ?? template_field::COMPLETEDBY_APPRAISER,
                $definition['required'] ?? true,
                null,
                $definition['scaleid'] ?? null,
                template_field::build_configdata($type, $settings)
            );
        }

        if ($record['publish'] ?? true) {
            $version = api::publish_version($versionid);
        }
        return (object) ['template' => $template, 'version' => $version, 'fields' => $fields];
    }

    /**
     * Assign a published template to a user, creating the assignment and its first, Not started, record.
     *
     * @param array $record 'templateid', 'userid' (the appraisee) and 'appraiserid' (the owner) are required; 'label'
     *     and 'duedate' are optional. 'nogate' (default false) makes the record without the access gate check that
     *     api::assign_template() applies, for tests of a situation the gates would not allow, such as an owner who
     *     has since lost access; it sends no `started` message.
     * @return record
     */
    public function create_appraisal(array $record): record {
        if (!empty($record['nogate'])) {
            $assignment = api::create_assignment((int) $record['templateid'], (int) $record['userid']);
            return api::create_record(
                $assignment->get('id'),
                api::get_published_version((int) $record['templateid'])->get('id'),
                (int) $record['appraiserid'],
                $record['label'] ?? null,
                $record['duedate'] ?? null
            );
        }
        return api::assign_template(
            (int) $record['templateid'],
            (int) $record['userid'],
            (int) $record['appraiserid'],
            $record['label'] ?? null,
            $record['duedate'] ?? null
        );
    }

    /**
     * Give one user a capability at system context, or prohibit it, through a role made for the purpose.
     *
     * A prohibit beats every allow, so it removes a capability the user would otherwise inherit from the
     * authenticated user role.
     *
     * @param int $userid
     * @param string $capability For example 'local/appraisal:appraise'.
     * @param int $permission CAP_ALLOW or CAP_PROHIBIT.
     * @return int The id of the role that was created.
     */
    public function set_user_capability(int $userid, string $capability, int $permission = CAP_ALLOW): int {
        $roleid = $this->datagenerator->create_role();
        $contextid = \core\context\system::instance()->id;
        assign_capability($capability, $permission, $roleid, $contextid, true);
        role_assign($roleid, $userid, $contextid);
        return $roleid;
    }

    /**
     * Link two cohorts so members of the first can appraise members of the second.
     *
     * @param array $record 'appraisercohortid' and 'appraiseecohortid'.
     * @return cohort_link
     */
    public function create_cohort_link(array $record): cohort_link {
        return api::create_cohort_link((int) $record['appraisercohortid'], (int) $record['appraiseecohortid']);
    }
}
