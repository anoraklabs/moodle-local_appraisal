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

use local_appraisal_generator;
use moodle_exception;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the template builder: templates, versions and their lifecycle, fields, sections, the layout, wording
 * corrections on a published version, and deleting a template.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(api::class)]
final class templates_test extends \advanced_testcase {
    /** @var local_appraisal_generator The plugin's data generator. */
    private local_appraisal_generator $gen;

    /**
     * Get the generator. Refusals inside api transactions must not be wrapped in the test's own transaction.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        $this->gen = $this->getDataGenerator()->get_plugin_generator('local_appraisal');
    }

    /**
     * Assert that a callable is refused with the given error code, and clear any transaction the refusal left open.
     *
     * @param string $errorcode
     * @param callable $work
     * @return void
     */
    private function assert_refused(string $errorcode, callable $work): void {
        global $DB;
        try {
            $work();
            $this->fail("Expected the error {$errorcode}.");
        } catch (moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        } finally {
            if ($DB->is_transaction_started()) {
                $DB->force_transaction_rollback();
            }
        }
    }

    /**
     * Assert that a callable is refused by the persistent's own validation with the given message.
     *
     * @param string $message Text expected in the message, or a property name followed by a colon (for a refused choice).
     * @param callable $work
     * @return void
     */
    private function assert_invalid(string $message, callable $work): void {
        global $DB;
        try {
            $work();
            $this->fail('Expected the persistent to refuse it.');
        } catch (\core\invalid_persistent_exception $e) {
            $this->assertStringContainsString($message, $e->a . ' | ' . $e->debuginfo);
        } finally {
            if ($DB->is_transaction_started()) {
                $DB->force_transaction_rollback();
            }
        }
    }

    /**
     * The field labels of a version in display order.
     *
     * @param int $versionid
     * @return string[]
     */
    private function labels(int $versionid): array {
        return array_values(array_map(
            fn(template_field $field): string => $field->get('label'),
            api::get_fields($versionid)
        ));
    }

    /**
     * The sort orders of a version's fields, which must always be 1 to N.
     *
     * @param int $versionid
     * @return int[]
     */
    private function sortorders(int $versionid): array {
        return array_values(array_map(
            fn(template_field $field): int => $field->get('sortorder'),
            api::get_fields($versionid)
        ));
    }

    /**
     * A new template starts active with a version 1 draft and nothing published.
     *
     * @return void
     */
    public function test_create_template(): void {
        $template = api::create_template('Annual review');

        $this->assertTrue($template->is_active());
        $draft = api::get_draft($template->get('id'));
        $this->assertSame(1, $draft->get('versionnumber'));
        $this->assertTrue($draft->is_draft());
        $this->assertNull(api::get_published_version($template->get('id')));
        $this->assertSame(template_version::COMMENTS_OPTIONAL, $draft->get('commentsmode'));
        $this->assertSame(template_version::GRADE_OFF, $draft->get('overallgrademode'));
        $this->assertNull($draft->get('reminderdays'));
        $this->assert_invalid(get_string('required'), fn(): mixed => api::create_template(' '));
        $this->assertSame(1, template::count_records());
    }

    /**
     * Renaming, archiving and unarchiving change only the template.
     *
     * @return void
     */
    public function test_rename_archive_and_unarchive(): void {
        $built = $this->gen->create_template(['name' => 'Old', 'fields' => [['label' => 'Goals']]]);
        $id = $built->template->get('id');

        $this->assertSame('New', api::update_template($id, 'New')->get('name'));
        api::archive_template($id);
        $this->assertSame(template::STATUS_ARCHIVED, api::get_template($id)->get('status'));
        $this->assertNotNull(api::get_published_version($id));
        api::unarchive_template($id);
        $this->assertTrue(api::get_template($id)->is_active());
        $this->assert_invalid(get_string('required'), fn(): mixed => api::update_template($id, ''));
    }

    /**
     * Fields are appended in order and the sort order stays 1 to N.
     *
     * @return void
     */
    public function test_fields_are_appended_in_order(): void {
        $template = api::create_template('T');
        $versionid = api::get_draft($template->get('id'))->get('id');

        api::add_field($versionid, template_field::TYPE_SHORTTEXT, 'First');
        api::add_field($versionid, template_field::TYPE_LONGTEXT, 'Second', template_field::COMPLETEDBY_BOTH, false);
        api::add_field($versionid, template_field::TYPE_DATE, 'Third', template_field::COMPLETEDBY_APPRAISEE);

        $this->assertSame(['First', 'Second', 'Third'], $this->labels($versionid));
        $this->assertSame([1, 2, 3], $this->sortorders($versionid));
        $second = array_values(api::get_fields($versionid))[1];
        $this->assertFalse((bool) $second->get('required'));
        $this->assertSame(template_field::COMPLETEDBY_BOTH, $second->get('completedby'));
    }

    /**
     * Every field type can be built, and each keeps its settings.
     *
     * @return void
     */
    public function test_every_field_type_can_be_built(): void {
        $scale = $this->gen->create_scale();
        $built = $this->gen->create_template([
            'fields' => [
                ['type' => template_field::TYPE_SHORTTEXT, 'label' => 'Short'],
                ['type' => template_field::TYPE_LONGTEXT, 'label' => 'Long'],
                ['type' => template_field::TYPE_SINGLECHOICE, 'label' => 'One', 'options' => "A\nB", 'display' => 'select'],
                ['type' => template_field::TYPE_MULTICHOICE, 'label' => 'Many', 'options' => "A\nB\nC"],
                ['type' => template_field::TYPE_SCALE, 'label' => 'Rate', 'scaleid' => $scale->get('id')],
                ['type' => template_field::TYPE_DATE, 'label' => 'When'],
                ['type' => template_field::TYPE_NUMBER, 'label' => 'How many', 'min' => 0, 'max' => 10, 'decimals' => 1],
            ],
        ]);

        $this->assertCount(7, $built->fields);
        $this->assertSame('select', $built->fields['One']->get_config()['display']);
        $this->assertSame([1 => 'A', 2 => 'B', 3 => 'C'], $built->fields['Many']->get_options());
        $this->assertSame($scale->get('id'), $built->fields['Rate']->get('scaleid'));
        $this->assertSame(['min' => 0, 'max' => 10, 'decimals' => 1], $built->fields['How many']->get_config());
        $this->assertTrue($built->version->is_published());
    }

    /**
     * A graded rating needs a usable scale; no other type takes one; archived and one-level scales are refused.
     *
     * @return void
     */
    public function test_scale_rules_on_fields(): void {
        $template = api::create_template('T');
        $versionid = api::get_draft($template->get('id'))->get('id');
        $good = $this->gen->create_scale();
        $archived = $this->gen->create_scale();
        api::archive_scale($archived->get('id'));
        $short = $this->gen->create_scale(['levels' => ['Only' => 1]]);
        $scale = template_field::TYPE_SCALE;

        $this->assert_invalid(
            get_string('error:scalerequired', 'local_appraisal'),
            fn(): mixed => api::add_field($versionid, $scale, 'Rate')
        );
        $this->assert_invalid(
            get_string('error:scalenotallowed', 'local_appraisal'),
            fn(): mixed => api::add_field($versionid, template_field::TYPE_SHORTTEXT, 'Text', 1, true, null, $good->get('id'))
        );
        $this->assert_refused(
            'error:scalenotusable',
            fn(): mixed => api::add_field($versionid, $scale, 'Rate', 1, true, null, $archived->get('id'))
        );
        $this->assert_refused(
            'error:scalenotusable',
            fn(): mixed => api::add_field($versionid, $scale, 'Rate', 1, true, null, $short->get('id'))
        );
        $this->assert_refused(
            'error:scalenotusable',
            fn(): mixed => api::add_field($versionid, $scale, 'Rate', 1, true, null, $good->get('id') + 1000)
        );
        $this->assertSame([], api::get_fields($versionid));
        $this->assertNotNull(api::add_field($versionid, $scale, 'Rate', 1, true, null, $good->get('id')));
    }

    /**
     * The persistent refuses a blank label, an unknown type and an unknown completed-by value.
     *
     * @return void
     */
    public function test_field_persistent_rules(): void {
        $template = api::create_template('T');
        $versionid = api::get_draft($template->get('id'))->get('id');

        $this->assert_invalid(get_string('required'), fn(): mixed => api::add_field($versionid, 1, '   '));
        $this->assert_invalid('type:', fn(): mixed => api::add_field($versionid, 99, 'Odd'));
        $this->assert_invalid('completedby:', fn(): mixed => api::add_field($versionid, 1, 'Odd', 9));
        $this->assertSame([], api::get_fields($versionid));
    }

    /**
     * Editing a field on a draft changes its type, settings and scale, and re-checks the type's rules.
     *
     * @return void
     */
    public function test_update_field_on_a_draft(): void {
        $scale = $this->gen->create_scale();
        $built = $this->gen->create_template(['publish' => false, 'fields' => [['label' => 'Goals']]]);
        $field = $built->fields['Goals'];

        $updated = api::update_field(
            $field->get('id'),
            template_field::TYPE_SCALE,
            'Rating',
            template_field::COMPLETEDBY_BOTH,
            false,
            null,
            $scale->get('id'),
            null,
            'Pick one'
        );

        $this->assertSame(template_field::TYPE_SCALE, $updated->get('type'));
        $this->assertSame('Rating', $updated->get('label'));
        $this->assertSame('Pick one', $updated->get('description'));
        $this->assertSame($scale->get('id'), $updated->get('scaleid'));

        $this->assert_invalid(
            get_string('error:scalerequired', 'local_appraisal'),
            fn(): mixed => api::update_field($field->get('id'), template_field::TYPE_SCALE, 'Rating', 1, true)
        );
        $this->assertSame('Rating', template_field::get_record(['id' => $field->get('id')])->get('label'));
    }

    /**
     * An archived scale can stay on a field that already uses it, but cannot be newly chosen.
     *
     * @return void
     */
    public function test_a_field_may_keep_a_scale_archived_since(): void {
        $scale = $this->gen->create_scale();
        $built = $this->gen->create_template([
            'publish' => false,
            'fields' => [['type' => template_field::TYPE_SCALE, 'label' => 'Rate', 'scaleid' => $scale->get('id')]],
        ]);
        api::archive_scale($scale->get('id'));
        $field = $built->fields['Rate'];

        $kept = api::update_field(
            $field->get('id'),
            template_field::TYPE_SCALE,
            'Rated',
            1,
            true,
            null,
            $scale->get('id')
        );
        $this->assertSame('Rated', $kept->get('label'));

        $other = $this->gen->create_scale();
        api::archive_scale($other->get('id'));
        $this->assert_refused(
            'error:scalenotusable',
            fn(): mixed => api::update_field(
                $field->get('id'),
                template_field::TYPE_SCALE,
                'Rated',
                1,
                true,
                null,
                $other->get('id')
            )
        );
    }

    /**
     * Deleting a field closes the gap in the ordering.
     *
     * @return void
     */
    public function test_delete_field_repacks(): void {
        $built = $this->gen->create_template([
            'publish' => false,
            'fields' => [['label' => 'A'], ['label' => 'B'], ['label' => 'C']],
        ]);
        $versionid = $built->version->get('id');

        api::delete_field($built->fields['B']->get('id'));

        $this->assertSame(['A', 'C'], $this->labels($versionid));
        $this->assertSame([1, 2], $this->sortorders($versionid));
    }

    /**
     * A published or archived version refuses every structural change.
     *
     * @return void
     */
    public function test_only_a_draft_can_be_changed(): void {
        $built = $this->gen->create_template(['fields' => [['label' => 'A'], ['label' => 'B']]]);
        $versionid = $built->version->get('id');
        $fieldid = $built->fields['A']->get('id');
        $section = api::add_section(api::create_draft($built->template->get('id'))->get('id'), 'Part');
        $sectionid = $section->get('id');
        $draftid = $section->get('template_versionid');
        api::publish_version($draftid);

        // The first version is archived now, the second published.
        foreach ([$versionid, $draftid] as $closed) {
            $this->assertFalse(api::get_version($closed)->is_draft());
            $this->assert_refused('error:notdraft', fn(): mixed => api::add_field($closed, 1, 'New'));
            $this->assert_refused('error:notdraft', fn(): mixed => api::add_section($closed, 'New'));
            $this->assert_refused('error:notdraft', fn(): mixed => api::remove_sections($closed));
            $this->assert_refused('error:notdraft', fn(): mixed => api::reorder_layout($closed, [['id' => 0, 'fieldids' => []]]));
            $this->assert_refused('error:notdraft', fn(): mixed => api::discard_draft($closed));
            $this->assert_refused('error:notdraft', fn(): mixed => api::publish_version($closed));
            $this->assert_refused(
                'error:notdraft',
                fn(): mixed => api::update_version_settings($closed, null, FORMAT_HTML, 2, 1, null, null)
            );
        }
        $this->assert_refused('error:notdraft', fn(): mixed => api::update_field($fieldid, 1, 'Changed', 1, true));
        $this->assert_refused('error:notdraft', fn(): mixed => api::delete_field($fieldid));
        $this->assert_refused('error:notdraft', fn(): mixed => api::update_section($sectionid, 'Changed'));
        $this->assert_refused('error:notdraft', fn(): mixed => api::delete_section($sectionid));
        $this->assertSame(['A', 'B'], $this->labels($versionid));
    }

    /**
     * A version needs at least one field to be published.
     *
     * @return void
     */
    public function test_publish_needs_a_field(): void {
        $template = api::create_template('Empty');
        $draft = api::get_draft($template->get('id'));

        $this->assert_refused('error:nofields', fn(): mixed => api::publish_version($draft->get('id')));
        $this->assertTrue(api::get_version($draft->get('id'))->is_draft());
    }

    /**
     * A version with an empty section cannot be published until the section has a field.
     *
     * @return void
     */
    public function test_publish_refuses_an_empty_section(): void {
        $built = $this->gen->create_template(['publish' => false, 'fields' => [['label' => 'A']]]);
        $versionid = $built->version->get('id');
        api::add_section($versionid, 'One');
        $two = api::add_section($versionid, 'Two');

        $this->assertTrue(api::has_empty_section($versionid));
        $this->assert_refused('error:emptysection', fn(): mixed => api::publish_version($versionid));

        api::add_field($versionid, template_field::TYPE_SHORTTEXT, 'B', 1, true, $two->get('id'));
        $this->assertFalse(api::has_empty_section($versionid));
        $this->assertTrue(api::publish_version($versionid)->is_published());
    }

    /**
     * Publishing needs every scale the version uses to still have two levels.
     *
     * @return void
     */
    public function test_publish_needs_scales_with_two_levels(): void {
        $scale = $this->gen->create_scale();
        $built = $this->gen->create_template([
            'publish' => false,
            'gradingmode' => template_version::GRADE_OPTIONAL,
            'overallscaleid' => $scale->get('id'),
            'fields' => [['label' => 'A']],
        ]);
        $levels = array_values(api::get_scale_levels($scale->get('id')));
        api::delete_scale_level($levels[0]->get('id'));
        api::delete_scale_level($levels[1]->get('id'));

        $this->assert_refused('error:scalenotusable', fn(): mixed => api::publish_version($built->version->get('id')));

        api::add_scale_level($scale->get('id'), 'Again', 50);
        $this->assertTrue(api::publish_version($built->version->get('id'))->is_published());
    }

    /**
     * Publishing a new draft archives the previously published version in the same step.
     *
     * @return void
     */
    public function test_publish_archives_the_previous_version(): void {
        $built = $this->gen->create_template(['fields' => [['label' => 'A']]]);
        $templateid = $built->template->get('id');

        $draft = api::create_draft($templateid);
        $this->assertSame(2, $draft->get('versionnumber'));
        api::add_field($draft->get('id'), template_field::TYPE_SHORTTEXT, 'B');
        api::publish_version($draft->get('id'));

        $versions = api::get_versions($templateid);
        $this->assertSame([2, 1], array_values(array_map(fn(template_version $v): int => $v->get('versionnumber'), $versions)));
        $statuses = array_values(array_map(fn(template_version $v): int => $v->get('status'), $versions));
        $this->assertSame([template_version::STATUS_PUBLISHED, template_version::STATUS_ARCHIVED], $statuses);
        $this->assertSame($draft->get('id'), api::get_published_version($templateid)->get('id'));
        $this->assertNull(api::get_draft($templateid));
        $this->assertSame(['A'], $this->labels($built->version->get('id')));
    }

    /**
     * There is one draft at a time, and a new draft copies the published version's settings, sections and fields.
     *
     * @return void
     */
    public function test_new_draft_copies_the_published_version(): void {
        $scale = $this->gen->create_scale();
        $built = $this->gen->create_template([
            'overview' => '<p>Welcome</p>',
            'commentsmode' => template_version::COMMENTS_REQUIRED,
            'gradingmode' => template_version::GRADE_REQUIRED,
            'overallscaleid' => $scale->get('id'),
            'reminderdays' => 7,
            'fields' => [
                ['type' => template_field::TYPE_MULTICHOICE, 'label' => 'Skills', 'options' => "X\nY"],
                ['label' => 'Notes', 'required' => false, 'completedby' => template_field::COMPLETEDBY_BOTH],
            ],
        ]);
        $templateid = $built->template->get('id');

        $draft = api::create_draft($templateid);
        $this->assert_refused('error:draftexists', fn(): mixed => api::create_draft($templateid));

        $this->assertSame('<p>Welcome</p>', $draft->get('overview'));
        $this->assertSame(template_version::COMMENTS_REQUIRED, $draft->get('commentsmode'));
        $this->assertSame(template_version::GRADE_REQUIRED, $draft->get('overallgrademode'));
        $this->assertSame($scale->get('id'), $draft->get('overallscaleid'));
        $this->assertSame(7, $draft->get('reminderdays'));
        $this->assertSame(['Skills', 'Notes'], $this->labels($draft->get('id')));
        $copies = array_values(api::get_fields($draft->get('id')));
        $originals = array_values(api::get_fields($built->version->get('id')));
        $this->assertNotSame($originals[0]->get('id'), $copies[0]->get('id'));
        $this->assertSame($originals[0]->get('configdata'), $copies[0]->get('configdata'));
        $this->assertFalse((bool) $copies[1]->get('required'));
        $this->assertSame(template_field::COMPLETEDBY_BOTH, $copies[1]->get('completedby'));
    }

    /**
     * A new draft keeps the option keys, so answers stored against the published version still mean the same.
     *
     * @return void
     */
    public function test_new_draft_keeps_option_keys(): void {
        $built = $this->gen->create_template([
            'fields' => [['type' => template_field::TYPE_SINGLECHOICE, 'label' => 'Pick', 'options' => "Red\nGreen\nBlue"]],
        ]);
        $draft = api::create_draft($built->template->get('id'));
        $field = array_values(api::get_fields($draft->get('id')))[0];

        $this->assertSame([1 => 'Red', 2 => 'Green', 3 => 'Blue'], $field->get_options());

        // Dropping the middle option and adding another keeps the old keys and gives the new one a fresh key.
        $configdata = template_field::build_configdata(
            template_field::TYPE_SINGLECHOICE,
            ['options' => "Red\nBlue\nPink", 'display' => 'radio'],
            $field->get('configdata')
        );
        $type = template_field::TYPE_SINGLECHOICE;
        $updated = api::update_field($field->get('id'), $type, 'Pick', 1, true, null, null, $configdata);
        $this->assertSame([1 => 'Red', 3 => 'Blue', 4 => 'Pink'], $updated->get_options());
    }

    /**
     * Copying a version copies sections too, with each field in the copy of its own section.
     *
     * @return void
     */
    public function test_new_draft_copies_sections(): void {
        $built = $this->gen->create_template(['publish' => false, 'fields' => [['label' => 'A']]]);
        $versionid = $built->version->get('id');
        $one = api::add_section($versionid, 'One', '<p>First</p>');
        $two = api::add_section($versionid, 'Two');
        api::add_field($versionid, template_field::TYPE_SHORTTEXT, 'B', 1, true, $two->get('id'));
        api::publish_version($versionid);

        $draft = api::create_draft($built->template->get('id'));
        $sections = array_values(api::get_sections($draft->get('id')));

        $this->assertSame(['One', 'Two'], array_map(fn(template_section $s): string => $s->get('name'), $sections));
        $this->assertSame('<p>First</p>', $sections[0]->get('description'));
        $this->assertNotSame($one->get('id'), $sections[0]->get('id'));
        $fields = array_values(api::get_fields($draft->get('id')));
        $this->assertSame($sections[0]->get('id'), $fields[0]->get('sectionid'));
        $this->assertSame($sections[1]->get('id'), $fields[1]->get('sectionid'));
    }

    /**
     * A template with no published version gets an empty draft; a discarded draft goes with its fields and sections.
     *
     * @return void
     */
    public function test_discard_draft(): void {
        $built = $this->gen->create_template(['fields' => [['label' => 'A']]]);
        $templateid = $built->template->get('id');
        $draft = api::create_draft($templateid);
        api::add_section($draft->get('id'), 'Part');
        $draftid = $draft->get('id');

        api::discard_draft($draftid);

        $this->assertNull(api::get_draft($templateid));
        $this->assertFalse(template_version::record_exists($draftid));
        $this->assertSame([], api::get_fields($draftid));
        $this->assertSame([], api::get_sections($draftid));
        $this->assertSame(['A'], $this->labels($built->version->get('id')));
        $this->assertSame(2, api::create_draft($templateid)->get('versionnumber'));
    }

    /**
     * Version settings validate the grade mode against its scale, and a reminder against one day.
     *
     * @return void
     */
    public function test_version_settings_rules(): void {
        $scale = $this->gen->create_scale();
        $archived = $this->gen->create_scale();
        api::archive_scale($archived->get('id'));
        $built = $this->gen->create_template(['publish' => false, 'fields' => [['label' => 'A']]]);
        $versionid = $built->version->get('id');
        $optional = template_version::GRADE_OPTIONAL;

        $this->assert_invalid(
            get_string('error:overallscalerequired', 'local_appraisal'),
            fn(): mixed => api::update_version_settings($versionid, null, FORMAT_HTML, 2, $optional, null, null)
        );
        $this->assert_refused(
            'error:scalenotusable',
            fn(): mixed => api::update_version_settings($versionid, null, FORMAT_HTML, 2, $optional, $archived->get('id'), null)
        );
        $this->assert_invalid(
            get_string('error:reminderdayspositive', 'local_appraisal'),
            fn(): mixed => api::update_version_settings($versionid, null, FORMAT_HTML, 2, 1, null, 0)
        );
        $this->assert_invalid(
            'commentsmode:',
            fn(): mixed => api::update_version_settings($versionid, null, FORMAT_HTML, 9, 1, null, null)
        );

        $saved = api::update_version_settings($versionid, '<p>Hi</p>', FORMAT_HTML, 3, $optional, $scale->get('id'), 14);
        $this->assertSame($scale->get('id'), $saved->get('overallscaleid'));
        $this->assertSame(14, $saved->get('reminderdays'));

        // Turning the grade off forgets the scale, and an empty editor stores null.
        $gradeoff = template_version::GRADE_OFF;
        $off = api::update_version_settings($versionid, '<p> </p>', FORMAT_HTML, 1, $gradeoff, $scale->get('id'), null);
        $this->assertNull($off->get('overallscaleid'));
        $this->assertNull($off->get('overview'));
        $this->assertNull($off->get('reminderdays'));
    }

    /**
     * A scale archived after it was chosen stays on the version; it is only refused when newly chosen.
     *
     * @return void
     */
    public function test_overall_scale_archived_since_stays(): void {
        $scale = $this->gen->create_scale();
        $built = $this->gen->create_template([
            'publish' => false,
            'gradingmode' => template_version::GRADE_OPTIONAL,
            'overallscaleid' => $scale->get('id'),
            'fields' => [['label' => 'A']],
        ]);
        api::archive_scale($scale->get('id'));

        $saved = api::update_version_settings(
            $built->version->get('id'),
            null,
            FORMAT_HTML,
            2,
            template_version::GRADE_REQUIRED,
            $scale->get('id'),
            null
        );

        $this->assertSame(template_version::GRADE_REQUIRED, $saved->get('overallgrademode'));
    }

    /**
     * Sections are all or nothing: the first one takes every existing field, later ones start empty.
     *
     * @return void
     */
    public function test_first_section_takes_every_field(): void {
        $built = $this->gen->create_template([
            'publish' => false,
            'fields' => [['label' => 'A'], ['label' => 'B']],
        ]);
        $versionid = $built->version->get('id');

        $one = api::add_section($versionid, 'One');
        foreach (api::get_fields($versionid) as $field) {
            $this->assertSame($one->get('id'), $field->get('sectionid'));
        }
        $two = api::add_section($versionid, 'Two');
        $orders = array_map(fn(template_section $s): int => $s->get('sortorder'), api::get_sections($versionid));
        $this->assertSame([1, 2], array_values($orders));
        $this->assertTrue(api::has_empty_section($versionid));

        // A field must say which section it goes in once sections exist.
        $this->assert_refused('error:sectionrequired', fn(): mixed => api::add_field($versionid, 1, 'C'));
        $missing = $two->get('id') + 1000;
        $this->assert_refused('error:sectionrequired', fn(): mixed => api::add_field($versionid, 1, 'C', 1, true, $missing));
        $added = api::add_field($versionid, 1, 'C', 1, true, $two->get('id'));
        $this->assertSame($two->get('id'), $added->get('sectionid'));
        $this->assertSame(['A', 'B', 'C'], $this->labels($versionid));
    }

    /**
     * A field cannot be put in a section of another version, and without sections the section is ignored.
     *
     * @return void
     */
    public function test_section_must_belong_to_the_version(): void {
        $one = $this->gen->create_template(['publish' => false, 'fields' => [['label' => 'A']]]);
        $two = $this->gen->create_template(['publish' => false, 'fields' => [['label' => 'B']]]);
        $foreign = api::add_section($two->version->get('id'), 'Elsewhere');
        $own = api::add_section($one->version->get('id'), 'Own');

        $this->assert_refused(
            'error:sectionrequired',
            fn(): mixed => api::add_field($one->version->get('id'), 1, 'C', 1, true, $foreign->get('id'))
        );
        $this->assertNotNull(api::add_field($one->version->get('id'), 1, 'C', 1, true, $own->get('id')));

        $plain = $this->gen->create_template(['publish' => false, 'fields' => [['label' => 'P']]]);
        $field = api::add_field($plain->version->get('id'), 1, 'Q', 1, true, $foreign->get('id'));
        $this->assertNull($field->get('sectionid'));
    }

    /**
     * Updating a field into another section moves it to the end of that section's order.
     *
     * @return void
     */
    public function test_update_field_moves_between_sections(): void {
        $built = $this->gen->create_template(['publish' => false, 'fields' => [['label' => 'A'], ['label' => 'B']]]);
        $versionid = $built->version->get('id');
        api::add_section($versionid, 'One');
        $two = api::add_section($versionid, 'Two');
        api::add_field($versionid, 1, 'C', 1, true, $two->get('id'));

        api::update_field($built->fields['A']->get('id'), 1, 'A', 1, true, $two->get('id'));

        $this->assertSame(['B', 'C', 'A'], $this->labels($versionid));
        $this->assertSame([1, 2, 3], $this->sortorders($versionid));
        $this->assertSame($two->get('id'), template_field::get_record(['id' => $built->fields['A']->get('id')])->get('sectionid'));
        $this->assert_refused(
            'error:sectionrequired',
            fn(): mixed => api::update_field($built->fields['B']->get('id'), 1, 'B', 1, true, $two->get('id') + 1000)
        );
    }

    /**
     * Deleting a section deletes its fields and repacks the rest; removing sections keeps the fields.
     *
     * @return void
     */
    public function test_delete_and_remove_sections(): void {
        $built = $this->gen->create_template(['publish' => false, 'fields' => [['label' => 'A']]]);
        $versionid = $built->version->get('id');
        api::add_section($versionid, 'One');
        $two = api::add_section($versionid, 'Two');
        $three = api::add_section($versionid, 'Three');
        api::add_field($versionid, 1, 'B', 1, true, $two->get('id'));
        api::add_field($versionid, 1, 'C', 1, true, $three->get('id'));

        api::delete_section($two->get('id'));

        $this->assertSame(['A', 'C'], $this->labels($versionid));
        $this->assertSame([1, 2], $this->sortorders($versionid));
        $remaining = array_values(api::get_sections($versionid));
        $this->assertSame(['One', 'Three'], array_map(fn(template_section $s): string => $s->get('name'), $remaining));
        $this->assertSame([1, 2], array_map(fn(template_section $s): int => $s->get('sortorder'), $remaining));

        api::remove_sections($versionid);
        $this->assertSame([], api::get_sections($versionid));
        $this->assertSame(['A', 'C'], $this->labels($versionid));
        foreach (api::get_fields($versionid) as $field) {
            $this->assertNull($field->get('sectionid'));
        }
    }

    /**
     * A section needs a name; its description is stored as HTML or null.
     *
     * @return void
     */
    public function test_section_rules(): void {
        $built = $this->gen->create_template(['publish' => false, 'fields' => [['label' => 'A']]]);
        $versionid = $built->version->get('id');

        $this->assert_invalid(get_string('required'), fn(): mixed => api::add_section($versionid, ' '));
        $section = api::add_section($versionid, 'Part', '<p>  </p>');
        $this->assertNull($section->get('description'));
        $updated = api::update_section($section->get('id'), 'Parts', '<p>Text</p>');
        $this->assertSame('Parts', $updated->get('name'));
        $this->assertSame('<p>Text</p>', $updated->get('description'));
    }

    /**
     * Reordering stores the new section order and each field's section and position, across sections.
     *
     * @return void
     */
    public function test_reorder_layout(): void {
        $built = $this->gen->create_template(['publish' => false, 'fields' => [['label' => 'A'], ['label' => 'B']]]);
        $versionid = $built->version->get('id');
        $one = api::add_section($versionid, 'One');
        $two = api::add_section($versionid, 'Two');
        $c = api::add_field($versionid, 1, 'C', 1, true, $two->get('id'));
        $a = $built->fields['A']->get('id');
        $b = $built->fields['B']->get('id');

        api::reorder_layout($versionid, [
            ['id' => $two->get('id'), 'fieldids' => [$b, $c->get('id')]],
            ['id' => $one->get('id'), 'fieldids' => [$a]],
        ]);

        $this->assertSame(['B', 'C', 'A'], $this->labels($versionid));
        $this->assertSame([1, 2, 3], $this->sortorders($versionid));
        $sections = array_values(api::get_sections($versionid));
        $this->assertSame(['Two', 'One'], array_map(fn(template_section $s): string => $s->get('name'), $sections));
        $this->assertSame($two->get('id'), template_field::get_record(['id' => $b])->get('sectionid'));
        $this->assertSame($one->get('id'), template_field::get_record(['id' => $a])->get('sectionid'));
    }

    /**
     * Reordering a version with no sections takes one entry with id 0.
     *
     * @return void
     */
    public function test_reorder_layout_without_sections(): void {
        $built = $this->gen->create_template([
            'publish' => false,
            'fields' => [['label' => 'A'], ['label' => 'B'], ['label' => 'C']],
        ]);
        $ids = array_map(fn(template_field $f): int => $f->get('id'), $built->fields);

        api::reorder_layout($built->version->get('id'), [
            ['id' => 0, 'fieldids' => [$ids['C'], $ids['A'], $ids['B']]],
        ]);

        $this->assertSame(['C', 'A', 'B'], $this->labels($built->version->get('id')));
        foreach (api::get_fields($built->version->get('id')) as $field) {
            $this->assertNull($field->get('sectionid'), 'Without sections the field has no section, not section 0.');
        }
    }

    /**
     * A layout must name exactly the version's sections and fields, each once, and none from another version.
     *
     * @return void
     */
    public function test_reorder_layout_must_match_the_version(): void {
        $built = $this->gen->create_template(['publish' => false, 'fields' => [['label' => 'A'], ['label' => 'B']]]);
        $other = $this->gen->create_template(['publish' => false, 'fields' => [['label' => 'X']]]);
        $versionid = $built->version->get('id');
        $a = $built->fields['A']->get('id');
        $b = $built->fields['B']->get('id');
        $x = $other->fields['X']->get('id');
        $one = api::add_section($versionid, 'One');
        $foreign = api::add_section($other->version->get('id'), 'Elsewhere');
        $sectionid = $one->get('id');

        $this->assert_refused(
            'error:sectionmismatch',
            fn(): mixed => api::reorder_layout($versionid, [['id' => 0, 'fieldids' => [$a, $b]]])
        );
        $this->assert_refused(
            'error:sectionmismatch',
            fn(): mixed => api::reorder_layout($versionid, [['id' => $foreign->get('id'), 'fieldids' => [$a, $b]]])
        );
        $this->assert_refused(
            'error:sectionmismatch',
            fn(): mixed => api::reorder_layout($versionid, [
                ['id' => $sectionid, 'fieldids' => [$a, $b]],
                ['id' => $sectionid, 'fieldids' => []],
            ])
        );
        $layout = fn(array $fieldids): mixed => api::reorder_layout($versionid, [['id' => $sectionid, 'fieldids' => $fieldids]]);
        $this->assert_refused('error:fieldmismatch', fn(): mixed => $layout([$a]));
        $this->assert_refused('error:fieldmismatch', fn(): mixed => $layout([$a, $a]));
        $this->assert_refused('error:fieldmismatch', fn(): mixed => $layout([$a, $b, $x]));
        $this->assert_refused('error:fieldmismatch', fn(): mixed => $layout([$a, $x]));

        $this->assertSame(['A', 'B'], $this->labels($versionid));
        $this->assertSame(['X'], $this->labels($other->version->get('id')));
    }

    /**
     * Wording corrections on the published version change the text only: label, description and option labels.
     *
     * @return void
     */
    public function test_correct_field_wording(): void {
        $built = $this->gen->create_template([
            'fields' => [
                ['type' => template_field::TYPE_SINGLECHOICE, 'label' => 'Pick', 'options' => "Yes\nNo", 'required' => false],
            ],
        ]);
        $field = $built->fields['Pick'];

        $corrected = api::correct_field_wording($field->get('id'), 'Choose', 'Help text', ['Aye', 'Nay']);

        $this->assertSame('Choose', $corrected->get('label'));
        $this->assertSame('Help text', $corrected->get('description'));
        $this->assertSame([1 => 'Aye', 2 => 'Nay'], $corrected->get_options());
        $this->assertSame(template_field::TYPE_SINGLECHOICE, $corrected->get('type'));
        $this->assertFalse((bool) $corrected->get('required'));
        $this->assertSame($field->get('completedby'), $corrected->get('completedby'));

        $this->assert_refused(
            'error:optionsatleasttwo',
            fn(): mixed => api::correct_field_wording($field->get('id'), 'Choose', null, ['Aye'])
        );
        $this->assert_refused(
            'error:optioncountfixed',
            fn(): mixed => api::correct_field_wording($field->get('id'), 'Choose', null, ['Aye', 'Nay', 'Maybe'])
        );
        $this->assert_refused(
            'error:optionsduplicate',
            fn(): mixed => api::correct_field_wording($field->get('id'), 'Choose', null, ['Aye', 'aye'])
        );
        $this->assertSame([1 => 'Aye', 2 => 'Nay'], template_field::get_record(['id' => $field->get('id')])->get_options());
        $this->assert_invalid(get_string('required'), fn(): mixed => api::correct_field_wording($field->get('id'), ' '));
    }

    /**
     * Wording can only be corrected on the published version: not on a draft and not on an archived one.
     *
     * @return void
     */
    public function test_wording_correction_is_published_only(): void {
        $built = $this->gen->create_template(['fields' => [['label' => 'A']]]);
        $draft = api::create_draft($built->template->get('id'));
        $draftfield = array_values(api::get_fields($draft->get('id')))[0];
        $draftsection = api::add_section($draft->get('id'), 'Part');

        $this->assert_refused('error:notpublished', fn(): mixed => api::correct_field_wording($draftfield->get('id'), 'Changed'));
        $sectionid = $draftsection->get('id');
        $this->assert_refused('error:notpublished', fn(): mixed => api::correct_section_wording($sectionid, 'Changed'));
        $this->assert_refused(
            'error:notpublished',
            fn(): mixed => api::correct_overview($draft->get('id'), '<p>x</p>', FORMAT_HTML)
        );

        api::publish_version($draft->get('id'));
        $archivedfield = $built->fields['A'];
        $this->assert_refused(
            'error:notpublished',
            fn(): mixed => api::correct_field_wording($archivedfield->get('id'), 'Changed')
        );
        $this->assert_refused(
            'error:notpublished',
            fn(): mixed => api::correct_overview($built->version->get('id'), '<p>x</p>', FORMAT_HTML)
        );
        $this->assertSame('A', template_field::get_record(['id' => $archivedfield->get('id')])->get('label'));
    }

    /**
     * A published section's name and description can be corrected, nothing else about it.
     *
     * @return void
     */
    public function test_correct_section_wording(): void {
        $built = $this->gen->create_template(['publish' => false, 'fields' => [['label' => 'A']]]);
        $section = api::add_section($built->version->get('id'), 'Part');
        api::publish_version($built->version->get('id'));

        $corrected = api::correct_section_wording($section->get('id'), 'Part one', '<p>Fixed</p>');

        $this->assertSame('Part one', $corrected->get('name'));
        $this->assertSame('<p>Fixed</p>', $corrected->get('description'));
        $this->assertSame(1, $corrected->get('sortorder'));
        $this->assertSame(['A'], $this->labels($built->version->get('id')));
        $this->assert_invalid(get_string('required'), fn(): mixed => api::correct_section_wording($section->get('id'), ' '));
    }

    /**
     * The overview of the published version can be corrected in place; an empty editor becomes null.
     *
     * @return void
     */
    public function test_correct_overview(): void {
        $built = $this->gen->create_template(['overview' => '<p>Old</p>', 'fields' => [['label' => 'A']]]);
        $versionid = $built->version->get('id');

        $this->assertSame('<p>New</p>', api::correct_overview($versionid, '<p>New</p>', FORMAT_HTML)->get('overview'));
        $this->assertNull(api::correct_overview($versionid, '<p><br></p>', FORMAT_HTML)->get('overview'));
        $this->assertTrue(api::get_version($versionid)->is_published());
    }

    /**
     * Duplicating a template copies its published version into a new, active, unassigned template's draft.
     *
     * @return void
     */
    public function test_duplicate_template(): void {
        $scale = $this->gen->create_scale();
        $built = $this->gen->create_template([
            'name' => 'Original',
            'overview' => '<p>Hello</p>',
            'gradingmode' => template_version::GRADE_OPTIONAL,
            'overallscaleid' => $scale->get('id'),
            'fields' => [['label' => 'A'], ['type' => template_field::TYPE_SINGLECHOICE, 'label' => 'B', 'options' => "X\nY"]],
        ]);
        $templateid = $built->template->get('id');
        // The published version is what is copied, not a newer draft.
        $draft = api::create_draft($templateid);
        api::add_field($draft->get('id'), 1, 'Only in the draft');
        api::archive_template($templateid);

        $copy = api::duplicate_template($templateid, 'Copy');

        $this->assertNotSame($templateid, $copy->get('id'));
        $this->assertTrue($copy->is_active());
        $this->assertSame('Copy', $copy->get('name'));
        $copydraft = api::get_draft($copy->get('id'));
        $this->assertSame(1, $copydraft->get('versionnumber'));
        $this->assertNull(api::get_published_version($copy->get('id')));
        $this->assertSame(['A', 'B'], $this->labels($copydraft->get('id')));
        $this->assertSame('<p>Hello</p>', $copydraft->get('overview'));
        $this->assertSame($scale->get('id'), $copydraft->get('overallscaleid'));
        $this->assertSame(0, assignment::count_records(['templateid' => $copy->get('id')]));
        $this->assertCount(3, api::get_fields($draft->get('id')));
        $this->assertSame(['A', 'B'], $this->labels($built->version->get('id')));
    }

    /**
     * A template never published duplicates from its draft, and a long new name is cut to 255 characters.
     *
     * @return void
     */
    public function test_duplicate_an_unpublished_template(): void {
        $built = $this->gen->create_template(['publish' => false, 'fields' => [['label' => 'Draft field']]]);

        $copy = api::duplicate_template($built->template->get('id'), str_repeat('n', 300));

        $this->assertSame(255, \core_text::strlen($copy->get('name')));
        $this->assertSame(['Draft field'], $this->labels(api::get_draft($copy->get('id'))->get('id')));
    }

    /**
     * Deleting a template removes its versions, fields and sections and nothing of another template.
     *
     * @return void
     */
    public function test_delete_template(): void {
        $built = $this->gen->create_template(['publish' => false, 'fields' => [['label' => 'A']]]);
        $other = $this->gen->create_template(['fields' => [['label' => 'Z']]]);
        $versionid = $built->version->get('id');
        api::add_section($versionid, 'Part');
        $sink = $this->redirectEvents();

        api::delete_template($built->template->get('id'));

        $this->assertFalse(template::record_exists($built->template->get('id')));
        $this->assertFalse(template_version::record_exists($versionid));
        $this->assertSame([], api::get_fields($versionid));
        $this->assertSame([], api::get_sections($versionid));
        $this->assertSame(['Z'], $this->labels($other->version->get('id')));
        $events = array_values(array_filter(
            $sink->get_events(),
            fn(\core\event\base $event): bool => $event instanceof event\template_deleted
        ));
        $this->assertCount(1, $events);
        $this->assertSame(1, $events[0]->other['versions']);
        $this->assertSame(0, $events[0]->other['assignments']);
        $sink->close();
    }

    /**
     * Deleting a template with assignments takes every cycle with it, and the summary says how many.
     *
     * @return void
     */
    public function test_delete_template_with_assignments(): void {
        global $DB;
        $built = $this->gen->create_template(['fields' => [['label' => 'Goals']]]);
        $other = $this->gen->create_template(['fields' => [['label' => 'Goals']]]);
        $templateid = $built->template->get('id');
        $ellie = $this->getDataGenerator()->create_user();
        $zoe = $this->getDataGenerator()->create_user();
        $alice = $this->getDataGenerator()->create_user();
        $appraisers = $this->getDataGenerator()->create_cohort();
        $appraisees = $this->getDataGenerator()->create_cohort();
        cohort_add_member($appraisers->id, $alice->id);
        cohort_add_member($appraisees->id, $ellie->id);
        $this->gen->create_cohort_link([
            'appraisercohortid' => $appraisers->id,
            'appraiseecohortid' => $appraisees->id,
        ]);
        $first = $this->gen->create_appraisal(['templateid' => $templateid, 'userid' => $ellie->id, 'appraiserid' => $alice->id]);
        $this->gen->create_appraisal([
            'templateid' => $templateid,
            'userid' => $zoe->id,
            'appraiserid' => $alice->id,
            'nogate' => true,
        ]);
        $kept = $this->gen->create_appraisal([
            'templateid' => $other->template->get('id'),
            'userid' => $ellie->id,
            'appraiserid' => $alice->id,
        ]);
        api::save_answer(
            $first->get('id'),
            $built->fields['Goals']->get('id'),
            record_answer::ROLE_APPRAISER,
            (int) $alice->id,
            'Learn'
        );
        api::log_transfer($first->get('id'), (int) $alice->id, (int) $zoe->id, (int) $alice->id);
        $DB->set_field(record::TABLE, 'status', record::STATUS_COMPLETED, ['id' => $first->get('id')]);
        $second = api::create_record($first->get('assignmentid'), $built->version->get('id'), (int) $alice->id);

        $summary = api::get_template_deletion_summary($templateid);
        $this->assertSame(2, $summary->assignments);
        $this->assertSame(3, $summary->records, 'Two assignments, one with a completed and a new cycle.');

        api::delete_template($templateid);

        $this->assertSame(0, assignment::count_records(['templateid' => $templateid]));
        $this->assertFalse(record::record_exists($first->get('id')));
        $this->assertFalse(record::record_exists($second->get('id')));
        $this->assertSame(0, $DB->count_records('local_appraisal_record_answer', ['recordid' => $first->get('id')]));
        $this->assertSame(0, $DB->count_records('local_appraisal_record_history', ['recordid' => $first->get('id')]));
        $this->assertSame(0, $DB->count_records('local_appraisal_record_transfer', ['recordid' => $first->get('id')]));
        $this->assertTrue(record::record_exists($kept->get('id')));
        $this->assertSame(1, assignment::count_records(['templateid' => $other->template->get('id')]));
        $this->assertGreaterThan(0, $DB->count_records('local_appraisal_record_history', ['recordid' => $kept->get('id')]));
    }

    /**
     * Deleting a template needs managetemplates, and deleteappraisals as well when it has assignments.
     *
     * @return void
     */
    public function test_can_delete_template(): void {
        $built = $this->gen->create_template(['fields' => [['label' => 'Goals']]]);
        $assigned = $this->gen->create_template(['fields' => [['label' => 'Goals']]]);
        $ellie = $this->getDataGenerator()->create_user();
        $alice = $this->getDataGenerator()->create_user();
        $this->gen->create_appraisal([
            'templateid' => $assigned->template->get('id'),
            'userid' => $ellie->id,
            'appraiserid' => $alice->id,
            'nogate' => true,
        ]);
        $builder = $this->getDataGenerator()->create_user();
        $both = $this->getDataGenerator()->create_user();
        $deleter = $this->getDataGenerator()->create_user();
        $this->gen->set_user_capability((int) $builder->id, 'local/appraisal:managetemplates');
        $this->gen->set_user_capability((int) $both->id, 'local/appraisal:managetemplates');
        $this->gen->set_user_capability((int) $both->id, 'local/appraisal:deleteappraisals');
        $this->gen->set_user_capability((int) $deleter->id, 'local/appraisal:deleteappraisals');
        $free = $built->template->get('id');
        $busy = $assigned->template->get('id');

        $this->assertTrue(access::can_delete_template($free, (int) $builder->id));
        $this->assertFalse(access::can_delete_template($busy, (int) $builder->id));
        $this->assertTrue(access::can_delete_template($busy, (int) $both->id));
        $this->assertFalse(access::can_delete_template($free, (int) $deleter->id));
        $this->assertFalse(access::can_delete_template($free, (int) $ellie->id));
        $this->assertTrue(access::can_delete_template($busy, (int) get_admin()->id));

        // Decided point 17: the person deleting may be one of the appraisees on the template.
        $this->gen->create_appraisal([
            'templateid' => $busy,
            'userid' => $both->id,
            'appraiserid' => $alice->id,
            'nogate' => true,
        ]);
        $this->assertTrue(access::can_delete_template($busy, (int) $both->id));
        api::delete_template($busy);
        $this->assertSame(0, assignment::count_records(['userid' => $both->id]));
        $this->assertFalse(template::record_exists($busy));
    }

    /**
     * Publishing needs a graded rating field's scale to still have two levels, not only the overall grade scale.
     *
     * @return void
     */
    public function test_publish_needs_the_rating_scale_to_have_two_levels(): void {
        $scale = $this->gen->create_scale();
        $built = $this->gen->create_template([
            'publish' => false,
            'fields' => [['type' => template_field::TYPE_SCALE, 'label' => 'Rate', 'scaleid' => $scale->get('id')]],
        ]);
        $levels = array_values(api::get_scale_levels($scale->get('id')));
        api::delete_scale_level($levels[0]->get('id'));
        api::delete_scale_level($levels[1]->get('id'));

        $this->assert_refused('error:scalenotusable', fn(): mixed => api::publish_version($built->version->get('id')));
        $this->assertTrue(api::get_version($built->version->get('id'))->is_draft());
    }

    /**
     * A section's wording can be corrected on the published version only: an archived version's section is refused.
     *
     * @return void
     */
    public function test_section_wording_is_refused_on_an_archived_version(): void {
        $built = $this->gen->create_template(['publish' => false, 'fields' => [['label' => 'A']]]);
        $section = api::add_section($built->version->get('id'), 'Part');
        api::publish_version($built->version->get('id'));
        $draft = api::create_draft($built->template->get('id'));
        api::publish_version($draft->get('id'));

        $this->assert_refused('error:notpublished', fn(): mixed => api::correct_section_wording($section->get('id'), 'Changed'));
        $this->assertSame('Part', template_section::get_record(['id' => $section->get('id')])->get('name'));
    }

    /**
     * A field added to an earlier section sits before the later section's fields, with sort orders 1 to N.
     *
     * @return void
     */
    public function test_field_added_to_an_earlier_section_keeps_the_order(): void {
        $built = $this->gen->create_template(['publish' => false, 'fields' => [['label' => 'A']]]);
        $versionid = $built->version->get('id');
        $one = api::add_section($versionid, 'One');
        $two = api::add_section($versionid, 'Two');
        api::add_field($versionid, 1, 'B', 1, true, $two->get('id'));

        api::add_field($versionid, 1, 'C', 1, true, $one->get('id'));

        $this->assertSame(['A', 'C', 'B'], $this->labels($versionid));
        $this->assertSame([1, 2, 3], $this->sortorders($versionid));
    }

    /**
     * Decided point 16: a new draft or a duplicate carries over a scale that was archived after publishing.
     *
     * @return void
     */
    public function test_scales_archived_since_carry_over_to_a_draft_and_a_duplicate(): void {
        $scale = $this->gen->create_scale();
        $scaleid = $scale->get('id');
        $built = $this->gen->create_template([
            'gradingmode' => template_version::GRADE_OPTIONAL,
            'overallscaleid' => $scaleid,
            'fields' => [['type' => template_field::TYPE_SCALE, 'label' => 'Rate', 'scaleid' => $scaleid]],
        ]);
        api::archive_scale($scaleid);

        $draft = api::create_draft($built->template->get('id'));
        $this->assertSame($scaleid, $draft->get('overallscaleid'));
        $this->assertSame($scaleid, array_values(api::get_fields($draft->get('id')))[0]->get('scaleid'));

        $copy = api::duplicate_template($built->template->get('id'), 'Copy');
        $copydraft = api::get_draft($copy->get('id'));
        $this->assertSame($scaleid, $copydraft->get('overallscaleid'));
        $this->assertSame($scaleid, array_values(api::get_fields($copydraft->get('id')))[0]->get('scaleid'));
    }

    /**
     * A new draft and a duplicate keep the overview's format as well as its text.
     *
     * @return void
     */
    public function test_copies_keep_the_overview_format(): void {
        $built = $this->gen->create_template(['publish' => false, 'fields' => [['label' => 'A']]]);
        api::update_version_settings($built->version->get('id'), 'Some *text*', FORMAT_MARKDOWN, 2, 1, null, null);
        api::publish_version($built->version->get('id'));

        $draft = api::create_draft($built->template->get('id'));
        $copy = api::get_draft(api::duplicate_template($built->template->get('id'), 'Copy')->get('id'));

        $this->assertSame((int) FORMAT_MARKDOWN, (int) $draft->get('overviewformat'));
        $this->assertSame((int) FORMAT_MARKDOWN, (int) $copy->get('overviewformat'));
        $this->assertSame('Some *text*', $copy->get('overview'));
    }

    /**
     * Correcting the wording of a published choice field keeps every key, including after a gap left by a draft edit.
     *
     * @return void
     */
    public function test_correcting_wording_keeps_option_keys_with_a_gap(): void {
        $built = $this->gen->create_template([
            'publish' => false,
            'fields' => [['type' => template_field::TYPE_MULTICHOICE, 'label' => 'Pick', 'options' => "Red\nGreen\nBlue"]],
        ]);
        $field = $built->fields['Pick'];
        $configdata = template_field::build_configdata(
            template_field::TYPE_MULTICHOICE,
            ['options' => "Red\nBlue"],
            $field->get('configdata')
        );
        api::update_field($field->get('id'), template_field::TYPE_MULTICHOICE, 'Pick', 1, true, null, null, $configdata);
        api::publish_version($built->version->get('id'));

        $corrected = api::correct_field_wording($field->get('id'), 'Pick', null, ['Rouge', 'Bleu']);

        $this->assertSame([1 => 'Rouge', 3 => 'Bleu'], $corrected->get_options());
    }

    /**
     * Grouping items by section keeps the order, and gives one group with no section when there are none.
     *
     * @return void
     */
    public function test_group_by_section(): void {
        $built = $this->gen->create_template(['publish' => false, 'fields' => [['label' => 'A'], ['label' => 'B']]]);
        $versionid = $built->version->get('id');
        $items = fn(): array => array_values(array_map(
            fn(template_field $f): array => ['label' => $f->get('label'), 'sectionid' => $f->get('sectionid')],
            api::get_fields($versionid)
        ));

        $plain = api::group_by_section([], $items());
        $this->assertCount(1, $plain);
        $this->assertNull($plain[0]['section']);
        $this->assertCount(2, $plain[0]['items']);

        $one = api::add_section($versionid, 'One');
        $two = api::add_section($versionid, 'Two');
        api::add_field($versionid, 1, 'C', 1, true, $two->get('id'));
        $groups = api::group_by_section(array_values(api::get_sections($versionid)), $items());

        $this->assertCount(2, $groups);
        $this->assertSame($one->get('id'), $groups[0]['section']->get('id'));
        $this->assertSame(['A', 'B'], array_column($groups[0]['items'], 'label'));
        $this->assertSame(['C'], array_column($groups[1]['items'], 'label'));
    }
}
