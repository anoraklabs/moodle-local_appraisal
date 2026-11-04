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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for grading scales: their levels, the strictly ascending value rule, reordering, duplicating, archiving,
 * deleting, usage counts and the locking that publishing a template version causes.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(api::class)]
final class scales_test extends \advanced_testcase {
    /** @var local_appraisal_generator The plugin's data generator. */
    private local_appraisal_generator $gen;

    /**
     * Get the generator. Refusals inside api transactions must not be wrapped in the test's own transaction.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
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
     * The labels of a scale's levels, lowest first.
     *
     * @param int $scaleid
     * @return string[]
     */
    private function labels(int $scaleid): array {
        return array_values(array_map(
            fn(scale_level $level): string => $level->get('label'),
            api::get_scale_levels($scaleid)
        ));
    }

    /**
     * The values of a scale's levels, lowest position first.
     *
     * @param int $scaleid
     * @return float[]
     */
    private function values(int $scaleid): array {
        return array_values(array_map(
            fn(scale_level $level): float => (float) $level->get('value'),
            api::get_scale_levels($scaleid)
        ));
    }

    /**
     * Publish a template with one graded rating field on the scale, which locks the scale.
     *
     * @param scale $scale
     * @return \stdClass The generated template.
     */
    private function publish_using(scale $scale): \stdClass {
        return $this->gen->create_template([
            'fields' => [['type' => template_field::TYPE_SCALE, 'label' => 'Rating', 'scaleid' => $scale->get('id')]],
        ]);
    }

    /**
     * A new scale is active and a name is needed.
     *
     * @return void
     */
    public function test_create_scale(): void {
        $scale = api::create_scale('Performance', 'How well');
        $this->assertTrue($scale->is_active());
        $this->assertSame('Performance', api::get_scale($scale->get('id'))->get('name'));
        $this->assertSame('How well', $scale->get('description'));

        $this->expectException(\core\invalid_persistent_exception::class);
        api::create_scale('   ');
    }

    /**
     * Levels are added at the top, in order, with the next sort order.
     *
     * @return void
     */
    public function test_levels_are_added_at_the_top(): void {
        $scale = $this->gen->create_scale(['levels' => ['Low' => 1, 'Medium' => 2]]);
        $scaleid = $scale->get('id');
        $level = api::add_scale_level($scaleid, 'High', 5, 'Exceeds');

        $this->assertSame(3, $level->get('sortorder'));
        $this->assertSame(['Low', 'Medium', 'High'], $this->labels($scaleid));
        $this->assertSame([1.0, 2.0, 5.0], $this->values($scaleid));
        $this->assertSame('Exceeds', $level->get('description'));
    }

    /**
     * A value must be strictly above every level below it, so a repeat or a lower value is refused.
     *
     * @return void
     */
    public function test_new_level_value_must_be_above_the_highest(): void {
        $scale = $this->gen->create_scale(['levels' => ['Low' => 1, 'High' => 3]]);
        $scaleid = $scale->get('id');

        $this->assert_refused('error:levelvalueascending', fn(): mixed => api::add_scale_level($scaleid, 'Same', 3));
        $this->assert_refused('error:levelvalueascending', fn(): mixed => api::add_scale_level($scaleid, 'Lower', 2));
        $this->assertSame(['Low', 'High'], $this->labels($scaleid));
        $this->assertNotNull(api::add_scale_level($scaleid, 'Top', 3.5));
    }

    /**
     * The range for a level value is 0 to 1000 inclusive, to two decimal places.
     *
     * @param float $value
     * @param bool $expected
     * @return void
     */
    #[DataProvider('range_provider')]
    public function test_level_value_range(float $value, bool $expected): void {
        $this->assertSame($expected, api::level_value_in_range($value));
    }

    /**
     * Values that are, and are not, in range.
     *
     * @return array
     */
    public static function range_provider(): array {
        return [
            'zero' => [0.0, true],
            'one thousand' => [1000.0, true],
            'just above one thousand' => [1000.01, false],
            'rounds down into range' => [1000.004, true],
            'negative' => [-0.01, false],
            'rounds up out of the negative' => [-0.004, true],
            'huge' => [1.0E+9, false],
        ];
    }

    /**
     * A level value outside the range is refused when adding and when editing.
     *
     * @return void
     */
    public function test_value_range_is_enforced(): void {
        $scale = $this->gen->create_scale(['levels' => ['Low' => 1, 'High' => 3]]);
        $scaleid = $scale->get('id');
        $levels = array_values(api::get_scale_levels($scaleid));

        $this->assert_refused('error:levelvaluerange', fn(): mixed => api::add_scale_level($scaleid, 'Big', 1000.5));
        $this->assert_refused(
            'error:levelvaluerange',
            fn(): mixed => api::update_scale_level($levels[0]->get('id'), 'Low', -1)
        );
        $this->assert_refused(
            'error:levelvaluerange',
            fn(): mixed => api::update_scale_level($levels[1]->get('id'), 'High', 2000)
        );
        $this->assertSame([1.0, 3.0], $this->values($scaleid));
    }

    /**
     * A level and a label are needed; a missing scale is refused.
     *
     * @return void
     */
    public function test_level_needs_a_label_and_a_scale(): void {
        $scale = $this->gen->create_scale();
        try {
            api::add_scale_level($scale->get('id'), '  ', 10);
            $this->fail('A blank label should be refused.');
        } catch (\core\invalid_persistent_exception $e) {
            $this->assertStringContainsString(get_string('required'), (string) $e->a);
        }

        $this->expectException(\dml_missing_record_exception::class);
        api::add_scale_level($scale->get('id') + 1000, 'Lost', 10);
    }

    /**
     * An edited value must sit between its neighbours; the ends have one neighbour only.
     *
     * @return void
     */
    public function test_edited_value_stays_between_neighbours(): void {
        $scale = $this->gen->create_scale(['levels' => ['A' => 1, 'B' => 2, 'C' => 3]]);
        $scaleid = $scale->get('id');
        [$a, $b, $c] = array_values(api::get_scale_levels($scaleid));

        $this->assert_refused('error:levelvalueascending', fn(): mixed => api::update_scale_level($b->get('id'), 'B', 1));
        $this->assert_refused('error:levelvalueascending', fn(): mixed => api::update_scale_level($b->get('id'), 'B', 3));
        $this->assert_refused('error:levelvalueascending', fn(): mixed => api::update_scale_level($a->get('id'), 'A', 2));
        $this->assert_refused('error:levelvalueascending', fn(): mixed => api::update_scale_level($c->get('id'), 'C', 2));
        $this->assertSame([1.0, 2.0, 3.0], $this->values($scaleid));

        $changed = api::update_scale_level($b->get('id'), 'Bee', 2.5, 'Middle');
        $this->assertSame('Bee', $changed->get('label'));
        $this->assertSame('Middle', $changed->get('description'));
        $this->assertSame([1.0, 2.5, 3.0], $this->values($scaleid));
        $this->assertNotNull(api::update_scale_level($a->get('id'), 'A', 0));
        $this->assertNotNull(api::update_scale_level($c->get('id'), 'C', 999));
    }

    /**
     * Editing a level to its own value is fine (it is not its own neighbour).
     *
     * @return void
     */
    public function test_a_level_may_keep_its_value(): void {
        $scale = $this->gen->create_scale(['levels' => ['A' => 1, 'B' => 2]]);
        $level = array_values(api::get_scale_levels($scale->get('id')))[1];
        $this->assertTrue(api::level_value_fits($scale->get('id'), $level->get('id'), 2.0));
        $this->assertFalse(api::level_value_fits($scale->get('id'), $level->get('id'), 1.0));
        $this->assertFalse(api::level_value_fits($scale->get('id'), 0, 2.0));
        $this->assertTrue(api::level_value_fits($scale->get('id'), 0, 2.01));
    }

    /**
     * Deleting a level closes the gap in the ordering and keeps the other values.
     *
     * @return void
     */
    public function test_delete_level_repacks_the_order(): void {
        $scale = $this->gen->create_scale(['levels' => ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4]]);
        $scaleid = $scale->get('id');
        $levels = array_values(api::get_scale_levels($scaleid));

        api::delete_scale_level($levels[1]->get('id'));

        $remaining = array_values(api::get_scale_levels($scaleid));
        $this->assertSame(['A', 'C', 'D'], $this->labels($scaleid));
        $this->assertSame([1, 2, 3], array_map(fn(scale_level $l): int => $l->get('sortorder'), $remaining));
        $this->assertSame([1.0, 3.0, 4.0], $this->values($scaleid));
    }

    /**
     * Reordering re-deals the existing values in ascending order to the new positions.
     *
     * @return void
     */
    public function test_reorder_levels_redeals_the_values(): void {
        $scale = $this->gen->create_scale(['levels' => ['A' => 1, 'B' => 2.5, 'C' => 10]]);
        $scaleid = $scale->get('id');
        [$a, $b, $c] = array_values(api::get_scale_levels($scaleid));

        api::reorder_levels($scaleid, [$c->get('id'), $a->get('id'), $b->get('id')]);

        $this->assertSame(['C', 'A', 'B'], $this->labels($scaleid));
        $this->assertSame([1.0, 2.5, 10.0], $this->values($scaleid));
        $this->assertSame([1, 2, 3], array_values(array_map(
            fn(scale_level $l): int => $l->get('sortorder'),
            api::get_scale_levels($scaleid)
        )));
        $this->assertSame(1.0, (float) scale_level::get_record(['id' => $c->get('id')])->get('value'));
    }

    /**
     * A reorder must name exactly the scale's levels, each once, and none from another scale.
     *
     * @return void
     */
    public function test_reorder_levels_must_match_the_scale(): void {
        $scale = $this->gen->create_scale(['levels' => ['A' => 1, 'B' => 2, 'C' => 3]]);
        $other = $this->gen->create_scale(['levels' => ['X' => 1, 'Y' => 2]]);
        $scaleid = $scale->get('id');
        [$a, $b, $c] = array_values(api::get_scale_levels($scaleid));
        $foreign = array_values(api::get_scale_levels($other->get('id')))[0];

        $this->assert_refused('error:levelmismatch', fn(): mixed => api::reorder_levels($scaleid, [$a->get('id'), $b->get('id')]));
        $this->assert_refused(
            'error:levelmismatch',
            fn(): mixed => api::reorder_levels($scaleid, [$a->get('id'), $a->get('id'), $b->get('id')])
        );
        $this->assert_refused(
            'error:levelmismatch',
            fn(): mixed => api::reorder_levels($scaleid, [$a->get('id'), $b->get('id'), $foreign->get('id')])
        );
        $this->assert_refused(
            'error:levelmismatch',
            fn(): mixed => api::reorder_levels($scaleid, [$a->get('id'), $b->get('id'), $c->get('id'), $foreign->get('id')])
        );
        $this->assertSame(['A', 'B', 'C'], $this->labels($scaleid));
        $this->assertSame(['X', 'Y'], $this->labels($other->get('id')));
    }

    /**
     * Updating a scale changes its name and description, and a blank name is refused.
     *
     * @return void
     */
    public function test_update_scale(): void {
        $scale = $this->gen->create_scale(['name' => 'Old']);
        $updated = api::update_scale($scale->get('id'), 'New', 'Fresh');
        $this->assertSame('New', $updated->get('name'));
        $this->assertSame('Fresh', api::get_scale($scale->get('id'))->get('description'));

        $this->expectException(\core\invalid_persistent_exception::class);
        api::update_scale($scale->get('id'), '');
    }

    /**
     * Archiving hides a scale from the pickers without deleting it, and unarchiving brings it back.
     *
     * @return void
     */
    public function test_archive_and_unarchive(): void {
        $scale = $this->gen->create_scale(['name' => 'Pickable']);
        $scaleid = $scale->get('id');
        $this->assertArrayHasKey($scaleid, api::get_usable_scales());

        api::archive_scale($scaleid);
        $this->assertSame(scale::STATUS_ARCHIVED, api::get_scale($scaleid)->get('status'));
        $this->assertArrayNotHasKey($scaleid, api::get_usable_scales());
        $this->assertCount(3, api::get_scale_levels($scaleid));

        api::unarchive_scale($scaleid);
        $this->assertTrue(api::get_scale($scaleid)->is_active());
        $this->assertArrayHasKey($scaleid, api::get_usable_scales());
    }

    /**
     * A scale needs two levels to be usable, whatever else is true of it.
     *
     * @return void
     */
    public function test_usable_scales_need_two_levels(): void {
        $none = api::create_scale('No levels');
        $one = $this->gen->create_scale(['levels' => ['Only' => 1]]);
        $two = $this->gen->create_scale(['levels' => ['Low' => 1, 'High' => 2]]);

        $usable = api::get_usable_scales();
        $this->assertArrayNotHasKey($none->get('id'), $usable);
        $this->assertArrayNotHasKey($one->get('id'), $usable);
        $this->assertArrayHasKey($two->get('id'), $usable);
    }

    /**
     * Duplicating copies the levels (label, value, description, order) into a new, active, unlocked scale.
     *
     * @return void
     */
    public function test_duplicate_scale(): void {
        $scale = $this->gen->create_scale(['name' => 'Original', 'description' => 'D', 'levels' => ['Low' => 1, 'High' => 4]]);
        $first = array_values(api::get_scale_levels($scale->get('id')))[0];
        api::update_scale_level($first->get('id'), 'Low', 1, 'Below');
        $this->publish_using($scale);
        api::archive_scale($scale->get('id'));

        $copy = api::duplicate_scale($scale->get('id'), 'Copy');

        $this->assertNotSame($scale->get('id'), $copy->get('id'));
        $this->assertSame('Copy', $copy->get('name'));
        $this->assertSame('D', $copy->get('description'));
        $this->assertTrue($copy->is_active());
        $this->assertFalse(api::is_scale_locked($copy->get('id')));
        $this->assertSame(['Low', 'High'], $this->labels($copy->get('id')));
        $this->assertSame([1.0, 4.0], $this->values($copy->get('id')));
        $this->assertSame('Below', array_values(api::get_scale_levels($copy->get('id')))[0]->get('description'));
        $this->assertTrue(api::is_scale_locked($scale->get('id')));
        $this->assertSame(['Low', 'High'], $this->labels($scale->get('id')));

        // The copy is free to change.
        $this->assertNotNull(api::add_scale_level($copy->get('id'), 'Higher', 9));
        $this->assertSame(2, count(api::get_scale_levels($scale->get('id'))));
    }

    /**
     * A copy's name is cut to 255 characters.
     *
     * @return void
     */
    public function test_duplicate_scale_truncates_a_long_name(): void {
        $scale = $this->gen->create_scale();
        $copy = api::duplicate_scale($scale->get('id'), str_repeat('n', 300));
        $this->assertSame(255, \core_text::strlen($copy->get('name')));
    }

    /**
     * Deleting a scale nothing uses removes it and its levels, and leaves other scales alone.
     *
     * @return void
     */
    public function test_delete_scale(): void {
        $scale = $this->gen->create_scale();
        $other = $this->gen->create_scale();
        $levelids = array_keys(api::get_scale_levels($scale->get('id')));

        api::delete_scale($scale->get('id'));

        $this->assertFalse(scale::record_exists($scale->get('id')));
        foreach ($levelids as $levelid) {
            $this->assertFalse(scale_level::record_exists($levelid));
        }
        $this->assertCount(3, api::get_scale_levels($other->get('id')));
    }

    /**
     * A scale used by any template version cannot be deleted, even by a draft, and nothing is removed.
     *
     * @return void
     */
    public function test_a_scale_in_use_cannot_be_deleted(): void {
        $scale = $this->gen->create_scale();
        $scaleid = $scale->get('id');
        $this->publish_using($scale);
        $this->assert_refused('error:scaleinuse', fn(): mixed => api::delete_scale($scaleid));
        $this->assertCount(3, api::get_scale_levels($scaleid));

        $draftscale = $this->gen->create_scale();
        $this->gen->create_template([
            'publish' => false,
            'fields' => [['type' => template_field::TYPE_SCALE, 'scaleid' => $draftscale->get('id')]],
        ]);
        $this->assert_refused('error:scaleinuse', fn(): mixed => api::delete_scale($draftscale->get('id')));
    }

    /**
     * A scale used as an overall grade scale counts as used.
     *
     * @return void
     */
    public function test_overall_grade_scale_counts_as_used(): void {
        $scale = $this->gen->create_scale();
        $this->gen->create_template([
            'gradingmode' => template_version::GRADE_OPTIONAL,
            'overallscaleid' => $scale->get('id'),
            'fields' => [['label' => 'Goals']],
        ]);

        $usage = api::get_scale_usage($scale->get('id'));
        $this->assertSame(1, $usage[$scale->get('id')]->templates);
        $this->assertSame(1, $usage[$scale->get('id')]->locked);
        $this->assert_refused('error:scaleinuse', fn(): mixed => api::delete_scale($scale->get('id')));
    }

    /**
     * Usage counts templates, not versions or fields, and a draft counts as used but does not lock.
     *
     * @return void
     */
    public function test_usage_counts_templates_and_locks_only_when_published(): void {
        $scale = $this->gen->create_scale();
        $scaleid = $scale->get('id');
        $this->assertSame([], api::get_scale_usage($scaleid));
        $this->assertFalse(api::is_scale_locked($scaleid));

        $draft = $this->gen->create_template([
            'publish' => false,
            'fields' => [
                ['type' => template_field::TYPE_SCALE, 'label' => 'One', 'scaleid' => $scaleid],
                ['type' => template_field::TYPE_SCALE, 'label' => 'Two', 'scaleid' => $scaleid],
            ],
        ]);
        $usage = api::get_scale_usage($scaleid)[$scaleid];
        $this->assertSame(1, $usage->templates);
        $this->assertSame(0, $usage->locked);
        $this->assertFalse(api::is_scale_locked($scaleid));

        api::publish_version($draft->version->get('id'));
        $this->assertTrue(api::is_scale_locked($scaleid));

        $this->publish_using($scale);
        $usage = api::get_scale_usage($scaleid)[$scaleid];
        $this->assertSame(2, $usage->templates);
        $this->assertArrayHasKey($scaleid, api::get_scale_usage());
    }

    /**
     * An unrelated scale is not locked or counted by someone else's template.
     *
     * @return void
     */
    public function test_usage_is_per_scale(): void {
        $used = $this->gen->create_scale();
        $free = $this->gen->create_scale();
        $this->publish_using($used);

        $this->assertTrue(api::is_scale_locked($used->get('id')));
        $this->assertFalse(api::is_scale_locked($free->get('id')));
        $this->assertArrayNotHasKey($free->get('id'), api::get_scale_usage());
        $this->assertCount(1, api::get_scale_usage());

        $other = $this->gen->create_scale();
        $this->publish_using($other);
        $this->assertSame([$used->get('id')], array_keys(api::get_scale_usage($used->get('id'))));
        $this->assertSame([], api::get_scale_usage($free->get('id')));
    }

    /**
     * Once a published version uses a scale, levels cannot be added, removed or reordered, and values are fixed.
     *
     * @return void
     */
    public function test_a_locked_scale_refuses_structure_changes(): void {
        $scale = $this->gen->create_scale();
        $scaleid = $scale->get('id');
        $this->publish_using($scale);
        [$a, $b, $c] = array_values(api::get_scale_levels($scaleid));

        $this->assert_refused('error:scalelocked', fn(): mixed => api::add_scale_level($scaleid, 'New', 10));
        $this->assert_refused('error:scalelocked', fn(): mixed => api::delete_scale_level($b->get('id')));
        $this->assert_refused(
            'error:scalelocked',
            fn(): mixed => api::reorder_levels($scaleid, [$c->get('id'), $b->get('id'), $a->get('id')])
        );

        $this->assertSame(['Low', 'Medium', 'High'], $this->labels($scaleid));
        $this->assertSame([1.0, 2.0, 3.0], $this->values($scaleid));
    }

    /**
     * On a locked scale a level's wording can still be corrected, but the value passed is ignored.
     *
     * @return void
     */
    public function test_a_locked_scale_allows_wording_only(): void {
        $scale = $this->gen->create_scale();
        $scaleid = $scale->get('id');
        $this->publish_using($scale);
        $level = array_values(api::get_scale_levels($scaleid))[1];

        $changed = api::update_scale_level($level->get('id'), 'Fair', 99, 'Typo fixed');

        $this->assertSame('Fair', $changed->get('label'));
        $this->assertSame('Typo fixed', $changed->get('description'));
        $this->assertSame(2.0, (float) scale_level::get_record(['id' => $level->get('id')])->get('value'));
        $this->assertSame([1.0, 2.0, 3.0], $this->values($scaleid));

        $renamed = api::update_scale($scaleid, 'Renamed', 'Still fine');
        $this->assertSame('Renamed', $renamed->get('name'));
    }

    /**
     * An archived version locks the scale too, even when the current version no longer uses it.
     *
     * @return void
     */
    public function test_an_archived_version_keeps_the_scale_locked(): void {
        $scale = $this->gen->create_scale();
        $scaleid = $scale->get('id');
        $other = $this->gen->create_scale();
        $built = $this->publish_using($scale);
        $templateid = $built->template->get('id');

        $draft = api::create_draft($templateid);
        $ratingid = array_values(api::get_fields($draft->get('id')))[0]->get('id');
        api::add_field($draft->get('id'), template_field::TYPE_SHORTTEXT, 'Plain');
        api::delete_field($ratingid);
        api::publish_version($draft->get('id'));

        $versions = api::get_versions($templateid);
        $this->assertSame(template_version::STATUS_ARCHIVED, array_pop($versions)->get('status'));
        $this->assertTrue(api::is_scale_locked($scaleid));
        $this->assertFalse(api::is_scale_locked($other->get('id')));
        $this->assert_refused('error:scalelocked', fn(): mixed => api::add_scale_level($scaleid, 'More', 10));
    }

    /**
     * Archiving a locked scale does not unlock it; versions using it keep working.
     *
     * @return void
     */
    public function test_archiving_does_not_unlock(): void {
        $scale = $this->gen->create_scale();
        $built = $this->publish_using($scale);
        api::archive_scale($scale->get('id'));

        $this->assertTrue(api::is_scale_locked($scale->get('id')));
        $this->assertSame(
            template_version::STATUS_PUBLISHED,
            api::get_published_version($built->template->get('id'))->get('status')
        );
    }

    /**
     * An unlocked scale (only a draft uses it) can still be restructured.
     *
     * @return void
     */
    public function test_a_draft_does_not_lock_the_scale(): void {
        $scale = $this->gen->create_scale();
        $scaleid = $scale->get('id');
        $this->gen->create_template([
            'publish' => false,
            'fields' => [['type' => template_field::TYPE_SCALE, 'scaleid' => $scaleid]],
        ]);
        [$a, $b, $c] = array_values(api::get_scale_levels($scaleid));

        api::reorder_levels($scaleid, [$b->get('id'), $a->get('id'), $c->get('id')]);
        api::delete_scale_level($c->get('id'));
        $this->assertNotNull(api::add_scale_level($scaleid, 'Top', 9));
        $this->assertSame(['Medium', 'Low', 'Top'], $this->labels($scaleid));
    }

    /**
     * Publishing is the point that locks: the scale is free until then.
     *
     * @return void
     */
    public function test_publishing_locks_the_scale(): void {
        $scale = $this->gen->create_scale();
        $built = $this->gen->create_template([
            'publish' => false,
            'fields' => [['type' => template_field::TYPE_SCALE, 'scaleid' => $scale->get('id')]],
        ]);
        $this->assertFalse(api::is_scale_locked($scale->get('id')));

        api::publish_version($built->version->get('id'));

        $this->assertTrue(api::is_scale_locked($scale->get('id')));
    }

    /**
     * Levels belong to their own scale: editing and reordering one scale never touches another.
     *
     * @return void
     */
    public function test_scales_are_independent(): void {
        $one = $this->gen->create_scale(['levels' => ['A' => 1, 'B' => 2]]);
        $two = $this->gen->create_scale(['levels' => ['A' => 1, 'B' => 2]]);
        [$a, $b] = array_values(api::get_scale_levels($one->get('id')));

        api::reorder_levels($one->get('id'), [$b->get('id'), $a->get('id')]);
        api::delete_scale_level($a->get('id'));

        $this->assertSame(['A', 'B'], $this->labels($two->get('id')));
        $this->assertSame([1.0, 2.0], $this->values($two->get('id')));
    }
}
