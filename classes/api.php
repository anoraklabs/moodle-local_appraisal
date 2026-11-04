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

namespace local_appraisal;

use core\clock;
use core\context\system;
use core\di;
use moodle_exception;

/**
 * Business rules for the plugin. Pages and web services call this rather than the persistent classes directly.
 *
 * Phase 1 holds the skeleton: creating and reading back each entity. The rules of later phases (locking,
 * transitions, access, visibility) are added here as the phases that need them are built.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {
    /** @var int The longest short text answer, in characters. */
    const SHORTTEXT_MAXLENGTH = 255;
    /** @var int The longest long text answer or overall comment, in characters. */
    const LONGTEXT_MAXLENGTH = 5000;
    /** @var int The lowest value a grading scale level can have. */
    const LEVEL_VALUE_MIN = 0;
    /** @var int The highest value a grading scale level can have. */
    const LEVEL_VALUE_MAX = 1000;

    /**
     * The user id left behind when a privacy request anonymises an appraiser or actor.
     *
     * Stored in the database - DO NOT CHANGE. Zero is used deliberately, since no real user id takes it.
     */
    const APPRAISER_DELETED = 0;

    /**
     * Create a grading scale.
     *
     * @param string $name
     * @param string|null $description
     * @return scale
     */
    public static function create_scale(string $name, ?string $description = null): scale {
        $scale = new scale(0, (object) [
            'name' => $name,
            'description' => $description,
            'status' => scale::STATUS_ACTIVE,
        ]);
        return $scale->create();
    }

    /**
     * Read a grading scale.
     *
     * @param int $scaleid
     * @return scale
     */
    public static function get_scale(int $scaleid): scale {
        return scale::get_record(['id' => $scaleid], MUST_EXIST);
    }

    /**
     * Change a scale's name and description. Wording can always be corrected, even on a locked scale.
     *
     * @param int $scaleid
     * @param string $name
     * @param string|null $description
     * @return scale
     */
    public static function update_scale(int $scaleid, string $name, ?string $description = null): scale {
        $scale = self::get_scale($scaleid);
        $scale->set('name', $name);
        $scale->set('description', $description);
        $scale->update();
        return $scale;
    }

    /**
     * Archive a scale: it can't be chosen for new fields or overall grades, but versions already using it keep working.
     *
     * @param int $scaleid
     * @return void
     */
    public static function archive_scale(int $scaleid): void {
        $scale = self::get_scale($scaleid);
        $scale->set('status', scale::STATUS_ARCHIVED);
        $scale->update();
    }

    /**
     * Set an archived scale back to active.
     *
     * @param int $scaleid
     * @return void
     */
    public static function unarchive_scale(int $scaleid): void {
        $scale = self::get_scale($scaleid);
        $scale->set('status', scale::STATUS_ACTIVE);
        $scale->update();
    }

    /**
     * Copy a scale and its levels into a new, unlocked, active scale.
     *
     * @param int $scaleid
     * @param string $newname
     * @return scale
     */
    public static function duplicate_scale(int $scaleid, string $newname): scale {
        global $DB;

        $source = self::get_scale($scaleid);
        $transaction = $DB->start_delegated_transaction();
        $copy = self::create_scale(\core_text::substr($newname, 0, 255), $source->get('description'));
        foreach (self::get_scale_levels($scaleid) as $level) {
            $clone = new scale_level(0, (object) [
                'scaleid' => $copy->get('id'),
                'sortorder' => $level->get('sortorder'),
                'label' => $level->get('label'),
                'value' => $level->get('value'),
                'description' => $level->get('description'),
            ]);
            $clone->create();
        }
        $transaction->allow_commit();

        return $copy;
    }

    /**
     * Delete a scale that nothing uses, with its levels.
     *
     * @param int $scaleid
     * @return void
     * @throws moodle_exception If a template version uses the scale.
     */
    public static function delete_scale(int $scaleid): void {
        global $DB;

        $scale = self::get_scale($scaleid);
        if (self::get_scale_usage($scaleid)[$scaleid]->templates ?? 0) {
            throw new moodle_exception('error:scaleinuse', 'local_appraisal');
        }
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records(scale_level::TABLE, ['scaleid' => $scaleid]);
        $scale->delete();
        $transaction->allow_commit();
    }

    /**
     * How many templates use each scale, and whether a published or archived version does (which locks the scale).
     *
     * A scale is used through a graded-rating field or as a version's overall-grade scale, in any version.
     *
     * @param int|null $scaleid Limit the result to one scale, or null for every scale in use.
     * @return \stdClass[] Keyed by scale id, each with scaleid, templates (count) and locked (0 or 1).
     */
    public static function get_scale_usage(?int $scaleid = null): array {
        global $DB;

        $params = [
            'draftfield' => template_version::STATUS_DRAFT,
            'draftoverall' => template_version::STATUS_DRAFT,
            'scalefilter1' => (int) ($scaleid !== null),
            'scalefilter2' => (int) ($scaleid !== null),
            'scaleid' => (int) $scaleid,
        ];
        $sql = "SELECT u.scaleid,
                       COUNT(DISTINCT u.templateid) AS templates,
                       MAX(u.locked) AS locked
                  FROM (SELECT f.scaleid,
                               v.templateid,
                               CASE WHEN v.status <> :draftfield THEN 1 ELSE 0 END AS locked
                          FROM {" . template_field::TABLE . "} f
                          JOIN {" . template_version::TABLE . "} v ON v.id = f.template_versionid
                         WHERE f.scaleid IS NOT NULL
                         UNION ALL
                        SELECT v.overallscaleid AS scaleid,
                               v.templateid,
                               CASE WHEN v.status <> :draftoverall THEN 1 ELSE 0 END AS locked
                          FROM {" . template_version::TABLE . "} v
                         WHERE v.overallscaleid IS NOT NULL) u
                 WHERE (:scalefilter1 = 1 AND u.scaleid = :scaleid) OR :scalefilter2 = 0
              GROUP BY u.scaleid";
        $usage = $DB->get_records_sql($sql, $params);
        foreach ($usage as $row) {
            $row->templates = (int) $row->templates;
            $row->locked = (int) $row->locked;
        }
        return $usage;
    }

    /**
     * Whether a published or archived template version uses the scale, so its levels must stay as they are.
     *
     * A locked scale allows only wording corrections: its name and description, and each level's label and description.
     *
     * @param int $scaleid
     * @return bool
     */
    public static function is_scale_locked(int $scaleid): bool {
        return !empty(self::get_scale_usage($scaleid)[$scaleid]->locked);
    }

    /**
     * The scales that can be chosen for a field or an overall grade: active, with at least two levels.
     *
     * @return scale[] Keyed by scale id, ordered by name.
     */
    public static function get_usable_scales(): array {
        $select = 'status = :active AND (SELECT COUNT(1)
                                           FROM {' . scale_level::TABLE . '} l
                                          WHERE l.scaleid = {' . scale::TABLE . '}.id) >= 2';
        return scale::get_records_select($select, ['active' => scale::STATUS_ACTIVE], 'name');
    }

    /**
     * Add a level at the top of a scale.
     *
     * @param int $scaleid
     * @param string $label
     * @param float $value Must be above the scale's current highest value.
     * @param string|null $description
     * @return scale_level
     * @throws moodle_exception If the scale is locked, or the value is out of range or does not keep the values ascending.
     */
    public static function add_scale_level(
        int $scaleid,
        string $label,
        float $value,
        ?string $description = null
    ): scale_level {
        global $DB;

        self::get_scale($scaleid);
        if (self::is_scale_locked($scaleid)) {
            throw new moodle_exception('error:scalelocked', 'local_appraisal');
        }
        if (!self::level_value_in_range($value)) {
            throw new moodle_exception('error:levelvaluerange', 'local_appraisal');
        }
        if (!self::level_value_fits($scaleid, 0, $value)) {
            throw new moodle_exception('error:levelvalueascending', 'local_appraisal');
        }
        $sortorder = (int) $DB->get_field(scale_level::TABLE, 'MAX(sortorder)', ['scaleid' => $scaleid]) + 1;
        $level = new scale_level(0, (object) [
            'scaleid' => $scaleid,
            'sortorder' => $sortorder,
            'label' => $label,
            'value' => $value,
            'description' => $description,
        ]);
        return $level->create();
    }

    /**
     * Edit a level. On a locked scale only the label and description change; the value passed is ignored.
     *
     * @param int $levelid
     * @param string $label
     * @param float $value Must sit between its neighbours' values.
     * @param string|null $description
     * @return scale_level
     * @throws moodle_exception If the value is out of range or does not keep the values strictly ascending.
     */
    public static function update_scale_level(
        int $levelid,
        string $label,
        float $value,
        ?string $description = null
    ): scale_level {
        $level = scale_level::get_record(['id' => $levelid], MUST_EXIST);
        $scaleid = $level->get('scaleid');
        if (!self::is_scale_locked($scaleid)) {
            if (!self::level_value_in_range($value)) {
                throw new moodle_exception('error:levelvaluerange', 'local_appraisal');
            }
            if (!self::level_value_fits($scaleid, $levelid, $value)) {
                throw new moodle_exception('error:levelvalueascending', 'local_appraisal');
            }
            $level->set('value', $value);
        }
        $level->set('label', $label);
        $level->set('description', $description);
        $level->update();
        return $level;
    }

    /**
     * Delete a level from an unlocked scale and close the gap in the ordering.
     *
     * @param int $levelid
     * @return void
     * @throws moodle_exception If the scale is locked.
     */
    public static function delete_scale_level(int $levelid): void {
        global $DB;

        $level = scale_level::get_record(['id' => $levelid], MUST_EXIST);
        $scaleid = $level->get('scaleid');
        if (self::is_scale_locked($scaleid)) {
            throw new moodle_exception('error:scalelocked', 'local_appraisal');
        }
        $transaction = $DB->start_delegated_transaction();
        $level->delete();
        foreach (array_values(self::get_scale_levels($scaleid)) as $index => $other) {
            if ($other->get('sortorder') !== $index + 1) {
                $other->set('sortorder', $index + 1);
                $other->update();
            }
        }
        $transaction->allow_commit();
    }

    /**
     * Store a new order for a scale's levels, keeping the values strictly ascending.
     *
     * The scale's existing values are dealt out again, in ascending order, to the levels in their new order, so a
     * level that moves takes on the value of its new position.
     *
     * @param int $scaleid
     * @param int[] $levelids Every level of the scale, lowest first.
     * @return void
     * @throws moodle_exception If the scale is locked or the ids are not exactly the scale's levels.
     */
    public static function reorder_levels(int $scaleid, array $levelids): void {
        global $DB;

        self::get_scale($scaleid);
        if (self::is_scale_locked($scaleid)) {
            throw new moodle_exception('error:scalelocked', 'local_appraisal');
        }
        $levels = self::get_scale_levels($scaleid);
        $levelids = array_map('intval', $levelids);
        if (!self::same_ids($levelids, array_keys($levels))) {
            throw new moodle_exception('error:levelmismatch', 'local_appraisal');
        }
        $values = array_map(fn(scale_level $level): float => (float) $level->get('value'), array_values($levels));
        sort($values);

        $transaction = $DB->start_delegated_transaction();
        foreach ($levelids as $index => $levelid) {
            $level = $levels[$levelid];
            $level->set('sortorder', $index + 1);
            $level->set('value', $values[$index]);
            $level->update();
        }
        $transaction->allow_commit();
    }

    /**
     * Whether a value lies within the range a level's value may take.
     *
     * @param float $value
     * @return bool
     */
    public static function level_value_in_range(float $value): bool {
        $value = round($value, 2);
        return $value >= self::LEVEL_VALUE_MIN && $value <= self::LEVEL_VALUE_MAX;
    }

    /**
     * Whether a value keeps a scale's values strictly ascending when given to a level.
     *
     * @param int $scaleid
     * @param int $levelid The level being edited, or 0 for a new level that would go at the top.
     * @param float $value
     * @return bool
     */
    public static function level_value_fits(int $scaleid, int $levelid, float $value): bool {
        $value = round($value, 2);
        $position = $levelid ? scale_level::get_record(['id' => $levelid], MUST_EXIST)->get('sortorder') : PHP_INT_MAX;
        foreach (self::get_scale_levels($scaleid) as $other) {
            $othervalue = round((float) $other->get('value'), 2);
            $otherposition = $other->get('sortorder');
            if ($other->get('id') === $levelid) {
                continue;
            }
            if (($otherposition < $position && $othervalue >= $value) || ($otherposition > $position && $othervalue <= $value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a submitted list of ids is exactly the expected set, with each id once.
     *
     * @param int[] $submitted
     * @param int[] $expected
     * @return bool
     */
    private static function same_ids(array $submitted, array $expected): bool {
        return count($submitted) === count($expected)
            && count(array_unique($submitted)) === count($submitted)
            && !array_diff($submitted, $expected)
            && !array_diff($expected, $submitted);
    }

    /**
     * Read the levels of a scale, lowest first.
     *
     * @param int $scaleid
     * @return scale_level[]
     */
    public static function get_scale_levels(int $scaleid): array {
        return scale_level::get_records(['scaleid' => $scaleid], 'sortorder');
    }

    /**
     * Create a template together with its version 1 draft.
     *
     * @param string $name
     * @return template
     */
    public static function create_template(string $name): template {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        $template = new template(0, (object) [
            'name' => $name,
            'status' => template::STATUS_ACTIVE,
        ]);
        $template->create();

        $version = new template_version(0, (object) [
            'templateid' => $template->get('id'),
            'versionnumber' => 1,
            'status' => template_version::STATUS_DRAFT,
        ]);
        $version->create();
        $transaction->allow_commit();

        return $template;
    }

    /**
     * Read a template.
     *
     * @param int $templateid
     * @return template
     */
    public static function get_template(int $templateid): template {
        return template::get_record(['id' => $templateid], MUST_EXIST);
    }

    /**
     * Rename a template.
     *
     * @param int $templateid
     * @param string $name
     * @return template
     */
    public static function update_template(int $templateid, string $name): template {
        $template = self::get_template($templateid);
        $template->set('name', $name);
        $template->update();
        return $template;
    }

    /**
     * Archive a template. Open cycles continue; nothing new can be started against it.
     *
     * @param int $templateid
     * @return void
     */
    public static function archive_template(int $templateid): void {
        $template = self::get_template($templateid);
        $template->set('status', template::STATUS_ARCHIVED);
        $template->update();
    }

    /**
     * Set an archived template back to active.
     *
     * @param int $templateid
     * @return void
     */
    public static function unarchive_template(int $templateid): void {
        $template = self::get_template($templateid);
        $template->set('status', template::STATUS_ACTIVE);
        $template->update();
    }

    /**
     * Copy a template into a brand-new template with a version 1 draft and no assignments.
     *
     * The published version is copied; if the template has never been published, its draft is copied instead.
     *
     * @param int $templateid
     * @param string $newname
     * @return template
     */
    public static function duplicate_template(int $templateid, string $newname): template {
        global $DB;

        self::get_template($templateid);
        $sourceversion = self::get_published_version($templateid) ?? self::get_draft($templateid);

        $transaction = $DB->start_delegated_transaction();
        $template = new template(0, (object) [
            'name' => \core_text::substr($newname, 0, 255),
            'status' => template::STATUS_ACTIVE,
        ]);
        $template->create();

        $version = new template_version(0, (object) [
            'templateid' => $template->get('id'),
            'versionnumber' => 1,
            'status' => template_version::STATUS_DRAFT,
        ]);
        if ($sourceversion) {
            self::copy_settings($sourceversion, $version);
        }
        $version->create();
        if ($sourceversion) {
            self::copy_content($sourceversion, $version);
        }
        $transaction->allow_commit();

        return $template;
    }

    /**
     * Read a template version.
     *
     * @param int $versionid
     * @return template_version
     */
    public static function get_version(int $versionid): template_version {
        return template_version::get_record(['id' => $versionid], MUST_EXIST);
    }

    /**
     * The template's draft version, if one exists.
     *
     * @param int $templateid
     * @return template_version|null
     */
    public static function get_draft(int $templateid): ?template_version {
        $draft = template_version::get_record([
            'templateid' => $templateid,
            'status' => template_version::STATUS_DRAFT,
        ]);
        return $draft ?: null;
    }

    /**
     * The template's published version, if one exists.
     *
     * @param int $templateid
     * @return template_version|null
     */
    public static function get_published_version(int $templateid): ?template_version {
        $published = template_version::get_record([
            'templateid' => $templateid,
            'status' => template_version::STATUS_PUBLISHED,
        ]);
        return $published ?: null;
    }

    /**
     * All versions of a template, newest first.
     *
     * @param int $templateid
     * @return template_version[]
     */
    public static function get_versions(int $templateid): array {
        return template_version::get_records(['templateid' => $templateid], 'versionnumber', 'DESC');
    }

    /**
     * Start a new draft by copying the template's published version (or an empty draft if it has no versions).
     *
     * @param int $templateid
     * @return template_version
     * @throws moodle_exception If a draft already exists.
     */
    public static function create_draft(int $templateid): template_version {
        global $DB;

        self::get_template($templateid);
        if (self::get_draft($templateid)) {
            throw new moodle_exception('error:draftexists', 'local_appraisal');
        }
        $source = self::get_published_version($templateid);
        $latest = (int) $DB->get_field(template_version::TABLE, 'MAX(versionnumber)', ['templateid' => $templateid]);

        $transaction = $DB->start_delegated_transaction();
        $draft = new template_version(0, (object) [
            'templateid' => $templateid,
            'versionnumber' => $latest + 1,
            'status' => template_version::STATUS_DRAFT,
        ]);
        if ($source) {
            self::copy_settings($source, $draft);
        }
        $draft->create();
        if ($source) {
            self::copy_content($source, $draft);
        }
        $transaction->allow_commit();

        return $draft;
    }

    /**
     * Save a draft's overview, overall comments and grade settings, and due date reminder.
     *
     * @param int $versionid
     * @param string|null $overview HTML guidance shown at the top of the appraisal, or null for none.
     * @param int $overviewformat
     * @param int $commentsmode One of the template_version::COMMENTS_* values.
     * @param int $gradingmode One of the template_version::GRADE_* values.
     * @param int|null $overallscaleid The overall grade's scale; ignored (stored as null) when the mode is off.
     * @param int|null $reminderdays Days before the due date to remind; null for no reminder.
     * @return template_version
     * @throws moodle_exception If the version is not a draft or the scale cannot be chosen.
     */
    public static function update_version_settings(
        int $versionid,
        ?string $overview,
        int $overviewformat,
        int $commentsmode,
        int $gradingmode,
        ?int $overallscaleid,
        ?int $reminderdays
    ): template_version {
        $version = self::require_draft($versionid);
        if ($gradingmode === template_version::GRADE_OFF) {
            $overallscaleid = null;
        } else if ($overallscaleid !== $version->get('overallscaleid')) {
            self::require_usable_scale($overallscaleid);
        }
        $version->set('overview', self::empty_html_to_null($overview));
        $version->set('overviewformat', $overviewformat);
        $version->set('commentsmode', $commentsmode);
        $version->set('overallgrademode', $gradingmode);
        $version->set('overallscaleid', $overallscaleid);
        $version->set('reminderdays', $reminderdays);
        $version->update();
        return $version;
    }

    /**
     * Check a scale can be chosen for a field or an overall grade: it exists, is active and has two levels.
     *
     * @param int|null $scaleid
     * @return void
     * @throws moodle_exception If it cannot be chosen.
     */
    private static function require_usable_scale(?int $scaleid): void {
        if ($scaleid === null || !isset(self::get_usable_scales()[$scaleid])) {
            throw new moodle_exception('error:scalenotusable', 'local_appraisal');
        }
    }

    /**
     * Copy one version's settings (overview, comments, overall grade, reminder) onto another, unsaved, version.
     *
     * @param template_version $source
     * @param template_version $target
     * @return void
     */
    private static function copy_settings(template_version $source, template_version $target): void {
        $properties = [
            'overview', 'overviewformat', 'commentsmode', 'overallgrademode', 'overallscaleid', 'reminderdays',
        ];
        foreach ($properties as $property) {
            $target->set($property, $source->get($property));
        }
    }

    /**
     * Rich text with no visible content, such as an empty editor, becomes null.
     *
     * @param string|null $html
     * @return string|null
     */
    private static function empty_html_to_null(?string $html): ?string {
        return $html !== null && trim(html_to_text($html, 0, false)) !== '' ? $html : null;
    }

    /**
     * Publish a draft, archiving the template's previously published version in the same transaction.
     *
     * Publishing is what locks the grading scales the version uses.
     *
     * @param int $versionid
     * @return template_version
     * @throws moodle_exception If the version is not a draft, has no fields, has an empty section, or uses a scale
     *     that no longer has two levels.
     */
    public static function publish_version(int $versionid): template_version {
        global $DB;

        $draft = self::require_draft($versionid);
        $fields = self::get_fields($versionid);
        if (!$fields) {
            throw new moodle_exception('error:nofields', 'local_appraisal');
        }
        if (self::has_empty_section($versionid)) {
            throw new moodle_exception('error:emptysection', 'local_appraisal');
        }
        $scaleids = array_filter(array_map(fn(template_field $field): ?int => $field->get('scaleid'), $fields));
        if ($draft->get('overallscaleid') !== null) {
            $scaleids[] = $draft->get('overallscaleid');
        }
        foreach (array_unique($scaleids) as $scaleid) {
            if (scale_level::count_records(['scaleid' => $scaleid]) < 2) {
                throw new moodle_exception('error:scalenotusable', 'local_appraisal');
            }
        }

        $transaction = $DB->start_delegated_transaction();
        $previous = self::get_published_version($draft->get('templateid'));
        if ($previous) {
            $previous->set('status', template_version::STATUS_ARCHIVED);
            $previous->update();
        }
        $draft->set('status', template_version::STATUS_PUBLISHED);
        $draft->update();
        $transaction->allow_commit();

        return $draft;
    }

    /**
     * Delete a draft with its fields and sections.
     *
     * @param int $versionid
     * @return void
     * @throws moodle_exception If the version is not a draft.
     */
    public static function discard_draft(int $versionid): void {
        global $DB;

        $draft = self::require_draft($versionid);
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records(template_field::TABLE, ['template_versionid' => $versionid]);
        $DB->delete_records(template_section::TABLE, ['template_versionid' => $versionid]);
        $draft->delete();
        $transaction->allow_commit();
    }

    /**
     * A version's fields in display order.
     *
     * @param int $versionid
     * @return template_field[]
     */
    public static function get_fields(int $versionid): array {
        return template_field::get_records(['template_versionid' => $versionid], 'sortorder');
    }

    /**
     * Append a field to a draft, at the end of its section when the version has sections.
     *
     * @param int $versionid
     * @param int $type One of the template_field::TYPE_* constants.
     * @param string $label Plain text.
     * @param int $completedby One of the template_field::COMPLETEDBY_* constants.
     * @param bool $required
     * @param int|null $sectionid Required when the version has sections, ignored when it has none.
     * @param int|null $scaleid Required for a graded rating, not allowed for other types.
     * @param string|null $configdata JSON type settings, see template_field::build_configdata().
     * @param string|null $description Optional plain text help shown under the label.
     * @return template_field
     * @throws moodle_exception If the version is not a draft, or the section or scale cannot be used.
     */
    public static function add_field(
        int $versionid,
        int $type,
        string $label,
        int $completedby = template_field::COMPLETEDBY_APPRAISER,
        bool $required = true,
        ?int $sectionid = null,
        ?int $scaleid = null,
        ?string $configdata = null,
        ?string $description = null
    ): template_field {
        global $DB;

        self::require_draft($versionid);
        $sectionid = self::resolve_section($versionid, $sectionid);
        if ($scaleid !== null) {
            self::require_usable_scale($scaleid);
        }
        $max = (int) $DB->get_field(template_field::TABLE, 'MAX(sortorder)', ['template_versionid' => $versionid]);

        $transaction = $DB->start_delegated_transaction();
        $field = new template_field(0, (object) [
            'template_versionid' => $versionid,
            'sectionid' => $sectionid,
            'sortorder' => $max + 1,
            'type' => $type,
            'label' => $label,
            'description' => $description,
            'completedby' => $completedby,
            'required' => $required,
            'scaleid' => $scaleid,
            'configdata' => $configdata,
        ]);
        $field->create();
        self::repack_fields($versionid);
        $transaction->allow_commit();

        return $field;
    }

    /**
     * Edit a field on a draft, optionally moving it to the end of another section.
     *
     * @param int $fieldid
     * @param int $type One of the template_field::TYPE_* constants.
     * @param string $label Plain text.
     * @param int $completedby One of the template_field::COMPLETEDBY_* constants.
     * @param bool $required
     * @param int|null $sectionid The section to move it to, or null to leave it where it is.
     * @param int|null $scaleid Required for a graded rating, not allowed for other types.
     * @param string|null $configdata JSON type settings, see template_field::build_configdata().
     * @param string|null $description Optional plain text help shown under the label.
     * @return template_field
     * @throws moodle_exception If the field's version is not a draft, or the section or scale cannot be used.
     */
    public static function update_field(
        int $fieldid,
        int $type,
        string $label,
        int $completedby,
        bool $required,
        ?int $sectionid = null,
        ?int $scaleid = null,
        ?string $configdata = null,
        ?string $description = null
    ): template_field {
        global $DB;

        $field = template_field::get_record(['id' => $fieldid], MUST_EXIST);
        $versionid = $field->get('template_versionid');
        self::require_draft($versionid);
        if ($scaleid !== null && $scaleid !== $field->get('scaleid')) {
            self::require_usable_scale($scaleid);
        }

        $transaction = $DB->start_delegated_transaction();
        $field->set('type', $type);
        $field->set('label', $label);
        $field->set('description', $description);
        $field->set('completedby', $completedby);
        $field->set('required', $required);
        $field->set('scaleid', $scaleid);
        $field->set('configdata', $configdata);
        if ($sectionid !== null && $sectionid !== $field->get('sectionid')) {
            $field->set('sectionid', self::resolve_section($versionid, $sectionid));
            $max = (int) $DB->get_field(template_field::TABLE, 'MAX(sortorder)', ['template_versionid' => $versionid]);
            $field->set('sortorder', $max + 1);
        }
        $field->update();
        self::repack_fields($versionid);
        $transaction->allow_commit();

        return $field;
    }

    /**
     * Fix the wording of a field on the published version, in place, without a new version.
     *
     * Only the label, the description and (for a choice field) the option labels can change. Nothing that changes
     * what the field asks does: not the type, who completes it, required, the options themselves or the scale.
     *
     * @param int $fieldid
     * @param string $label Plain text.
     * @param string|null $description Plain text help, or null for none.
     * @param string[]|null $optionlabels For a choice field, the new label of every option in the stored order.
     * @return template_field
     * @throws moodle_exception If the field's version is not the published one, or the option count differs.
     */
    public static function correct_field_wording(
        int $fieldid,
        string $label,
        ?string $description = null,
        ?array $optionlabels = null
    ): template_field {
        $field = template_field::get_record(['id' => $fieldid], MUST_EXIST);
        $version = new template_version($field->get('template_versionid'));
        if (!$version->is_published()) {
            throw new moodle_exception('error:notpublished', 'local_appraisal');
        }
        $field->set('label', $label);
        $field->set('description', $description);
        if ($optionlabels !== null && $field->has_options()) {
            $field->set('configdata', template_field::relabel_options($field->get('configdata'), $optionlabels));
        }
        $field->update();
        return $field;
    }

    /**
     * Correct the overview of the published version, in place, without a new version.
     *
     * The overview is guidance text, so it does not change what any field asks.
     *
     * @param int $versionid
     * @param string|null $overview HTML, or null for none.
     * @param int $overviewformat
     * @return template_version
     * @throws moodle_exception If the version is not the published one.
     */
    public static function correct_overview(int $versionid, ?string $overview, int $overviewformat): template_version {
        $version = self::get_version($versionid);
        if (!$version->is_published()) {
            throw new moodle_exception('error:notpublished', 'local_appraisal');
        }
        $version->set('overview', self::empty_html_to_null($overview));
        $version->set('overviewformat', $overviewformat);
        $version->update();
        return $version;
    }

    /**
     * Delete a field from a draft and close the gap in the ordering.
     *
     * @param int $fieldid
     * @return void
     * @throws moodle_exception If the field's version is not a draft.
     */
    public static function delete_field(int $fieldid): void {
        global $DB;

        $field = template_field::get_record(['id' => $fieldid], MUST_EXIST);
        $versionid = $field->get('template_versionid');
        self::require_draft($versionid);

        $transaction = $DB->start_delegated_transaction();
        $field->delete();
        self::repack_fields($versionid);
        $transaction->allow_commit();
    }

    /**
     * Store a new layout for a draft: the order of its sections, and which fields sit in each, in order.
     *
     * A version without sections is sent as a single entry with id 0.
     *
     * @param int $versionid
     * @param array $sections Every section of the version in the new order, each ['id' => int, 'fieldids' => int[]].
     * @return void
     * @throws moodle_exception If the version is not a draft or the ids don't match its sections and fields exactly.
     */
    public static function reorder_layout(int $versionid, array $sections): void {
        global $DB;

        self::require_draft($versionid);

        $sectionids = array_map(fn(array $section): int => (int) $section['id'], $sections);
        $existingsections = array_keys(self::get_sections($versionid)) ?: [0];
        if (!self::same_ids($sectionids, $existingsections)) {
            throw new moodle_exception('error:sectionmismatch', 'local_appraisal');
        }

        $fieldids = array_merge(...array_map(
            fn(array $section): array => array_map('intval', $section['fieldids']),
            $sections
        ));
        if (!self::same_ids($fieldids, array_keys(self::get_fields($versionid)))) {
            throw new moodle_exception('error:fieldmismatch', 'local_appraisal');
        }

        $existingfields = self::get_fields($versionid);
        $existingsectionrows = self::get_sections($versionid);
        $transaction = $DB->start_delegated_transaction();
        $position = 0;
        foreach ($sections as $index => $section) {
            $sectionid = (int) $section['id'];
            if ($sectionid) {
                $row = $existingsectionrows[$sectionid];
                $row->set('sortorder', $index + 1);
                $row->update();
            }
            foreach ($section['fieldids'] as $fieldid) {
                $position++;
                $field = $existingfields[(int) $fieldid];
                $field->set('sectionid', $sectionid ?: null);
                $field->set('sortorder', $position);
                $field->update();
            }
        }
        $transaction->allow_commit();
    }

    /**
     * A version's sections in display order.
     *
     * @param int $versionid
     * @return template_section[]
     */
    public static function get_sections(int $versionid): array {
        return template_section::get_records(['template_versionid' => $versionid], 'sortorder');
    }

    /**
     * Whether any section of the version has no fields.
     *
     * @param int $versionid
     * @return bool
     */
    public static function has_empty_section(int $versionid): bool {
        global $DB;

        $sql = "SELECT 1
                  FROM {" . template_section::TABLE . "} s
             LEFT JOIN {" . template_field::TABLE . "} f ON f.sectionid = s.id
                 WHERE s.template_versionid = :versionid
                   AND f.id IS NULL";
        return $DB->record_exists_sql($sql, ['versionid' => $versionid]);
    }

    /**
     * Add a section to the end of a draft.
     *
     * Sections are all or nothing: the first section added takes every existing field into it.
     *
     * @param int $versionid
     * @param string $name
     * @param string|null $description HTML, or null for none.
     * @param int $descriptionformat
     * @return template_section
     * @throws moodle_exception If the version is not a draft.
     */
    public static function add_section(
        int $versionid,
        string $name,
        ?string $description = null,
        int $descriptionformat = FORMAT_HTML
    ): template_section {
        global $DB;

        self::require_draft($versionid);
        $isfirst = template_section::count_records(['template_versionid' => $versionid]) === 0;
        $max = (int) $DB->get_field(template_section::TABLE, 'MAX(sortorder)', ['template_versionid' => $versionid]);

        $transaction = $DB->start_delegated_transaction();
        $section = new template_section(0, (object) [
            'template_versionid' => $versionid,
            'sortorder' => $max + 1,
            'name' => $name,
            'description' => self::empty_html_to_null($description),
            'descriptionformat' => $descriptionformat,
        ]);
        $section->create();
        if ($isfirst) {
            foreach (self::get_fields($versionid) as $field) {
                $field->set('sectionid', $section->get('id'));
                $field->update();
            }
        }
        $transaction->allow_commit();

        return $section;
    }

    /**
     * Edit a section's name and description on a draft.
     *
     * @param int $sectionid
     * @param string $name
     * @param string|null $description HTML, or null for none.
     * @param int $descriptionformat
     * @return template_section
     * @throws moodle_exception If the section's version is not a draft.
     */
    public static function update_section(
        int $sectionid,
        string $name,
        ?string $description = null,
        int $descriptionformat = FORMAT_HTML
    ): template_section {
        $section = template_section::get_record(['id' => $sectionid], MUST_EXIST);
        self::require_draft($section->get('template_versionid'));
        return self::set_section_text($section, $name, $description, $descriptionformat);
    }

    /**
     * Fix the wording of a section on the published version, in place, without a new version.
     *
     * Only the name and description can change: not the order or which fields it holds.
     *
     * @param int $sectionid
     * @param string $name
     * @param string|null $description HTML, or null for none.
     * @param int $descriptionformat
     * @return template_section
     * @throws moodle_exception If the section's version is not the published one.
     */
    public static function correct_section_wording(
        int $sectionid,
        string $name,
        ?string $description = null,
        int $descriptionformat = FORMAT_HTML
    ): template_section {
        $section = template_section::get_record(['id' => $sectionid], MUST_EXIST);
        $version = new template_version($section->get('template_versionid'));
        if (!$version->is_published()) {
            throw new moodle_exception('error:notpublished', 'local_appraisal');
        }
        return self::set_section_text($section, $name, $description, $descriptionformat);
    }

    /**
     * Save a section's name and description.
     *
     * @param template_section $section
     * @param string $name
     * @param string|null $description
     * @param int $descriptionformat
     * @return template_section
     */
    private static function set_section_text(
        template_section $section,
        string $name,
        ?string $description,
        int $descriptionformat
    ): template_section {
        $section->set('name', $name);
        $section->set('description', self::empty_html_to_null($description));
        $section->set('descriptionformat', $descriptionformat);
        $section->update();
        return $section;
    }

    /**
     * Delete a section from a draft together with its fields, and close the gaps in the ordering.
     *
     * @param int $sectionid
     * @return void
     * @throws moodle_exception If the section's version is not a draft.
     */
    public static function delete_section(int $sectionid): void {
        global $DB;

        $section = template_section::get_record(['id' => $sectionid], MUST_EXIST);
        $versionid = $section->get('template_versionid');
        self::require_draft($versionid);

        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records(template_field::TABLE, ['sectionid' => $sectionid]);
        $section->delete();
        self::repack_sections($versionid);
        self::repack_fields($versionid);
        $transaction->allow_commit();
    }

    /**
     * Turn a draft back into a single list: delete every section and keep the fields in their current order.
     *
     * @param int $versionid
     * @return void
     * @throws moodle_exception If the version is not a draft.
     */
    public static function remove_sections(int $versionid): void {
        global $DB;

        self::require_draft($versionid);
        $transaction = $DB->start_delegated_transaction();
        foreach (self::get_fields($versionid) as $field) {
            $field->set('sectionid', null);
            $field->update();
        }
        $DB->delete_records(template_section::TABLE, ['template_versionid' => $versionid]);
        $transaction->allow_commit();
    }

    /**
     * Check the section a field is being placed in, given whether the version has sections.
     *
     * @param int $versionid
     * @param int|null $sectionid
     * @return int|null The section id to store: null when the version has no sections.
     * @throws moodle_exception If the version has sections and the id is missing or belongs to another version.
     */
    private static function resolve_section(int $versionid, ?int $sectionid): ?int {
        if (template_section::count_records(['template_versionid' => $versionid]) === 0) {
            return null;
        }
        if (
            !$sectionid || !template_section::record_exists_select(
                'id = :id AND template_versionid = :versionid',
                ['id' => $sectionid, 'versionid' => $versionid]
            )
        ) {
            throw new moodle_exception('error:sectionrequired', 'local_appraisal');
        }
        return $sectionid;
    }

    /**
     * Split items into the version's sections, keeping their order.
     *
     * With no sections, everything comes back as one group whose section is null.
     *
     * @param template_section[] $sections The version's sections in display order.
     * @param array[] $items Items in display order, each an array with a 'sectionid' key.
     * @return array[] Groups of ['section' => template_section|null, 'items' => array[]].
     */
    public static function group_by_section(array $sections, array $items): array {
        if (!$sections) {
            return [['section' => null, 'items' => array_values($items)]];
        }
        $groups = [];
        foreach ($sections as $section) {
            $groups[$section->get('id')] = ['section' => $section, 'items' => []];
        }
        foreach ($items as $item) {
            $groups[$item['sectionid']]['items'][] = $item;
        }
        return array_values($groups);
    }

    /**
     * Load a version and confirm it is a draft.
     *
     * @param int $versionid
     * @return template_version
     * @throws moodle_exception If the version is not a draft.
     */
    private static function require_draft(int $versionid): template_version {
        $version = template_version::get_record(['id' => $versionid], MUST_EXIST);
        if (!$version->is_draft()) {
            throw new moodle_exception('error:notdraft', 'local_appraisal');
        }
        return $version;
    }

    /**
     * Rewrite a version's field sortorder as 1..N in display order: by section, then within the section.
     *
     * @param int $versionid
     * @return void
     */
    private static function repack_fields(int $versionid): void {
        global $DB;

        $sql = "SELECT f.id
                  FROM {" . template_field::TABLE . "} f
             LEFT JOIN {" . template_section::TABLE . "} s ON s.id = f.sectionid
                 WHERE f.template_versionid = :versionid
              ORDER BY s.sortorder, f.sortorder, f.id";
        $fields = self::get_fields($versionid);
        $position = 0;
        foreach (array_keys($DB->get_records_sql($sql, ['versionid' => $versionid])) as $fieldid) {
            $position++;
            if ($fields[$fieldid]->get('sortorder') !== $position) {
                $fields[$fieldid]->set('sortorder', $position);
                $fields[$fieldid]->update();
            }
        }
    }

    /**
     * Rewrite a version's section sortorder as 1..N in the current order.
     *
     * @param int $versionid
     * @return void
     */
    private static function repack_sections(int $versionid): void {
        foreach (array_values(self::get_sections($versionid)) as $index => $section) {
            if ($section->get('sortorder') !== $index + 1) {
                $section->set('sortorder', $index + 1);
                $section->update();
            }
        }
    }

    /**
     * Copy every section and field from one version to another as new rows, keeping each field in the copy of its
     * section.
     *
     * @param template_version $source
     * @param template_version $target
     * @return void
     */
    private static function copy_content(template_version $source, template_version $target): void {
        $sectionmap = [];
        foreach (self::get_sections($source->get('id')) as $section) {
            $copy = new template_section(0, (object) [
                'template_versionid' => $target->get('id'),
                'sortorder' => $section->get('sortorder'),
                'name' => $section->get('name'),
                'description' => $section->get('description'),
                'descriptionformat' => $section->get('descriptionformat'),
            ]);
            $copy->create();
            $sectionmap[$section->get('id')] = $copy->get('id');
        }

        foreach (self::get_fields($source->get('id')) as $field) {
            $sectionid = $field->get('sectionid');
            $copy = new template_field(0, (object) [
                'template_versionid' => $target->get('id'),
                'sectionid' => $sectionid !== null ? $sectionmap[$sectionid] : null,
                'sortorder' => $field->get('sortorder'),
                'type' => $field->get('type'),
                'label' => $field->get('label'),
                'description' => $field->get('description'),
                'completedby' => $field->get('completedby'),
                'required' => $field->get('required'),
                'scaleid' => $field->get('scaleid'),
                'configdata' => $field->get('configdata'),
            ]);
            $copy->create();
        }
    }

    /**
     * How much deleting a template would remove from users: its assignments and their records.
     *
     * @param int $templateid
     * @return \stdClass With assignments and records counts.
     */
    public static function get_template_deletion_summary(int $templateid): \stdClass {
        global $DB;

        $records = $DB->count_records_sql(
            "SELECT COUNT(r.id)
               FROM {" . record::TABLE . "} r
               JOIN {" . assignment::TABLE . "} a ON a.id = r.assignmentid
              WHERE a.templateid = :templateid",
            ['templateid' => $templateid]
        );
        return (object) [
            'assignments' => assignment::count_records(['templateid' => $templateid]),
            'records' => $records,
        ];
    }

    /**
     * Permanently delete a template: every assignment on it with its records, answers, history and transfers, then
     * its versions, sections and fields.
     *
     * Callers check the capabilities first (see access::can_delete_template()), since a template with assignments
     * takes users' records with it.
     *
     * @param int $templateid
     * @return void
     */
    public static function delete_template(int $templateid): void {
        global $DB;

        $template = self::get_template($templateid);
        $versionids = $DB->get_fieldset_select(template_version::TABLE, 'id', 'templateid = :templateid', [
            'templateid' => $templateid,
        ]);

        $name = $template->get('name');
        $assignments = assignment::get_records(['templateid' => $templateid]);

        $transaction = $DB->start_delegated_transaction();
        foreach ($assignments as $assignment) {
            self::delete_assignment_data($assignment->get('id'));
        }
        if ($versionids) {
            [$insql, $params] = $DB->get_in_or_equal($versionids, SQL_PARAMS_NAMED);
            $DB->delete_records_select(template_field::TABLE, 'template_versionid ' . $insql, $params);
            $DB->delete_records_select(template_section::TABLE, 'template_versionid ' . $insql, $params);
            $DB->delete_records_select(template_version::TABLE, 'id ' . $insql, $params);
        }
        $template->delete();
        $transaction->allow_commit();

        event\template_deleted::create([
            'objectid' => $templateid,
            'context' => system::instance(),
            'other' => ['name' => $name, 'versions' => count($versionids), 'assignments' => count($assignments)],
        ])->trigger();
    }

    /**
     * Delete an assignment with every record on it and their answers, history and transfers.
     *
     * Serves delete_assignment() and delete_template(), which fire the events; this fires none.
     *
     * @param int $assignmentid
     * @return void
     */
    private static function delete_assignment_data(int $assignmentid): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        $recordids = $DB->get_fieldset_select(record::TABLE, 'id', 'assignmentid = :assignmentid', [
            'assignmentid' => $assignmentid,
        ]);
        if ($recordids) {
            [$insql, $params] = $DB->get_in_or_equal($recordids, SQL_PARAMS_NAMED);
            $DB->delete_records_select(record_answer::TABLE, 'recordid ' . $insql, $params);
            $DB->delete_records_select('local_appraisal_record_history', 'recordid ' . $insql, $params);
            $DB->delete_records_select('local_appraisal_record_transfer', 'recordid ' . $insql, $params);
            $DB->delete_records_select(record::TABLE, 'id ' . $insql, $params);
        }
        $DB->delete_records(assignment::TABLE, ['id' => $assignmentid]);
        $transaction->allow_commit();
    }

    /**
     * Link a template to an appraisee.
     *
     * @param int $templateid
     * @param int $userid The appraisee.
     * @return assignment
     * @throws moodle_exception If the user is already assigned the template.
     */
    public static function create_assignment(int $templateid, int $userid): assignment {
        if (assignment::record_exists_select('templateid = ? AND userid = ?', [$templateid, $userid])) {
            throw new moodle_exception('error:alreadyassigned', 'local_appraisal');
        }
        $assignment = new assignment(0, (object) [
            'templateid' => $templateid,
            'userid' => $userid,
        ]);
        $assignment->create();
        event\assignment_created::create([
            'objectid' => $assignment->get('id'),
            'context' => system::instance(),
            'relateduserid' => $userid,
            'other' => ['templateid' => $templateid],
        ])->trigger();

        return $assignment;
    }

    /**
     * Read an assignment.
     *
     * @param int $assignmentid
     * @return assignment
     */
    public static function get_assignment(int $assignmentid): assignment {
        return assignment::get_record(['id' => $assignmentid], MUST_EXIST);
    }

    /**
     * The assignment's open cycle (Not started, In progress or Submitted), if there is one.
     *
     * @param int $assignmentid
     * @return record|null
     */
    public static function get_open_record(int $assignmentid): ?record {
        $records = record::get_records_select(
            'assignmentid = ? AND status <> ?',
            [$assignmentid, record::STATUS_COMPLETED]
        );
        return $records ? reset($records) : null;
    }

    /**
     * Start a cycle: a Not started record pinned to a template version, with its creation logged.
     *
     * @param int $assignmentid
     * @param int $versionid The template version the cycle is pinned to.
     * @param int $appraiserid The owner of the record.
     * @param string|null $label
     * @param int|null $duedate
     * @return record
     * @throws moodle_exception If the appraiser is the appraisee, the template is archived or the version is not its
     *     published version, or the assignment already has an open cycle.
     */
    public static function create_record(
        int $assignmentid,
        int $versionid,
        int $appraiserid,
        ?string $label = null,
        ?int $duedate = null
    ): record {
        $assignment = self::get_assignment($assignmentid);
        if ($appraiserid === $assignment->get('userid')) {
            throw new moodle_exception('error:selfappraisal', 'local_appraisal');
        }
        $published = self::get_published_version($assignment->get('templateid'));
        if (
            !self::get_template($assignment->get('templateid'))->is_active()
            || !$published
            || $published->get('id') !== $versionid
        ) {
            throw new moodle_exception('error:cannotstartcycle', 'local_appraisal');
        }

        // The open-cycle check and the insert take turns with any other start on this assignment.
        $record = self::run_locked_on('assignment' . $assignmentid, function () use (
            $assignmentid,
            $versionid,
            $appraiserid,
            $label,
            $duedate
        ): record {
            if (self::get_open_record($assignmentid)) {
                throw new moodle_exception('error:opencycleexists', 'local_appraisal');
            }
            $record = new record(0, (object) [
                'assignmentid' => $assignmentid,
                'template_versionid' => $versionid,
                'appraiserid' => $appraiserid,
                'status' => record::STATUS_NOT_STARTED,
                'label' => $label,
                'duedate' => $duedate,
            ]);
            $record->create();
            self::log_history($record->get('id'), null, record::STATUS_NOT_STARTED, $appraiserid);
            return $record;
        });
        self::trigger_record_event(event\record_created::class, $record);

        return $record;
    }

    /**
     * Fire one of the record events for a record.
     *
     * @param string $eventclass The event class name, a subclass of event\record_event.
     * @param record $record
     * @return void
     */
    private static function trigger_record_event(string $eventclass, record $record): void {
        $assignment = assignment::get_record(['id' => $record->get('assignmentid')], MUST_EXIST);
        $eventclass::create([
            'objectid' => $record->get('id'),
            'context' => system::instance(),
            'relateduserid' => $assignment->get('userid'),
            'other' => [
                'assignmentid' => $assignment->get('id'),
                'templateid' => $assignment->get('templateid'),
            ],
        ])->trigger();
    }

    /**
     * Run some work on a record with the record locked, in one transaction.
     *
     * Answer saves and status changes on one record take turns, so a submit or hand back cannot land between a save's
     * permission check and its write. The lock is taken before the transaction starts, so the work reads what the
     * previous holder committed.
     *
     * @param int $recordid
     * @param callable $work Called with no arguments once the record is locked; its return value is returned.
     * @return mixed What the work returned.
     * @throws moodle_exception If the record stays locked by someone else for ten seconds.
     */
    private static function run_locked(int $recordid, callable $work): mixed {
        return self::run_locked_on('record' . $recordid, $work);
    }

    /**
     * Run some work with a named lock held, in one transaction. See run_locked(); the cycle rules lock the
     * assignment, since they decide about all of its records at once.
     *
     * @param string $resource The lock name, such as 'record5' or 'assignment3'.
     * @param callable $work Called with no arguments once the lock is held; its return value is returned.
     * @return mixed What the work returned.
     * @throws moodle_exception If the lock stays held by someone else for ten seconds.
     */
    private static function run_locked_on(string $resource, callable $work): mixed {
        global $DB;

        $lock = \core\lock\lock_config::get_lock_factory('local_appraisal_record')->get_lock($resource, 10);
        if (!$lock) {
            throw new moodle_exception('error:recordbusy', 'local_appraisal');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $result = $work();
            } catch (\Throwable $exception) {
                $transaction->rollback($exception);
            }
            $transaction->allow_commit();
            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * A cycle label as stored: trimmed, at most 255 characters, null when empty.
     *
     * @param string|null $label
     * @return string|null
     */
    private static function normalise_label(?string $label): ?string {
        return $label === null || trim($label) === '' ? null : \core_text::substr(trim($label), 0, 255);
    }

    /**
     * The site timezone, as an identifier make_timestamp() and userdate() accept (the server's when the site follows
     * it). Due dates are shown in it, so everyone sees the day the appraiser chose.
     *
     * @return string
     */
    public static function site_timezone(): string {
        return \core_date::get_server_timezone();
    }

    /**
     * The due date stored for a chosen day: 23:59:59 on that calendar day in the site timezone.
     *
     * @param int $day Any timestamp on the chosen day in the viewer's timezone, such as a date selector returns.
     * @return int
     */
    public static function due_date_from_day(int $day): int {
        $date = usergetdate($day);
        return make_timestamp($date['year'], $date['mon'], $date['mday'], 23, 59, 59, self::site_timezone());
    }

    /**
     * Templates the user could be assigned: active, with a published version, and not yet assigned to them.
     *
     * @param int $userid The appraisee.
     * @return template[] Keyed by template id, ordered by name.
     */
    public static function get_assignable_templates(int $userid): array {
        $select = "status = :active
                   AND EXISTS (SELECT 1
                                 FROM {" . template_version::TABLE . "} v
                                WHERE v.templateid = {" . template::TABLE . "}.id
                                  AND v.status = :published)
                   AND NOT EXISTS (SELECT 1
                                     FROM {" . assignment::TABLE . "} a
                                    WHERE a.templateid = {" . template::TABLE . "}.id
                                      AND a.userid = :userid)";
        return template::get_records_select($select, [
            'active' => template::STATUS_ACTIVE,
            'published' => template_version::STATUS_PUBLISHED,
            'userid' => $userid,
        ], 'name');
    }

    /**
     * Assign a template to a user and start the first cycle on its published version, owned by the assigner.
     *
     * @param int $templateid
     * @param int $userid The appraisee.
     * @param int $appraiserid The assigner, who owns the new record.
     * @param string|null $label Optional plain-text cycle label.
     * @param int|null $duedate Optional due date, see due_date_from_day().
     * @return record The new, Not started, record.
     * @throws moodle_exception If the template cannot be assigned: archived, unpublished, already assigned to the
     *     user, the assigner is the user, or the assigner cannot appraise the user (no appraise capability, or no
     *     access gate to them).
     */
    public static function assign_template(
        int $templateid,
        int $userid,
        int $appraiserid,
        ?string $label = null,
        ?int $duedate = null
    ): record {
        global $DB;

        if ($userid === $appraiserid) {
            throw new moodle_exception('error:selfappraisal', 'local_appraisal');
        }
        if (!access::can_appraise($appraiserid, $userid)) {
            throw new moodle_exception('error:nodashboardaccess', 'local_appraisal');
        }
        if (!isset(self::get_assignable_templates($userid)[$templateid])) {
            throw new moodle_exception('error:cannotassign', 'local_appraisal');
        }
        $label = self::normalise_label($label);

        $transaction = $DB->start_delegated_transaction();
        $assignment = self::create_assignment($templateid, $userid);
        $version = self::get_published_version($templateid);
        $record = self::create_record(
            $assignment->get('id'),
            $version->get('id'),
            $appraiserid,
            $label,
            $duedate
        );
        self::send_started($record);
        $transaction->allow_commit();

        return $record;
    }

    /**
     * One row per assignment of a user, with the open cycle and the latest completed cycle, for the dashboard.
     *
     * @param int $userid The appraisee.
     * @param int $now The time that decides whether the open cycle is overdue.
     * @return \stdClass[] Ordered by template name. Each has assignmentid, templateid, templatename, templatestatus,
     *     open (record|null), overdue (whether the open cycle is past its due date), last (record|null),
     *     lastgrade (the overall level's label, or null) and records (how many cycles the assignment has).
     */
    public static function get_dashboard_rows(int $userid, int $now): array {
        global $DB;

        $sql = "SELECT a.id AS assignmentid,
                       t.id AS templateid,
                       t.name AS templatename,
                       t.status AS templatestatus
                  FROM {" . assignment::TABLE . "} a
                  JOIN {" . template::TABLE . "} t ON t.id = a.templateid
                 WHERE a.userid = :userid
              ORDER BY t.name, a.id";
        $rows = $DB->get_records_sql($sql, ['userid' => $userid]);
        if (!$rows) {
            return [];
        }
        foreach ($rows as $row) {
            $row->open = null;
            $row->overdue = false;
            $row->last = null;
            $row->lastgrade = null;
            $row->records = 0;
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($rows), SQL_PARAMS_NAMED);
        $records = record::get_records_select('assignmentid ' . $insql, $params, 'timecreated, id');
        foreach ($records as $record) {
            $row = $rows[$record->get('assignmentid')];
            $row->records++;
            if ($record->is_open()) {
                $row->open = $record;
            } else if (!$row->last || ($record->get('timecompleted') ?? 0) >= ($row->last->get('timecompleted') ?? 0)) {
                $row->last = $record;
            }
        }
        $levelids = [];
        foreach ($rows as $row) {
            $row->overdue = $row->open && $row->open->get('duedate') !== null && $row->open->get('duedate') < $now;
            if ($row->last?->get('overalllevelid')) {
                $levelids[] = $row->last->get('overalllevelid');
            }
        }
        $levels = [];
        if ($levelids) {
            [$levelsql, $levelparams] = $DB->get_in_or_equal(array_unique($levelids), SQL_PARAMS_NAMED, 'lvl');
            $levels = scale_level::get_records_select('id ' . $levelsql, $levelparams);
        }
        foreach ($rows as $row) {
            $levelid = $row->last?->get('overalllevelid');
            $row->lastgrade = $levelid && isset($levels[$levelid]) ? $levels[$levelid]->get('label') : null;
        }
        return $rows;
    }

    /**
     * The templates a user has completed cycles of, for the history filter.
     *
     * @param int $userid The appraisee.
     * @return string[] Template names keyed by template id, ordered by name.
     */
    public static function get_completed_templates(int $userid): array {
        global $DB;

        $sql = "SELECT DISTINCT t.id, t.name
                  FROM {" . record::TABLE . "} r
                  JOIN {" . assignment::TABLE . "} a ON a.id = r.assignmentid
                  JOIN {" . template::TABLE . "} t ON t.id = a.templateid
                 WHERE a.userid = :userid
                   AND r.status = :completed
              ORDER BY t.name, t.id";
        return $DB->get_records_sql_menu($sql, ['userid' => $userid, 'completed' => record::STATUS_COMPLETED]);
    }

    /**
     * A page of a user's completed cycles, newest first.
     *
     * @param int $userid The appraisee.
     * @param int $page Zero-based page number.
     * @param int $perpage
     * @param int $templateid Only this template's cycles, or 0 for all.
     * @return array ['rows' => \stdClass[], 'total' => int]. Each row has id, templatename, label, timecompleted,
     *     gradelabel (or null), appraiserid and the appraiser's name fields prefixed appraiser_ (null when the
     *     appraiser's account no longer exists).
     */
    public static function get_completed_cycles(int $userid, int $page, int $perpage, int $templateid = 0): array {
        global $DB;

        $params = ['userid' => $userid, 'completed' => record::STATUS_COMPLETED];
        $where = 'a.userid = :userid AND r.status = :completed';
        if ($templateid) {
            $where .= ' AND a.templateid = :templateid';
            $params['templateid'] = $templateid;
        }
        $from = "FROM {" . record::TABLE . "} r
                  JOIN {" . assignment::TABLE . "} a ON a.id = r.assignmentid
                  JOIN {" . template::TABLE . "} t ON t.id = a.templateid
             LEFT JOIN {" . scale_level::TABLE . "} l ON l.id = r.overalllevelid
             LEFT JOIN {user} u ON u.id = r.appraiserid
                 WHERE $where";
        $total = $DB->count_records_sql("SELECT COUNT(1) $from", $params);

        $namefields = '';
        foreach (\core_user\fields::get_name_fields() as $field) {
            $namefields .= ", u.$field AS appraiser_$field";
        }
        $rows = $DB->get_records_sql(
            "SELECT r.id, r.label, r.timecompleted, r.appraiserid, t.name AS templatename, l.label AS gradelabel
                    $namefields
               $from
           ORDER BY r.timecompleted DESC, r.id DESC",
            $params,
            $page * $perpage,
            $perpage
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Per-person counts for the People I appraise list.
     *
     * @param int[] $userids The appraisees.
     * @param int $viewerid The viewer, whose Submitted records count as awaiting sign-off.
     * @param int $now The time that decides what is overdue.
     * @return \stdClass[] Keyed by user id, each with assignments, open, awaiting, overdue and nextdue (the soonest
     *     due date among open cycles, or null). Users with no assignments get zeros.
     */
    public static function summarise_people(array $userids, int $viewerid, int $now): array {
        global $DB;

        $summary = [];
        foreach ($userids as $userid) {
            $summary[$userid] = (object) [
                'assignments' => 0, 'open' => 0, 'awaiting' => 0, 'overdue' => 0, 'nextdue' => null,
            ];
        }
        if (!$userids) {
            return $summary;
        }

        // In batches, so a long list stays under the parameter limit of the stricter databases.
        foreach (array_chunk($userids, 1000) as $batch) {
            [$insql, $params] = $DB->get_in_or_equal($batch, SQL_PARAMS_NAMED);
            $sql = "SELECT a.userid,
                           COUNT(a.id) AS assignments
                      FROM {" . assignment::TABLE . "} a
                     WHERE a.userid $insql
                  GROUP BY a.userid";
            foreach ($DB->get_records_sql($sql, $params) as $row) {
                $summary[$row->userid]->assignments = (int) $row->assignments;
            }

            $params['completed'] = record::STATUS_COMPLETED;
            $sql = "SELECT r.id, a.userid, r.status, r.appraiserid, r.duedate
                      FROM {" . record::TABLE . "} r
                      JOIN {" . assignment::TABLE . "} a ON a.id = r.assignmentid
                     WHERE a.userid $insql
                       AND r.status <> :completed";
            $recordset = $DB->get_recordset_sql($sql, $params);
            foreach ($recordset as $row) {
                $person = $summary[$row->userid];
                $person->open++;
                if ((int) $row->status === record::STATUS_SUBMITTED && (int) $row->appraiserid === $viewerid) {
                    $person->awaiting++;
                }
                if ($row->duedate !== null) {
                    $due = (int) $row->duedate;
                    if ($due < $now) {
                        $person->overdue++;
                    }
                    $person->nextdue = $person->nextdue === null ? $due : min($person->nextdue, $due);
                }
            }
            $recordset->close();
        }
        return $summary;
    }

    /**
     * Apply a People I appraise filter and the list's ordering.
     *
     * Filters: 'attention' keeps people with an overdue cycle or one awaiting the viewer's sign-off, 'open' keeps
     * people with any open cycle, anything else keeps everyone. The order is overdue first, then awaiting sign-off,
     * then soonest due (people with nothing due last), then name.
     *
     * @param \stdClass[] $people Each with fullname, open, awaiting, overdue and nextdue, as summarise_people()
     *     returns plus the name.
     * @param string $filter One of 'all', 'attention' or 'open'.
     * @return \stdClass[] A re-indexed list.
     */
    public static function filter_and_sort_people(array $people, string $filter): array {
        $people = array_filter($people, fn(\stdClass $p): bool => match ($filter) {
            'attention' => $p->overdue > 0 || $p->awaiting > 0,
            'open' => $p->open > 0,
            default => true,
        });
        // Rank the names once in the user's locale, so the tie-break below compares ranks.
        $names = array_map(fn(\stdClass $p): string => $p->fullname, $people);
        \core_collator::asort($names);
        $namerank = array_flip(array_keys($names));
        uksort($people, function (int|string $a, int|string $b) use ($people, $namerank): int {
            $pa = $people[$a];
            $pb = $people[$b];
            $byurgency = [$pb->overdue, $pb->awaiting, $pa->nextdue ?? PHP_INT_MAX]
                <=> [$pa->overdue, $pa->awaiting, $pb->nextdue ?? PHP_INT_MAX];
            return $byurgency ?: $namerank[$a] <=> $namerank[$b];
        });
        return array_values($people);
    }

    /**
     * Read a record.
     *
     * @param int $recordid
     * @return record
     */
    public static function get_record(int $recordid): record {
        return record::get_record(['id' => $recordid], MUST_EXIST);
    }

    /**
     * Append a row to a record's status history.
     *
     * @param int $recordid
     * @param int|null $fromstatus Null on the row logging the record's creation.
     * @param int $tostatus
     * @param int|null $actorid Null when the scheduled task made the change.
     * @param string|null $comment
     * @return int The id of the history row.
     */
    public static function log_history(
        int $recordid,
        ?int $fromstatus,
        int $tostatus,
        ?int $actorid,
        ?string $comment = null
    ): int {
        global $DB;

        return $DB->insert_record('local_appraisal_record_history', (object) [
            'recordid' => $recordid,
            'fromstatus' => $fromstatus,
            'tostatus' => $tostatus,
            'actorid' => $actorid,
            'comment' => $comment,
            'timecreated' => self::now(),
        ]);
    }

    /**
     * Read a record's status history, oldest first.
     *
     * @param int $recordid
     * @return \stdClass[]
     */
    public static function get_history(int $recordid): array {
        global $DB;

        return $DB->get_records('local_appraisal_record_history', ['recordid' => $recordid], 'id');
    }

    /**
     * Append a row to a record's ownership transfer log.
     *
     * @param int $recordid
     * @param int $fromappraiserid
     * @param int $toappraiserid
     * @param int $actorid
     * @return int The id of the transfer row.
     */
    public static function log_transfer(int $recordid, int $fromappraiserid, int $toappraiserid, int $actorid): int {
        global $DB;

        return $DB->insert_record('local_appraisal_record_transfer', (object) [
            'recordid' => $recordid,
            'fromappraiserid' => $fromappraiserid,
            'toappraiserid' => $toappraiserid,
            'actorid' => $actorid,
            'timecreated' => self::now(),
        ]);
    }

    /**
     * Read a record's ownership transfers, oldest first.
     *
     * @param int $recordid
     * @return \stdClass[]
     */
    public static function get_transfers(int $recordid): array {
        global $DB;

        return $DB->get_records('local_appraisal_record_transfer', ['recordid' => $recordid], 'id');
    }

    /**
     * A record with its assignment, version and template, for pages and services that work on one record.
     *
     * @param int $recordid
     * @return \stdClass With record, assignment, version and template.
     * @throws moodle_exception If the record does not exist.
     */
    public static function get_record_context(int $recordid): \stdClass {
        $record = record::get_record(['id' => $recordid]);
        if (!$record) {
            throw new moodle_exception('error:recordnotfound', 'local_appraisal');
        }
        $assignment = assignment::get_record(['id' => $record->get('assignmentid')], MUST_EXIST);
        $version = template_version::get_record(['id' => $record->get('template_versionid')], MUST_EXIST);
        $template = template::get_record(['id' => $version->get('templateid')], MUST_EXIST);
        return (object) compact('record', 'assignment', 'version', 'template');
    }

    /**
     * Which side of a record a user sees it from.
     *
     * The appraisee, with completeown, sees it from the appraisee side. Anyone who may appraise the appraisee, owner
     * or not, sees it from the appraiser side. Everyone else has no view of it.
     *
     * @param \stdClass $ctx From get_record_context().
     * @param int $userid
     * @return int|null record_answer::ROLE_APPRAISER or ROLE_APPRAISEE, or null when the user may not view it.
     */
    public static function get_viewer_role(\stdClass $ctx, int $userid): ?int {
        $appraiseeid = $ctx->assignment->get('userid');
        if ($userid === $appraiseeid) {
            return has_capability('local/appraisal:completeown', system::instance(), $userid)
                ? record_answer::ROLE_APPRAISEE : null;
        }
        return access::can_appraise($userid, $appraiseeid) ? record_answer::ROLE_APPRAISER : null;
    }

    /**
     * Load a record for a viewer, refusing anyone who may not see it.
     *
     * @param int $recordid
     * @param int $userid
     * @return \stdClass From get_record_context(), plus role (the viewer's side, see get_viewer_role()).
     * @throws moodle_exception If the record does not exist or the user may not view it.
     */
    public static function require_record_access(int $recordid, int $userid): \stdClass {
        $ctx = self::get_record_context($recordid);
        $role = self::get_viewer_role($ctx, $userid);
        if ($role === null) {
            throw new moodle_exception('error:nodashboardaccess', 'local_appraisal');
        }
        $ctx->role = $role;
        return $ctx;
    }

    /**
     * Whether a user may write answers as a role on a record right now. These are the only two write paths.
     *
     * The appraiser role: the record's owner, who holds appraise and can still reach the appraisee, while the record
     * is open. The appraisee role: the assignment's user, who holds completeown, while the record is Not started or
     * In progress. Nobody can write the other role's answers, whatever their capabilities.
     *
     * @param \stdClass $ctx From get_record_context().
     * @param int $userid
     * @param int $role record_answer::ROLE_APPRAISER or ROLE_APPRAISEE.
     * @return bool
     */
    public static function can_write_role(\stdClass $ctx, int $userid, int $role): bool {
        $record = $ctx->record;
        if (self::get_viewer_role($ctx, $userid) !== $role) {
            return false;
        }
        if ($role === record_answer::ROLE_APPRAISER) {
            return $record->get('appraiserid') === $userid && $record->is_open();
        }
        return in_array($record->get('status'), [record::STATUS_NOT_STARTED, record::STATUS_IN_PROGRESS], true);
    }

    /**
     * Whether a viewer on one side may see the answers the other side wrote.
     *
     * Your own side's answers are always visible. The other side's are hidden until the appraisee has first
     * submitted (timesubmitted, which stays set after a hand back) or the record is Completed, which also covers a
     * version with no appraisee fields, where nobody ever submits.
     *
     * @param record $record
     * @param int $viewerrole The viewer's side.
     * @param int $answerrole The side that wrote the answer.
     * @return bool
     */
    public static function answers_visible(record $record, int $viewerrole, int $answerrole): bool {
        return $viewerrole === $answerrole
            || $record->get('timesubmitted') !== null
            || $record->get('status') === record::STATUS_COMPLETED;
    }

    /**
     * The record's fields for one viewer, with the answers they are allowed to see.
     *
     * The other side's answer is left out (null) while it is hidden, so nothing built from this can leak it.
     *
     * @param \stdClass $ctx From get_record_context().
     * @param int $viewerrole The viewer's side.
     * @param int $userid The viewer, to decide which fields they may edit.
     * @return \stdClass[] In display order. Each has field, sectionid, mine (the viewer's side completes it), theirs
     *     (the other side does), own and other (record_answer or null), otherhidden (the other side's answer is not
     *     yet visible to the viewer) and editable.
     */
    public static function get_record_fields(\stdClass $ctx, int $viewerrole, int $userid): array {
        $record = $ctx->record;
        $otherrole = $viewerrole === record_answer::ROLE_APPRAISER
            ? record_answer::ROLE_APPRAISEE : record_answer::ROLE_APPRAISER;
        $canwrite = self::can_write_role($ctx, $userid, $viewerrole);
        $othervisible = self::answers_visible($record, $viewerrole, $otherrole);

        $answers = [];
        foreach (self::get_answers($record->get('id')) as $answer) {
            $answers[$answer->get('fieldid')][$answer->get('role')] = $answer;
        }

        $rows = [];
        foreach (self::get_fields($record->get('template_versionid')) as $field) {
            $fieldid = $field->get('id');
            $mine = $viewerrole === record_answer::ROLE_APPRAISER
                ? $field->is_completed_by_appraiser() : $field->is_completed_by_appraisee();
            $theirs = $viewerrole === record_answer::ROLE_APPRAISER
                ? $field->is_completed_by_appraisee() : $field->is_completed_by_appraiser();
            $own = $mine ? ($answers[$fieldid][$viewerrole] ?? null) : null;
            $other = $theirs && $othervisible ? ($answers[$fieldid][$otherrole] ?? null) : null;
            $rows[] = (object) [
                'field' => $field,
                'sectionid' => $field->get('sectionid'),
                'mine' => $mine,
                'theirs' => $theirs,
                'own' => $own,
                'other' => $other,
                'otherhidden' => $theirs && !$othervisible,
                'editable' => $mine && $canwrite,
            ];
        }

        return $rows;
    }

    /**
     * The name fields of some users, for showing who saved an answer.
     *
     * @param int[] $userids
     * @return \stdClass[] Keyed by user id, each with the fields fullname() needs. An anonymised user (id 0) is
     *     missing from the result.
     */
    public static function get_user_names(array $userids): array {
        global $DB;

        $userids = array_filter(array_unique($userids));
        if (!$userids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $fields = implode(', ', \core_user\fields::get_name_fields());
        return $DB->get_records_select('user', 'id ' . $insql, $params, '', 'id, ' . $fields);
    }

    /**
     * Whether an answer counts as complete for its field: a value is held, and a number is within the field's limits.
     *
     * @param template_field $field
     * @param record_answer|null $answer
     * @return bool
     */
    public static function is_answer_complete(template_field $field, ?record_answer $answer): bool {
        if (!$answer) {
            return false;
        }
        switch ($field->get('type')) {
            case template_field::TYPE_SHORTTEXT:
            case template_field::TYPE_LONGTEXT:
                return trim((string) $answer->get('valuetext')) !== '';
            case template_field::TYPE_MULTICHOICE:
                $keys = json_decode((string) $answer->get('valuetext'), true);
                return is_array($keys) && $keys !== [];
            case template_field::TYPE_NUMBER:
                $number = $answer->get('valuenumber');
                $config = $field->get_config();
                return $number !== null
                    && (!isset($config['min']) || $number >= $config['min'])
                    && (!isset($config['max']) || $number <= $config['max']);
            default:
                return $answer->get('valueint') !== null;
        }
    }

    /**
     * How many of the required fields a side completes are complete, for one group of the page's field items.
     *
     * @param array[] $items Each with mine (the viewer's side completes it), required and complete flags.
     * @return array ['complete' => int, 'required' => int, 'allcomplete' => bool]
     */
    public static function get_group_progress(array $items): array {
        $required = array_filter($items, fn(array $item): bool => $item['mine'] && $item['required']);
        $complete = array_filter($required, fn(array $item): bool => $item['complete']);
        return [
            'complete' => count($complete),
            'required' => count($required),
            'allcomplete' => count($complete) === count($required),
        ];
    }

    /**
     * What the viewer may see of the appraiser's overall comments and overall grade.
     *
     * These belong to the appraiser's side, so they follow the same visibility rule as the appraiser's answers:
     * hidden from the appraisee until they have first submitted or the record is Completed.
     *
     * @param \stdClass $ctx From get_record_context().
     * @param int $viewerrole The viewer's side.
     * @return \stdClass With mode (a template_version::COMMENTS_* value), required (whether the comments must be
     *     written), visible, comment (the text, or null when empty, hidden or the version takes none) and level (the
     *     overall grade's scale_level, or null when unset or hidden).
     */
    public static function get_record_overall(\stdClass $ctx, int $viewerrole): \stdClass {
        $record = $ctx->record;
        $mode = $ctx->version->get('commentsmode');
        $visible = self::answers_visible($record, $viewerrole, record_answer::ROLE_APPRAISER);
        $level = null;
        if ($visible && $record->get('overalllevelid') !== null) {
            $level = scale_level::get_record(['id' => $record->get('overalllevelid')]) ?: null;
        }
        return (object) [
            'mode' => $mode,
            'required' => $mode === template_version::COMMENTS_REQUIRED,
            'visible' => $visible,
            'comment' => $visible && $mode !== template_version::COMMENTS_OFF ? $record->get('comment') : null,
            'level' => $level,
        ];
    }

    /**
     * The scale levels used by a set of fields, loaded once, for format_answer().
     *
     * @param template_field[] $fields
     * @return scale_level[] Keyed by level id.
     */
    public static function get_levels_for_fields(array $fields): array {
        global $DB;

        $scaleids = [];
        foreach ($fields as $field) {
            if ($field->get('scaleid') !== null) {
                $scaleids[$field->get('scaleid')] = $field->get('scaleid');
            }
        }
        if (!$scaleids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($scaleids, SQL_PARAMS_NAMED);
        return scale_level::get_records_select('scaleid ' . $insql, $params);
    }

    /**
     * Progress for one side of a record: required fields that side completes, and how many are done.
     *
     * @param \stdClass $ctx From get_record_context().
     * @param int $role record_answer::ROLE_APPRAISER or ROLE_APPRAISEE.
     * @return array ['complete' => int, 'required' => int, 'allcomplete' => bool]
     */
    public static function get_progress(\stdClass $ctx, int $role): array {
        $answers = [];
        foreach (self::get_answers($ctx->record->get('id')) as $answer) {
            if ($answer->get('role') === $role) {
                $answers[$answer->get('fieldid')] = $answer;
            }
        }
        $required = 0;
        $complete = 0;
        foreach (self::get_fields($ctx->record->get('template_versionid')) as $field) {
            $mine = $role === record_answer::ROLE_APPRAISER
                ? $field->is_completed_by_appraiser() : $field->is_completed_by_appraisee();
            if ($mine && $field->get('required')) {
                $required++;
                $complete += self::is_answer_complete($field, $answers[$field->get('id')] ?? null) ? 1 : 0;
            }
        }
        return ['complete' => $complete, 'required' => $required, 'allcomplete' => $complete === $required];
    }

    /**
     * The answer as text for read-only display: option labels, a scale level's label and description (never its
     * value), a date, or a number. Plain text, to be escaped on output.
     *
     * @param template_field $field
     * @param record_answer $answer
     * @param scale_level[]|null $levels The scale levels by id, from get_levels_for_fields(), or null to look the
     *     level up.
     * @return \stdClass With text and description (a scale level's description, or an empty string).
     */
    public static function format_answer(template_field $field, record_answer $answer, ?array $levels = null): \stdClass {
        $text = '';
        $description = '';
        switch ($field->get('type')) {
            case template_field::TYPE_SINGLECHOICE:
                $text = $field->get_options()[$answer->get('valueint')] ?? '';
                break;
            case template_field::TYPE_MULTICHOICE:
                $options = $field->get_options();
                $labels = [];
                foreach ((array) json_decode((string) $answer->get('valuetext'), true) as $key) {
                    if (isset($options[$key])) {
                        $labels[] = $options[$key];
                    }
                }
                $text = implode(', ', $labels);
                break;
            case template_field::TYPE_SCALE:
                $level = $levels !== null
                    ? ($levels[$answer->get('valueint')] ?? null)
                    : scale_level::get_record(['id' => $answer->get('valueint')]);
                $text = $level ? $level->get('label') : '';
                $description = $level ? (string) $level->get('description') : '';
                break;
            case template_field::TYPE_DATE:
                $text = userdate($answer->get('valueint'), get_string('strftimedate'));
                break;
            case template_field::TYPE_NUMBER:
                $decimals = $field->get_config()['decimals'] ?? -1;
                $text = format_float((float) $answer->get('valuenumber'), $decimals);
                break;
            default:
                $text = (string) $answer->get('valuetext');
        }
        return (object) ['text' => $text, 'description' => $description];
    }

    /**
     * Turn the submitted text for a field into the columns to store, checking it against the field.
     *
     * The text arrives as typed or chosen: free text; an option key; a JSON list of option keys; a scale level id; a
     * day as YYYY-MM-DD; or a number. An empty value (or an empty list) returns null, which clears the answer.
     *
     * @param template_field $field
     * @param string|null $value
     * @return array|null ['valueint' => int|null, 'valuenumber' => float|null, 'valuetext' => string|null], or null.
     * @throws moodle_exception If the value is not one the field accepts.
     */
    public static function parse_answer_value(template_field $field, ?string $value): ?array {
        $value = $value === null ? '' : $value;
        $empty = ['valueint' => null, 'valuenumber' => null, 'valuetext' => null];
        $invalid = fn(): moodle_exception => new moodle_exception('error:invalidanswer', 'local_appraisal');

        switch ($field->get('type')) {
            case template_field::TYPE_SHORTTEXT:
            case template_field::TYPE_LONGTEXT:
                $text = trim($value);
                if ($text === '') {
                    return null;
                }
                $limit = $field->get('type') === template_field::TYPE_SHORTTEXT
                    ? self::SHORTTEXT_MAXLENGTH : self::LONGTEXT_MAXLENGTH;
                if (\core_text::strlen($text) > $limit) {
                    throw new moodle_exception('error:answertoolong', 'local_appraisal', '', $limit);
                }
                return ['valuetext' => $text] + $empty;

            case template_field::TYPE_SINGLECHOICE:
                if (trim($value) === '') {
                    return null;
                }
                if (!ctype_digit(trim($value)) || !isset($field->get_options()[(int) $value])) {
                    throw $invalid();
                }
                return ['valueint' => (int) $value] + $empty;

            case template_field::TYPE_MULTICHOICE:
                if (trim($value) === '') {
                    return null;
                }
                $keys = json_decode($value, true);
                if (!is_array($keys) || !array_is_list($keys)) {
                    throw $invalid();
                }
                if (!$keys) {
                    return null;
                }
                $options = $field->get_options();
                foreach ($keys as $key) {
                    if (!is_int($key) || !isset($options[$key])) {
                        throw $invalid();
                    }
                }
                $keys = array_values(array_unique($keys));
                sort($keys);
                return ['valuetext' => json_encode($keys)] + $empty;

            case template_field::TYPE_SCALE:
                if (trim($value) === '') {
                    return null;
                }
                $onscale = ctype_digit(trim($value)) && scale_level::record_exists_select(
                    'id = :id AND scaleid = :scaleid',
                    ['id' => (int) $value, 'scaleid' => (int) $field->get('scaleid')]
                );
                if (!$onscale) {
                    throw $invalid();
                }
                return ['valueint' => (int) $value] + $empty;

            case template_field::TYPE_DATE:
                if (trim($value) === '') {
                    return null;
                }
                $isday = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m)
                    && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
                if (!$isday) {
                    throw $invalid();
                }
                // Midday of the chosen day in the saver's timezone, so the calendar date survives display elsewhere.
                return ['valueint' => make_timestamp((int) $m[1], (int) $m[2], (int) $m[3], 12, 0, 0)] + $empty;

            case template_field::TYPE_NUMBER:
                if (trim($value) === '') {
                    return null;
                }
                $number = unformat_float(trim($value), true);
                if ($number === false || $number === null || !is_finite($number) || abs($number) >= 1.0E+15) {
                    throw $invalid();
                }
                $config = $field->get_config();
                if ((isset($config['min']) && $number < $config['min']) || (isset($config['max']) && $number > $config['max'])) {
                    throw new moodle_exception('error:answeroutofrange', 'local_appraisal');
                }
                $decimals = $config['decimals'] ?? 5;
                if (round($number, $decimals) != $number) {
                    throw $decimals === 0
                        ? new moodle_exception('error:answerwhole', 'local_appraisal')
                        : new moodle_exception('error:answerdecimals', 'local_appraisal', '', $decimals);
                }
                return ['valuenumber' => (float) $number] + $empty;
        }
        throw $invalid();
    }

    /**
     * Move a Not started record to In progress on its first save, stamping the start and logging the change.
     *
     * @param record $record
     * @param int $userid Who saved.
     * @return void
     */
    private static function mark_started(record $record, int $userid): void {
        // Read it again, so a status another save has just changed is not written back over.
        $fresh = record::get_record(['id' => $record->get('id')], MUST_EXIST);
        if ($fresh->get('status') !== record::STATUS_NOT_STARTED) {
            return;
        }
        $fresh->set('status', record::STATUS_IN_PROGRESS);
        $fresh->set('timestarted', self::now());
        $fresh->update();
        self::log_history($fresh->get('id'), record::STATUS_NOT_STARTED, record::STATUS_IN_PROGRESS, $userid);
        self::trigger_record_event(event\record_started::class, $fresh);
    }

    /**
     * Save one person's answer to one field, replacing the earlier value, or clearing it for an empty value.
     *
     * Checks the write path (see can_write_role()), that the field is on the record's version and is completed by
     * the role, and that the value suits the field. The first save by either person moves a Not started record to
     * In progress.
     *
     * @param int $recordid
     * @param int $fieldid
     * @param int $role The side being saved, which must be the side the user is on.
     * @param int $userid Who is saving.
     * @param string|null $value As typed or chosen, see parse_answer_value(). Empty clears the answer.
     * @return record_answer|null The saved answer, or null when it was cleared.
     * @throws moodle_exception If the user may not write that role now, the field is not theirs to complete, or the
     *     value is not valid.
     */
    public static function save_answer(int $recordid, int $fieldid, int $role, int $userid, ?string $value): ?record_answer {
        $ctx = self::get_record_context($recordid);
        if (!self::can_write_role($ctx, $userid, $role)) {
            throw new moodle_exception('error:cannotwrite', 'local_appraisal');
        }
        $field = template_field::get_record(['id' => $fieldid], MUST_EXIST);
        if ($field->get('template_versionid') !== $ctx->record->get('template_versionid')) {
            throw new moodle_exception('error:fieldmismatch', 'local_appraisal');
        }
        $completes = $role === record_answer::ROLE_APPRAISER
            ? $field->is_completed_by_appraiser() : $field->is_completed_by_appraisee();
        if (!$completes) {
            throw new moodle_exception('error:wrongrolefield', 'local_appraisal');
        }
        $columns = self::parse_answer_value($field, $value);

        return self::run_locked($recordid, function () use ($recordid, $fieldid, $role, $userid, $columns): ?record_answer {
            // The write path is checked again with the record locked, so a submit that has just landed is respected.
            $ctx = self::get_record_context($recordid);
            if (!self::can_write_role($ctx, $userid, $role)) {
                throw new moodle_exception('error:cannotwrite', 'local_appraisal');
            }
            $answer = record_answer::get_record(['recordid' => $recordid, 'fieldid' => $fieldid, 'role' => $role]);
            if ($columns === null) {
                // Clearing an answer that was never saved changes nothing, so it does not start the record.
                if ($answer) {
                    $answer->delete();
                }
                return null;
            }
            if (!$answer) {
                $answer = new record_answer(0, (object) [
                    'recordid' => $recordid,
                    'fieldid' => $fieldid,
                    'role' => $role,
                    'userid' => $userid,
                ]);
            }
            foreach ($columns as $column => $columnvalue) {
                $answer->set($column, $columnvalue);
            }
            $answer->set('userid', $userid);
            $answer->set('timeanswered', self::now());
            if ($answer->get('id')) {
                $answer->update();
            } else {
                $answer->create();
            }
            self::mark_started($ctx->record, $userid);

            return $answer;
        });
    }

    /**
     * Save the appraiser's overall comments on a record they own. Plain text; empty clears them.
     *
     * @param int $recordid
     * @param string $comment
     * @param int $userid The appraiser saving.
     * @return record
     * @throws moodle_exception If the user may not write as the appraiser now, the version takes no comments, or the
     *     text is too long.
     */
    public static function save_comment(int $recordid, string $comment, int $userid): record {
        $comment = trim($comment);
        if (\core_text::strlen($comment) > self::LONGTEXT_MAXLENGTH) {
            throw new moodle_exception('error:answertoolong', 'local_appraisal', '', self::LONGTEXT_MAXLENGTH);
        }

        return self::run_locked($recordid, function () use ($recordid, $comment, $userid): record {
            // Everything is read with the record locked, so a status another save or a submit has just changed is
            // neither missed nor written back over.
            $ctx = self::get_record_context($recordid);
            if (!self::can_write_role($ctx, $userid, record_answer::ROLE_APPRAISER)) {
                throw new moodle_exception('error:cannotwrite', 'local_appraisal');
            }
            if ($ctx->version->get('commentsmode') === template_version::COMMENTS_OFF) {
                throw new moodle_exception('error:commentsoff', 'local_appraisal');
            }
            $record = $ctx->record;
            $record->set('comment', $comment === '' ? null : $comment);
            $record->set('commentformat', FORMAT_PLAIN);
            $record->update();
            if ($comment !== '') {
                self::mark_started($record, $userid);
            }

            return record::get_record(['id' => $recordid], MUST_EXIST);
        });
    }

    /**
     * Whether a version has any field the appraisee completes. Without one nothing is submitted, and the appraiser
     * signs off from In progress.
     *
     * @param int $versionid
     * @return bool
     */
    public static function version_has_appraisee_fields(int $versionid): bool {
        return template_field::record_exists_select(
            'template_versionid = :versionid AND completedby IN (:appraisee, :both)',
            [
                'versionid' => $versionid,
                'appraisee' => template_field::COMPLETEDBY_APPRAISEE,
                'both' => template_field::COMPLETEDBY_BOTH,
            ]
        );
    }

    /**
     * What stops the appraisee submitting their part, as sentences for the person.
     *
     * Only the content rules are listed here (the required fields); whether the person may submit at all is decided by
     * get_record_actions().
     *
     * @param \stdClass $ctx From get_record_context().
     * @return string[] Empty when every required appraisee answer is complete.
     */
    public static function get_submit_outstanding(\stdClass $ctx): array {
        $progress = self::get_progress($ctx, record_answer::ROLE_APPRAISEE);
        if ($progress['allcomplete']) {
            return [];
        }
        return [get_string('outstandingfields', 'local_appraisal', $progress['required'] - $progress['complete'])];
    }

    /**
     * Why the record's status stops it being signed off, as a sentence for the appraiser, or null if it does not.
     *
     * A version with appraisee fields is signed off from Submitted; one without, once anything has been saved.
     *
     * @param \stdClass $ctx From get_record_context().
     * @return string|null
     */
    public static function get_signoff_blocker(\stdClass $ctx): ?string {
        $record = $ctx->record;
        if (self::version_has_appraisee_fields($record->get('template_versionid'))) {
            if ($record->get('status') === record::STATUS_SUBMITTED) {
                return null;
            }
            $appraisee = \core_user::get_user($ctx->assignment->get('userid'));
            return get_string('outstandingsubmit', 'local_appraisal', $appraisee ? fullname($appraisee) : '');
        }
        return $record->get('status') === record::STATUS_NOT_STARTED ? get_string('outstandingstart', 'local_appraisal') : null;
    }

    /**
     * What stops the appraiser signing off, as sentences for the person.
     *
     * The overall grade is chosen on the sign-off form, so it is not listed here.
     *
     * @param \stdClass $ctx From get_record_context().
     * @return string[] Empty when the record can be signed off.
     */
    public static function get_signoff_outstanding(\stdClass $ctx): array {
        $record = $ctx->record;
        $reasons = [];
        if ($blocker = self::get_signoff_blocker($ctx)) {
            $reasons[] = $blocker;
        }
        $progress = self::get_progress($ctx, record_answer::ROLE_APPRAISER);
        if (!$progress['allcomplete']) {
            $reasons[] = get_string('outstandingfields', 'local_appraisal', $progress['required'] - $progress['complete']);
        }
        if (
            $ctx->version->get('commentsmode') === template_version::COMMENTS_REQUIRED
            && trim((string) $record->get('comment')) === ''
        ) {
            $reasons[] = get_string('outstandingcomment', 'local_appraisal');
        }
        return $reasons;
    }

    /**
     * Which lifecycle actions a user may take on a record right now.
     *
     * The owner is the record's appraiser while they hold appraise and can still reach the appraisee (the appraiser
     * write path). Whether Submit or Sign off is ready is separate: see submitoutstanding and signoffoutstanding.
     *
     * @param \stdClass $ctx From get_record_context().
     * @param int $userid
     * @return \stdClass With the booleans submit, handback, signoff, reset, cancel, takeownership and editdetails, and
     *     submitoutstanding and signoffoutstanding (string[], empty when ready).
     */
    public static function get_record_actions(\stdClass $ctx, int $userid): \stdClass {
        $record = $ctx->record;
        $status = $record->get('status');
        $hasappraisee = self::version_has_appraisee_fields($record->get('template_versionid'));
        $owner = self::can_write_role($ctx, $userid, record_answer::ROLE_APPRAISER);
        $canreach = access::can_appraise($userid, $ctx->assignment->get('userid'));

        $actions = (object) [
            'submit' => $hasappraisee && self::can_write_role($ctx, $userid, record_answer::ROLE_APPRAISEE),
            'handback' => $owner && $status === record::STATUS_SUBMITTED,
            'signoff' => $owner,
            'reset' => $owner && in_array($status, [record::STATUS_IN_PROGRESS, record::STATUS_SUBMITTED], true),
            'cancel' => $status === record::STATUS_NOT_STARTED && ($owner || ($canreach && is_siteadmin($userid))),
            'takeownership' => $record->is_open() && $canreach && $record->get('appraiserid') !== $userid,
            'editdetails' => $owner,
            'submitoutstanding' => [],
            'signoffoutstanding' => [],
        ];
        if ($actions->submit) {
            $actions->submitoutstanding = self::get_submit_outstanding($ctx);
        }
        if ($actions->signoff) {
            $actions->signoffoutstanding = self::get_signoff_outstanding($ctx);
        }
        return $actions;
    }

    /**
     * The appraisee submits their part: the record becomes Submitted and their answers lock.
     *
     * Allowed from Not started or In progress once every required appraisee answer is complete. The first submission
     * stamps timesubmitted, which opens the appraiser's answers to the appraisee and is never cleared by a hand back.
     *
     * @param int $recordid
     * @param int $userid The appraisee.
     * @return record
     * @throws moodle_exception If the user may not write as the appraisee now, the version has no appraisee fields, or a
     *     required answer is incomplete.
     */
    public static function submit_part(int $recordid, int $userid): record {
        return self::run_locked($recordid, function () use ($recordid, $userid): record {
            $ctx = self::get_record_context($recordid);
            if (
                !self::can_write_role($ctx, $userid, record_answer::ROLE_APPRAISEE)
                || !self::version_has_appraisee_fields($ctx->record->get('template_versionid'))
            ) {
                throw new moodle_exception('error:cannotsubmit', 'local_appraisal');
            }
            if ($outstanding = self::get_submit_outstanding($ctx)) {
                throw new moodle_exception('error:submitnotready', 'local_appraisal', '', implode(' ', $outstanding));
            }
            $record = $ctx->record;
            $fromstatus = $record->get('status');
            $now = self::now();
            $record->set('status', record::STATUS_SUBMITTED);
            if ($record->get('timesubmitted') === null) {
                $record->set('timesubmitted', $now);
            }
            if ($record->get('timestarted') === null) {
                $record->set('timestarted', $now);
            }
            $record->update();
            self::log_history($recordid, $fromstatus, record::STATUS_SUBMITTED, $userid);
            if ($fromstatus === record::STATUS_NOT_STARTED) {
                self::trigger_record_event(event\record_started::class, $record);
            }
            self::trigger_record_event(event\record_submitted::class, $record);
            messaging::send('submitted', $record->get('appraiserid'), $record);

            return $record;
        });
    }

    /**
     * The owner hands a Submitted record back: the appraisee's answers unlock and the record is In progress again.
     *
     * The appraisee's first-submission time stays, so what they have already seen stays visible.
     *
     * @param int $recordid
     * @param int $userid The record's owner.
     * @param string|null $comment Optional note to the appraisee, stored on the history row.
     * @return record
     * @throws moodle_exception If the user is not the owner, the record is not Submitted, or the note is too long.
     */
    public static function hand_back(int $recordid, int $userid, ?string $comment = null): record {
        $comment = $comment === null || trim($comment) === '' ? null : trim($comment);
        if ($comment !== null && \core_text::strlen($comment) > self::LONGTEXT_MAXLENGTH) {
            throw new moodle_exception('error:answertoolong', 'local_appraisal', '', self::LONGTEXT_MAXLENGTH);
        }
        return self::run_locked($recordid, function () use ($recordid, $userid, $comment): record {
            $ctx = self::get_record_context($recordid);
            $record = $ctx->record;
            if (
                !self::can_write_role($ctx, $userid, record_answer::ROLE_APPRAISER)
                || $record->get('status') !== record::STATUS_SUBMITTED
            ) {
                throw new moodle_exception('error:cannothandback', 'local_appraisal');
            }
            $record->set('status', record::STATUS_IN_PROGRESS);
            $record->update();
            self::log_history($recordid, record::STATUS_SUBMITTED, record::STATUS_IN_PROGRESS, $userid, $comment);
            self::trigger_record_event(event\record_handedback::class, $record);
            $variant = $comment === null ? null : 'handedbacknote';
            messaging::send('handedback', $ctx->assignment->get('userid'), $record, $variant, ['note' => $comment ?? '']);

            return $record;
        });
    }

    /**
     * Turn the day picked on the sign-off form into a completion date.
     *
     * Today means the moment of sign-off. An earlier day is stored as midday of that day in the user's timezone, so the
     * calendar date stays the same when it is shown in another timezone. A later day is converted the same way, so it
     * comes out after $now and get_completion_date_error() can refuse it.
     *
     * @param int $day Midnight of the chosen day in the user's timezone, as the date selector returns it.
     * @param int $now The time of sign-off.
     * @return int
     */
    public static function completion_date_from_day(int $day, int $now): int {
        if ($day >= usergetmidnight($now) && $day <= $now) {
            return $now;
        }
        return $day + 12 * HOURSECS;
    }

    /**
     * Why a completion date can't be used to sign a record off, or null if it can.
     *
     * The date may be backdated to when the appraisal conversation really took place, but not into the future and not
     * to a day before the record was started.
     *
     * @param record $record The record being signed off.
     * @param int $completiondate
     * @param int $now
     * @return string|null A local_appraisal error string key, or null if the date is allowed.
     */
    public static function get_completion_date_error(record $record, int $completiondate, int $now): ?string {
        if ($completiondate > $now) {
            return 'error:completiondatefuture';
        }
        $started = $record->get('timestarted');
        if ($started !== null && usergetmidnight($completiondate) < usergetmidnight($started)) {
            return 'error:completiondatebeforestart';
        }
        return null;
    }

    /**
     * Why an overall grade can't be stored on a version's record, or null if it can.
     *
     * @param template_version $version
     * @param int|null $levelid The chosen level, or null for none.
     * @return string|null A local_appraisal error string key, or null if the choice is allowed.
     */
    public static function get_overall_grade_error(template_version $version, ?int $levelid): ?string {
        $mode = $version->get('overallgrademode');
        if ($mode === template_version::GRADE_OFF) {
            return $levelid === null ? null : 'error:gradenotoffered';
        }
        if ($levelid === null) {
            return $mode === template_version::GRADE_REQUIRED ? 'error:graderequired' : null;
        }
        $level = scale_level::get_record(['id' => $levelid]);
        return $level && $level->get('scaleid') === $version->get('overallscaleid') ? null : 'error:gradeinvalid';
    }

    /**
     * Sign a record off: it becomes Completed, with the overall grade and the completion date.
     *
     * Allowed for the owner when the record is Submitted (or open, for a version with no appraisee fields), every
     * required appraiser answer is complete, the overall comments are written when the version requires them, and an
     * overall grade is chosen when the version requires one. timecompleted holds the completion date, which may be
     * backdated; the history row keeps the real time of the sign-off.
     *
     * @param int $recordid
     * @param int $userid The record's owner.
     * @param int|null $levelid The overall grade, a level of the version's overall grade scale.
     * @param int|null $completiondate When the appraisal took place (see completion_date_from_day()), or null for now.
     * @return record
     * @throws moodle_exception If the user is not the owner, the record is not ready, or the grade or date is refused.
     */
    public static function sign_off(int $recordid, int $userid, ?int $levelid = null, ?int $completiondate = null): record {
        return self::run_locked($recordid, function () use ($recordid, $userid, $levelid, $completiondate): record {
            $ctx = self::get_record_context($recordid);
            $record = $ctx->record;
            if (!self::get_record_actions($ctx, $userid)->signoff) {
                throw new moodle_exception('error:cannotsignoff', 'local_appraisal');
            }
            if ($outstanding = self::get_signoff_outstanding($ctx)) {
                throw new moodle_exception('error:signoffnotready', 'local_appraisal', '', implode(' ', $outstanding));
            }
            if ($error = self::get_overall_grade_error($ctx->version, $levelid)) {
                throw new moodle_exception($error, 'local_appraisal');
            }
            $now = self::now();
            $completiondate = $completiondate ?? $now;
            if ($error = self::get_completion_date_error($record, $completiondate, $now)) {
                throw new moodle_exception($error, 'local_appraisal');
            }

            $fromstatus = $record->get('status');
            $record->set('status', record::STATUS_COMPLETED);
            $record->set('timecompleted', $completiondate);
            $record->set('overalllevelid', $levelid);
            if ($record->get('timestarted') === null) {
                $record->set('timestarted', $now);
            }
            $record->update();
            self::log_history($recordid, $fromstatus, record::STATUS_COMPLETED, $userid);
            self::trigger_record_event(event\record_completed::class, $record);
            messaging::send('completed', $ctx->assignment->get('userid'), $record);

            return $record;
        });
    }

    /**
     * Reset an open record: every answer from both people, the overall comments and grade are deleted and the record
     * returns to Not started.
     *
     * The start and first-submission times are cleared too, so the visibility rule starts again: each person sees only
     * their own answers until the appraisee submits afresh.
     *
     * @param int $recordid
     * @param int $userid The record's owner.
     * @return record
     * @throws moodle_exception If the user is not the owner or the record is not In progress or Submitted.
     */
    public static function reset_record(int $recordid, int $userid): record {
        return self::run_locked($recordid, function () use ($recordid, $userid): record {
            global $DB;

            $ctx = self::get_record_context($recordid);
            $record = $ctx->record;
            if (!self::get_record_actions($ctx, $userid)->reset) {
                throw new moodle_exception('error:cannotreset', 'local_appraisal');
            }
            $fromstatus = $record->get('status');
            $DB->delete_records(record_answer::TABLE, ['recordid' => $recordid]);
            $record->set('status', record::STATUS_NOT_STARTED);
            $record->set('timestarted', null);
            $record->set('timesubmitted', null);
            $record->set('comment', null);
            $record->set('overalllevelid', null);
            $record->update();
            self::log_history($recordid, $fromstatus, record::STATUS_NOT_STARTED, $userid);
            self::trigger_record_event(event\record_reset::class, $record);

            return $record;
        });
    }

    /**
     * Delete a Not started record: the undo path for an accidental Assign template or Start new appraisal.
     *
     * Allowed for the owner, or a site administrator who can reach the appraisee.
     *
     * @param int $recordid
     * @param int $userid
     * @return void
     * @throws moodle_exception If the user may not cancel it, it is not Not started, or it holds an answer or comment.
     */
    public static function cancel_record(int $recordid, int $userid): void {
        self::run_locked($recordid, function () use ($recordid, $userid): void {
            global $DB;

            $ctx = self::get_record_context($recordid);
            $record = $ctx->record;
            if (!self::get_record_actions($ctx, $userid)->cancel) {
                throw new moodle_exception('error:cannotcancel', 'local_appraisal');
            }
            if ($record->get('comment') !== null || record_answer::count_records(['recordid' => $recordid]) > 0) {
                throw new moodle_exception('error:recordhascontent', 'local_appraisal');
            }
            $DB->delete_records('local_appraisal_record_history', ['recordid' => $recordid]);
            $DB->delete_records('local_appraisal_record_transfer', ['recordid' => $recordid]);
            $record->delete();
        });
    }

    /**
     * Take ownership of an open record, as any appraiser who can reach the appraisee may. The change is logged.
     *
     * @param int $recordid
     * @param int $userid The appraiser taking over.
     * @return record
     * @throws moodle_exception If the record is not open, the user already owns it, or cannot reach the appraisee.
     */
    public static function take_ownership(int $recordid, int $userid): record {
        return self::run_locked($recordid, function () use ($recordid, $userid): record {
            $ctx = self::get_record_context($recordid);
            $record = $ctx->record;
            if (!self::get_record_actions($ctx, $userid)->takeownership) {
                throw new moodle_exception('error:cannottakeownership', 'local_appraisal');
            }
            $outgoingid = (int) $record->get('appraiserid');
            self::log_transfer($recordid, $outgoingid, $userid, $userid);
            $record->set('appraiserid', $userid);
            $record->update();
            self::trigger_record_event(event\record_transferred::class, $record);
            // Only the person taking over can make the change, so the incoming appraiser is not told. The outgoing
            // one is, unless they were anonymised.
            if ($outgoingid !== self::APPRAISER_DELETED) {
                messaging::send('reassigned', $outgoingid, $record);
            }

            return $record;
        });
    }

    /**
     * Change the label and due date of an open record. A new due date clears the reminder stamp, so the new date gets
     * its own reminder.
     *
     * @param int $recordid
     * @param int $userid The record's owner.
     * @param string|null $label
     * @param int|null $duedate See due_date_from_day().
     * @return record
     * @throws moodle_exception If the user is not the owner of an open record.
     */
    public static function update_record_details(int $recordid, int $userid, ?string $label, ?int $duedate): record {
        $label = self::normalise_label($label);
        return self::run_locked($recordid, function () use ($recordid, $userid, $label, $duedate): record {
            $ctx = self::get_record_context($recordid);
            $record = $ctx->record;
            if (!self::get_record_actions($ctx, $userid)->editdetails) {
                throw new moodle_exception('error:cannotact', 'local_appraisal');
            }
            if ($record->get('duedate') !== $duedate) {
                $record->set('timereminded', null);
            }
            $record->set('label', $label);
            $record->set('duedate', $duedate);
            $record->update();

            return $record;
        });
    }

    /**
     * Why a new cycle can't be started on an assignment right now, or null if it can.
     *
     * @param assignment $assignment
     * @return string|null A local_appraisal error string key.
     */
    public static function get_start_blocker(assignment $assignment): ?string {
        if (self::get_open_record($assignment->get('id'))) {
            return 'error:opencycleexists';
        }
        if (!self::get_template($assignment->get('templateid'))->is_active()) {
            return 'error:templateinactive';
        }
        if (!self::get_published_version($assignment->get('templateid'))) {
            return 'error:unpublished';
        }
        return null;
    }

    /**
     * Start a new cycle on an assignment, on the template's latest published version, owned by the starter.
     *
     * Completed cycles are untouched: a new cycle never changes a past one.
     *
     * @param int $assignmentid
     * @param int $userid The appraiser starting it, who becomes the owner.
     * @param string|null $label
     * @param int|null $duedate See due_date_from_day().
     * @return record
     * @throws moodle_exception If the user cannot appraise the appraisee, or the cycle cannot be started (an open
     *     cycle exists, the template is archived or has no published version).
     */
    public static function start_cycle(int $assignmentid, int $userid, ?string $label = null, ?int $duedate = null): record {
        $assignment = self::get_assignment($assignmentid);
        if (!access::can_appraise($userid, $assignment->get('userid'))) {
            throw new moodle_exception('error:nodashboardaccess', 'local_appraisal');
        }
        if ($blocker = self::get_start_blocker($assignment)) {
            throw new moodle_exception($blocker, 'local_appraisal');
        }
        $published = self::get_published_version($assignment->get('templateid'));
        $record = self::create_record(
            $assignmentid,
            $published->get('id'),
            $userid,
            self::normalise_label($label),
            $duedate
        );
        self::send_started($record);

        return $record;
    }

    /**
     * Tell the appraisee a cycle has been created for them, when the version has anything for them to complete.
     *
     * @param record $record The new record.
     * @return void
     */
    private static function send_started(record $record): void {
        if (!self::version_has_appraisee_fields($record->get('template_versionid'))) {
            return;
        }
        $assignment = assignment::get_record(['id' => $record->get('assignmentid')], MUST_EXIST);
        $variant = $record->get('duedate') === null ? null : 'starteddue';
        messaging::send('started', $assignment->get('userid'), $record, $variant);
    }

    /**
     * Send the due date reminders that have come round, once each.
     *
     * A record is reminded when it is open, has a due date that has not passed, its version asks for a reminder and the
     * calendar day that many days before the due date has begun (a due date is stored as the end of its day, so a
     * 7 day reminder for a cycle due 12 March goes out on 5 March), and it has not been reminded since its due date was last
     * set. The appraiser is told; the appraisee too, while their part is still open (the record is Not started or In
     * progress and the version has appraisee fields). The record is stamped in the same transaction as the send, and
     * every candidate is checked again under its lock, so a second run, or an overlapping one, sends nothing more.
     *
     * @param int $now The time the reminders are worked out for.
     * @return int How many records were reminded.
     */
    public static function send_due_reminders(int $now): int {
        global $DB;

        $sql = "SELECT r.id
                  FROM {" . record::TABLE . "} r
                  JOIN {" . template_version::TABLE . "} v ON v.id = r.template_versionid
                 WHERE r.status <> :completed
                   AND r.timereminded IS NULL
                   AND r.duedate IS NOT NULL
                   AND r.duedate >= :notyetdue
                   AND v.reminderdays IS NOT NULL
                   AND r.duedate - ((v.reminderdays + 1) * :daysecs) < :now
              ORDER BY r.id";
        $recordids = $DB->get_fieldset_sql($sql, [
            'completed' => record::STATUS_COMPLETED,
            'notyetdue' => $now,
            'daysecs' => DAYSECS,
            'now' => $now,
        ]);

        $reminded = 0;
        foreach ($recordids as $recordid) {
            try {
                $reminded += self::run_locked((int) $recordid, function () use ($recordid, $now): int {
                    $ctx = self::get_record_context((int) $recordid);
                    $record = $ctx->record;
                    if (!self::is_reminder_due($record, $ctx->version, $now)) {
                        return 0;
                    }
                    $record->set('timereminded', $now);
                    $record->update();

                    messaging::send('duereminder', $record->get('appraiserid'), $record);
                    $appraiseepartopen = in_array(
                        $record->get('status'),
                        [record::STATUS_NOT_STARTED, record::STATUS_IN_PROGRESS],
                        true
                    );
                    if ($appraiseepartopen && self::version_has_appraisee_fields($ctx->version->get('id'))) {
                        messaging::send('duereminder', $ctx->assignment->get('userid'), $record, 'duereminderappraisee');
                    }
                    return 1;
                });
            } catch (moodle_exception $exception) {
                // A record cancelled since the query, or one that stays locked, must not stop the others.
                mtrace("local_appraisal: reminder for record {$recordid} failed: " . $exception->getMessage());
            }
        }
        return $reminded;
    }

    /**
     * Whether a record's due date reminder is due now and has not been sent.
     *
     * @param record $record
     * @param template_version $version The record's version, which holds the reminder lead time.
     * @param int $now
     * @return bool
     */
    private static function is_reminder_due(record $record, template_version $version, int $now): bool {
        $duedate = $record->get('duedate');
        $reminderdays = $version->get('reminderdays');
        return $record->is_open()
            && $record->get('timereminded') === null
            && $duedate !== null
            && $reminderdays !== null
            && $duedate >= $now
            && $duedate - ($reminderdays + 1) * DAYSECS < $now;
    }

    /**
     * Remove an assignment that has no records left, as when its only cycle was cancelled.
     *
     * @param int $assignmentid
     * @param int $userid The appraiser removing it.
     * @return void
     * @throws moodle_exception If the user cannot appraise the appraisee or the assignment still has records.
     */
    public static function remove_assignment(int $assignmentid, int $userid): void {
        $assignment = self::get_assignment($assignmentid);
        if (!access::can_appraise($userid, $assignment->get('userid'))) {
            throw new moodle_exception('error:nodashboardaccess', 'local_appraisal');
        }
        self::run_locked_on('assignment' . $assignmentid, function () use ($assignment, $assignmentid): void {
            if (self::get_remove_blocker($assignmentid)) {
                throw new moodle_exception('error:assignmenthasrecords', 'local_appraisal');
            }
            $assignment->delete();
        });
    }

    /**
     * Why an assignment can't be removed, or null if it can: it still has cycles.
     *
     * @param int $assignmentid
     * @return string|null A local_appraisal error string key.
     */
    public static function get_remove_blocker(int $assignmentid): ?string {
        return record::count_records(['assignmentid' => $assignmentid]) > 0 ? 'error:assignmenthasrecords' : null;
    }

    /**
     * How much deleting an assignment would remove: its cycles, and how many of them were signed off.
     *
     * @param int $assignmentid
     * @return \stdClass With records and completed counts.
     */
    public static function get_assignment_deletion_summary(int $assignmentid): \stdClass {
        return (object) [
            'records' => record::count_records(['assignmentid' => $assignmentid]),
            'completed' => record::count_records(['assignmentid' => $assignmentid, 'status' => record::STATUS_COMPLETED]),
        ];
    }

    /**
     * Permanently delete an assignment with every cycle on it and their answers, history and transfers, in one
     * transaction.
     *
     * Callers check the capabilities first (see access::can_delete_appraisal()). The event keeps the template name and
     * counts, since the rows they came from are gone. The privacy provider passes $fireevent as false: the event
     * names the appraisee as its related user, so logging it while erasing that user's data would write back what the
     * request is removing.
     *
     * @param int $assignmentid
     * @param bool $fireevent Whether to trigger assignment_deleted; false for privacy deletions.
     * @return void
     */
    public static function delete_assignment(int $assignmentid, bool $fireevent = true): void {
        $assignment = self::get_assignment($assignmentid);
        $template = self::get_template($assignment->get('templateid'));
        $summary = self::get_assignment_deletion_summary($assignmentid);
        self::run_locked_on('assignment' . $assignmentid, function () use ($assignmentid): void {
            self::delete_assignment_data($assignmentid);
        });
        if ($fireevent) {
            event\assignment_deleted::create([
                'objectid' => $assignmentid,
                'context' => system::instance(),
                'relateduserid' => $assignment->get('userid'),
                'other' => [
                    'templateid' => $template->get('id'),
                    'templatename' => $template->get('name'),
                    'records' => $summary->records,
                    'completed' => $summary->completed,
                ],
            ])->trigger();
        }
    }

    /**
     * The assignment's latest Completed cycle other than the given record, for the "Previous appraisal" line.
     *
     * @param record $record
     * @return record|null
     */
    public static function get_previous_record(record $record): ?record {
        $records = record::get_records_select(
            'assignmentid = :assignmentid AND status = :completed AND id <> :id',
            ['assignmentid' => $record->get('assignmentid'), 'completed' => record::STATUS_COMPLETED, 'id' => $record->get('id')],
            'timecompleted DESC, id DESC',
            '*',
            0,
            1
        );
        return $records ? reset($records) : null;
    }

    /**
     * Read the saved answers of a record.
     *
     * @param int $recordid
     * @return record_answer[]
     */
    public static function get_answers(int $recordid): array {
        return record_answer::get_records(['recordid' => $recordid], 'id');
    }

    /**
     * Link two cohorts: members of the first can appraise members of the second.
     *
     * @param int $appraisercohortid
     * @param int $appraiseecohortid
     * @return cohort_link
     * @throws moodle_exception If either cohort does not exist or the pair is already linked. A cohort linked to
     *     itself is refused by the persistent.
     */
    public static function create_cohort_link(int $appraisercohortid, int $appraiseecohortid): cohort_link {
        global $DB;

        foreach ([$appraisercohortid, $appraiseecohortid] as $cohortid) {
            if (!$DB->record_exists('cohort', ['id' => $cohortid])) {
                throw new moodle_exception('error:cohortmissing', 'local_appraisal');
            }
        }
        $exists = cohort_link::record_exists_select(
            'appraisercohortid = ? AND appraiseecohortid = ?',
            [$appraisercohortid, $appraiseecohortid]
        );
        if ($exists) {
            throw new moodle_exception('error:linkexists', 'local_appraisal');
        }
        $link = new cohort_link(0, (object) [
            'appraisercohortid' => $appraisercohortid,
            'appraiseecohortid' => $appraiseecohortid,
        ]);
        $link->create();
        event\cohort_link_created::create([
            'objectid' => $link->get('id'),
            'context' => system::instance(),
            'other' => [
                'appraisercohortid' => $appraisercohortid,
                'appraiseecohortid' => $appraiseecohortid,
            ],
        ])->trigger();

        return $link;
    }

    /**
     * Read all cohort links.
     *
     * @return cohort_link[]
     */
    public static function get_cohort_links(): array {
        return cohort_link::get_records([], 'id');
    }

    /**
     * Read a cohort link.
     *
     * @param int $linkid
     * @return cohort_link
     */
    public static function get_cohort_link(int $linkid): cohort_link {
        return cohort_link::get_record(['id' => $linkid], MUST_EXIST);
    }

    /**
     * Every cohort link with both cohorts' names and member counts, for the Appraiser cohorts page.
     *
     * @return \stdClass[] Each with id, appraisercohortid, appraisername, appraisercount, appraiseecohortid,
     *     appraiseename and appraiseecount. A cohort missing from core's table is left out.
     */
    public static function get_cohort_link_rows(): array {
        global $DB;

        $sql = "SELECT cl.id,
                       cl.appraisercohortid,
                       ac.name AS appraisername,
                       (SELECT COUNT(1)
                          FROM {cohort_members} am
                         WHERE am.cohortid = cl.appraisercohortid) AS appraisercount,
                       cl.appraiseecohortid,
                       ec.name AS appraiseename,
                       (SELECT COUNT(1)
                          FROM {cohort_members} em
                         WHERE em.cohortid = cl.appraiseecohortid) AS appraiseecount
                  FROM {" . cohort_link::TABLE . "} cl
                  JOIN {cohort} ac ON ac.id = cl.appraisercohortid
                  JOIN {cohort} ec ON ec.id = cl.appraiseecohortid
              ORDER BY ac.name, ec.name, cl.id";
        $rows = $DB->get_records_sql($sql);
        foreach ($rows as $row) {
            $row->appraisercount = (int) $row->appraisercount;
            $row->appraiseecount = (int) $row->appraiseecount;
        }
        return $rows;
    }

    /**
     * Delete a cohort link, so its appraisers can no longer reach its appraisees through it.
     *
     * Records the appraisers own stay as they are; they are frozen until someone who can reach the appraisee takes
     * ownership.
     *
     * @param int $linkid
     * @return void
     */
    public static function delete_cohort_link(int $linkid): void {
        $link = self::get_cohort_link($linkid);
        $other = [
            'appraisercohortid' => $link->get('appraisercohortid'),
            'appraiseecohortid' => $link->get('appraiseecohortid'),
        ];
        $link->delete();
        event\cohort_link_deleted::create([
            'objectid' => $linkid,
            'context' => system::instance(),
            'other' => $other,
        ])->trigger();
    }

    /**
     * Delete every cohort link that names a cohort, as when core deletes the cohort.
     *
     * @param int $cohortid
     * @return int How many links were deleted.
     */
    public static function delete_links_for_cohort(int $cohortid): int {
        $links = cohort_link::get_records_select(
            'appraisercohortid = :appraiser OR appraiseecohortid = :appraisee',
            ['appraiser' => $cohortid, 'appraisee' => $cohortid]
        );
        foreach ($links as $link) {
            self::delete_cohort_link($link->get('id'));
        }
        return count($links);
    }

    /**
     * The current time, from Moodle's clock so tests can control it.
     *
     * @return int
     */
    protected static function now(): int {
        return di::get(clock::class)->time();
    }
}
