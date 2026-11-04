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
 * Tests for the two write paths and the visibility rule: who may save which side's answers and comments, when, and
 * that nothing of the other side leaves the api before the appraisee has submitted.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(api::class)]
final class write_paths_test extends \advanced_testcase {
    /** @var local_appraisal_generator The plugin's data generator. */
    private local_appraisal_generator $gen;

    /** @var \stdClass The appraisee. */
    private \stdClass $ellie;

    /** @var \stdClass The record's owner, an appraiser who reaches the appraisee through a cohort link. */
    private \stdClass $alice;

    /** @var \stdClass A second appraiser who reaches the appraisee but does not own the record. */
    private \stdClass $ben;

    /** @var \stdClass A user with no gate to the appraisee. */
    private \stdClass $uma;

    /** @var \stdClass The appraiser cohort. */
    private \stdClass $appraisercohort;

    /** @var \stdClass The generated template: template, version and fields keyed by label. */
    private \stdClass $built;

    /** @var record The record under test, owned by Alice for Ellie. */
    private record $record;

    /**
     * Build one appraisal: Ellie appraised by Alice, with Ben as another appraiser and Uma as an outsider.
     *
     * The fields are Goals (long text, both), Manager note (short text, appraiser only) and Self review (short text,
     * appraisee only).
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        $this->redirectMessages();
        $this->gen = $this->getDataGenerator()->get_plugin_generator('local_appraisal');

        $this->ellie = $this->getDataGenerator()->create_user(['firstname' => 'Ellie']);
        $this->alice = $this->getDataGenerator()->create_user(['firstname' => 'Alice']);
        $this->ben = $this->getDataGenerator()->create_user(['firstname' => 'Ben']);
        $this->uma = $this->getDataGenerator()->create_user(['firstname' => 'Uma']);

        $this->appraisercohort = $this->getDataGenerator()->create_cohort();
        $appraiseecohort = $this->getDataGenerator()->create_cohort();
        cohort_add_member($this->appraisercohort->id, $this->alice->id);
        cohort_add_member($this->appraisercohort->id, $this->ben->id);
        cohort_add_member($appraiseecohort->id, $this->ellie->id);
        $this->gen->create_cohort_link([
            'appraisercohortid' => $this->appraisercohort->id,
            'appraiseecohortid' => $appraiseecohort->id,
        ]);

        $this->built = $this->gen->create_template([
            'fields' => [
                [
                    'type' => template_field::TYPE_LONGTEXT,
                    'label' => 'Goals',
                    'completedby' => template_field::COMPLETEDBY_BOTH,
                ],
                [
                    'type' => template_field::TYPE_SHORTTEXT,
                    'label' => 'Manager note',
                    'completedby' => template_field::COMPLETEDBY_APPRAISER,
                ],
                [
                    'type' => template_field::TYPE_SHORTTEXT,
                    'label' => 'Self review',
                    'completedby' => template_field::COMPLETEDBY_APPRAISEE,
                ],
            ],
        ]);
        $this->record = $this->gen->create_appraisal([
            'templateid' => $this->built->template->get('id'),
            'userid' => $this->ellie->id,
            'appraiserid' => $this->alice->id,
        ]);
    }

    /**
     * The id of one of the template's fields.
     *
     * @param string $label
     * @return int
     */
    private function field_id(string $label): int {
        return $this->built->fields[$label]->get('id');
    }

    /**
     * The record's context, read afresh so it carries the current status.
     *
     * @return \stdClass
     */
    private function ctx(): \stdClass {
        return api::get_record_context($this->record->get('id'));
    }

    /**
     * Put the record in a status directly, as the lifecycle would leave it.
     *
     * @param int $status One of the record STATUS_* constants.
     * @param bool $submitted Whether the appraisee has ever submitted.
     * @return void
     */
    private function force_status(int $status, bool $submitted): void {
        $record = record::get_record(['id' => $this->record->get('id')], MUST_EXIST);
        $record->set('status', $status);
        $record->set('timesubmitted', $submitted ? time() : null);
        $record->update();
    }

    /**
     * Save an answer as one side of the record and return the result.
     *
     * @param \stdClass $user Who saves.
     * @param string $label The field.
     * @param int $role The side saved.
     * @param string|null $value
     * @return record_answer|null
     */
    private function save(\stdClass $user, string $label, int $role, ?string $value): ?record_answer {
        return api::save_answer($this->record->get('id'), $this->field_id($label), $role, (int) $user->id, $value);
    }

    /**
     * Assert that a save is refused with the given error code.
     *
     * @param string $errorcode
     * @param \stdClass $user Who saves.
     * @param string $label The field.
     * @param int $role The side saved.
     * @return void
     */
    private function assert_save_refused(string $errorcode, \stdClass $user, string $label, int $role): void {
        try {
            $this->save($user, $label, $role, 'text');
            $this->fail("{$user->firstname} saving {$label} as role {$role} should have been refused.");
        } catch (moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * The fields as one viewer gets them, keyed by field label.
     *
     * @param \stdClass $viewer
     * @return \stdClass[]
     */
    private function fields_for(\stdClass $viewer): array {
        $ctx = $this->ctx();
        $role = api::get_viewer_role($ctx, (int) $viewer->id);
        $rows = [];
        foreach (api::get_record_fields($ctx, $role, (int) $viewer->id) as $row) {
            $rows[$row->field->get('label')] = $row;
        }
        return $rows;
    }

    /**
     * The appraisee sees the record from the appraisee side, every appraiser who reaches them from the appraiser
     * side, and an outsider from neither.
     *
     * @return void
     */
    public function test_viewer_roles(): void {
        $ctx = $this->ctx();

        $this->assertSame(record_answer::ROLE_APPRAISEE, api::get_viewer_role($ctx, (int) $this->ellie->id));
        $this->assertSame(record_answer::ROLE_APPRAISER, api::get_viewer_role($ctx, (int) $this->alice->id));
        $this->assertSame(record_answer::ROLE_APPRAISER, api::get_viewer_role($ctx, (int) $this->ben->id));
        $this->assertNull(api::get_viewer_role($ctx, (int) $this->uma->id));
        $this->assertSame(record_answer::ROLE_APPRAISER, api::get_viewer_role($ctx, (int) get_admin()->id));
    }

    /**
     * An appraisee who has lost completeown has no view of their own record.
     *
     * @return void
     */
    public function test_appraisee_without_completeown_has_no_view(): void {
        $this->gen->set_user_capability($this->ellie->id, 'local/appraisal:completeown', CAP_PROHIBIT);

        $this->assertNull(api::get_viewer_role($this->ctx(), (int) $this->ellie->id));
        $this->assertFalse(api::can_write_role($this->ctx(), (int) $this->ellie->id, record_answer::ROLE_APPRAISEE));
        $this->assert_save_refused('error:cannotwrite', $this->ellie, 'Self review', record_answer::ROLE_APPRAISEE);
    }

    /**
     * Opening a record needs a view of it.
     *
     * @return void
     */
    public function test_require_record_access(): void {
        $recordid = $this->record->get('id');
        $asalice = api::require_record_access($recordid, (int) $this->alice->id);
        $asellie = api::require_record_access($recordid, (int) $this->ellie->id);
        $this->assertSame(record_answer::ROLE_APPRAISER, $asalice->role);
        $this->assertSame(record_answer::ROLE_APPRAISEE, $asellie->role);

        try {
            api::require_record_access($this->record->get('id'), (int) $this->uma->id);
            $this->fail('An outsider should be refused.');
        } catch (moodle_exception $e) {
            $this->assertSame('error:nodashboardaccess', $e->errorcode);
        }

        try {
            api::get_record_context($this->record->get('id') + 1000);
            $this->fail('A record that does not exist should be refused.');
        } catch (moodle_exception $e) {
            $this->assertSame('error:recordnotfound', $e->errorcode);
        }
    }

    /**
     * Who can write which side while the record is open: only the owner as the appraiser, only the appraisee as the
     * appraisee, whatever anyone else's capabilities.
     *
     * @return void
     */
    public function test_the_two_write_paths_on_an_open_record(): void {
        $ctx = $this->ctx();
        $appraiser = record_answer::ROLE_APPRAISER;
        $appraisee = record_answer::ROLE_APPRAISEE;

        $this->assertTrue(api::can_write_role($ctx, (int) $this->alice->id, $appraiser));
        $this->assertFalse(api::can_write_role($ctx, (int) $this->alice->id, $appraisee), 'Owner as the appraisee.');
        $this->assertTrue(api::can_write_role($ctx, (int) $this->ellie->id, $appraisee));
        $this->assertFalse(api::can_write_role($ctx, (int) $this->ellie->id, $appraiser), 'Appraisee as the appraiser.');
        $this->assertFalse(api::can_write_role($ctx, (int) $this->ben->id, $appraiser), 'A second appraiser.');
        $this->assertFalse(api::can_write_role($ctx, (int) $this->ben->id, $appraisee));
        $this->assertFalse(api::can_write_role($ctx, (int) get_admin()->id, $appraiser), 'An administrator who is not the owner.');
        $this->assertFalse(api::can_write_role($ctx, (int) get_admin()->id, $appraisee));
        $this->assertFalse(api::can_write_role($ctx, (int) $this->uma->id, $appraiser));
        $this->assertFalse(api::can_write_role($ctx, (int) $this->uma->id, $appraisee));
    }

    /**
     * The appraisee's side locks once they submit and stays locked; the owner's side stays open until sign-off.
     *
     * @return void
     */
    public function test_write_paths_across_the_statuses(): void {
        $appraiser = record_answer::ROLE_APPRAISER;
        $appraisee = record_answer::ROLE_APPRAISEE;
        $alice = (int) $this->alice->id;
        $ellie = (int) $this->ellie->id;

        $this->force_status(record::STATUS_IN_PROGRESS, false);
        $this->assertTrue(api::can_write_role($this->ctx(), $ellie, $appraisee));
        $this->assertTrue(api::can_write_role($this->ctx(), $alice, $appraiser));

        $this->force_status(record::STATUS_SUBMITTED, true);
        $this->assertFalse(api::can_write_role($this->ctx(), $ellie, $appraisee), 'Locked after submitting.');
        $this->assertTrue(api::can_write_role($this->ctx(), $alice, $appraiser));

        $this->force_status(record::STATUS_IN_PROGRESS, true);
        $this->assertTrue(api::can_write_role($this->ctx(), $ellie, $appraisee), 'Unlocked again by a hand back.');

        $this->force_status(record::STATUS_COMPLETED, true);
        $this->assertFalse(api::can_write_role($this->ctx(), $ellie, $appraisee), 'Completed.');
        $this->assertFalse(api::can_write_role($this->ctx(), $alice, $appraiser), 'Completed.');
    }

    /**
     * An owner who loses their route to the appraisee stops writing at once.
     *
     * @return void
     */
    public function test_owner_loses_the_write_path_with_the_gate(): void {
        $this->assertTrue(api::can_write_role($this->ctx(), (int) $this->alice->id, record_answer::ROLE_APPRAISER));

        cohort_remove_member($this->appraisercohort->id, $this->alice->id);

        $this->assertFalse(api::can_write_role($this->ctx(), (int) $this->alice->id, record_answer::ROLE_APPRAISER));
        $this->assert_save_refused('error:cannotwrite', $this->alice, 'Manager note', record_answer::ROLE_APPRAISER);
    }

    /**
     * An owner who loses the appraise capability stops writing at once.
     *
     * @return void
     */
    public function test_owner_loses_the_write_path_with_the_capability(): void {
        $this->gen->set_user_capability($this->alice->id, 'local/appraisal:appraise', CAP_PROHIBIT);

        $this->assertFalse(api::can_write_role($this->ctx(), (int) $this->alice->id, record_answer::ROLE_APPRAISER));
    }

    /**
     * Each side saves its answers, an answer is replaced rather than duplicated, and the saver is recorded.
     *
     * @return void
     */
    public function test_each_side_saves_its_own_answers(): void {
        $clock = $this->mock_clock_with_frozen();

        $mine = $this->save($this->ellie, 'Self review', record_answer::ROLE_APPRAISEE, 'Learned a lot');
        $this->assertSame('Learned a lot', $mine->get('valuetext'));
        $this->assertSame((int) $this->ellie->id, $mine->get('userid'));
        $this->assertSame($clock->time(), $mine->get('timeanswered'));

        $this->save($this->alice, 'Manager note', record_answer::ROLE_APPRAISER, 'Strong year');
        $this->save($this->ellie, 'Self review', record_answer::ROLE_APPRAISEE, 'Learned even more');

        $answers = api::get_answers($this->record->get('id'));
        $this->assertCount(2, $answers, 'The second save replaced the first.');
        $self = record_answer::get_record([
            'recordid' => $this->record->get('id'),
            'fieldid' => $this->field_id('Self review'),
            'role' => record_answer::ROLE_APPRAISEE,
        ]);
        $this->assertSame('Learned even more', $self->get('valuetext'));
    }

    /**
     * Nobody saves the other side's answers, whatever they hold.
     *
     * @return void
     */
    public function test_nobody_writes_the_other_sides_answers(): void {
        $this->assert_save_refused('error:cannotwrite', $this->ellie, 'Manager note', record_answer::ROLE_APPRAISER);
        $this->assert_save_refused('error:cannotwrite', $this->alice, 'Self review', record_answer::ROLE_APPRAISEE);
        $this->assert_save_refused('error:cannotwrite', $this->ben, 'Manager note', record_answer::ROLE_APPRAISER);
        $this->assert_save_refused('error:cannotwrite', $this->uma, 'Manager note', record_answer::ROLE_APPRAISER);
        $this->assert_save_refused('error:cannotwrite', $this->uma, 'Self review', record_answer::ROLE_APPRAISEE);
        $this->setAdminUser();
        $this->assert_save_refused('error:cannotwrite', get_admin(), 'Manager note', record_answer::ROLE_APPRAISER);

        $this->assertSame([], api::get_answers($this->record->get('id')));
        $this->assertSame(record::STATUS_NOT_STARTED, $this->ctx()->record->get('status'));
    }

    /**
     * Once the appraisee has submitted, even a save to their own field is refused; a completed record refuses all.
     *
     * @return void
     */
    public function test_saves_are_refused_when_the_side_is_locked(): void {
        $this->force_status(record::STATUS_SUBMITTED, true);
        $this->assert_save_refused('error:cannotwrite', $this->ellie, 'Self review', record_answer::ROLE_APPRAISEE);
        $this->assertNotNull($this->save($this->alice, 'Manager note', record_answer::ROLE_APPRAISER, 'Still open to me'));

        $this->force_status(record::STATUS_COMPLETED, true);
        $this->assert_save_refused('error:cannotwrite', $this->ellie, 'Self review', record_answer::ROLE_APPRAISEE);
        $this->assert_save_refused('error:cannotwrite', $this->alice, 'Manager note', record_answer::ROLE_APPRAISER);
    }

    /**
     * A side cannot answer a field the other side completes, nor a field of another version.
     *
     * @return void
     */
    public function test_field_must_belong_to_the_version_and_to_the_side(): void {
        $this->assert_save_refused('error:wrongrolefield', $this->ellie, 'Manager note', record_answer::ROLE_APPRAISEE);
        $this->assert_save_refused('error:wrongrolefield', $this->alice, 'Self review', record_answer::ROLE_APPRAISER);

        $other = $this->gen->create_template(['fields' => [['label' => 'Elsewhere']]]);
        try {
            api::save_answer(
                $this->record->get('id'),
                $other->fields['Elsewhere']->get('id'),
                record_answer::ROLE_APPRAISER,
                (int) $this->alice->id,
                'x'
            );
            $this->fail('A field of another version should be refused.');
        } catch (moodle_exception $e) {
            $this->assertSame('error:fieldmismatch', $e->errorcode);
        }
    }

    /**
     * The first real save by either person starts the record once, with a history row and one started event.
     *
     * @return void
     */
    public function test_first_save_starts_the_record_once(): void {
        $clock = $this->mock_clock_with_frozen();
        $sink = $this->redirectEvents();

        $this->save($this->ellie, 'Self review', record_answer::ROLE_APPRAISEE, 'First');
        $this->save($this->alice, 'Manager note', record_answer::ROLE_APPRAISER, 'Second');

        $record = $this->ctx()->record;
        $this->assertSame(record::STATUS_IN_PROGRESS, $record->get('status'));
        $this->assertSame($clock->time(), $record->get('timestarted'));
        $started = array_filter(
            $sink->get_events(),
            fn(\core\event\base $event): bool => $event instanceof event\record_started
        );
        $this->assertCount(1, $started);
        $sink->close();

        $history = array_values(api::get_history($record->get('id')));
        $this->assertCount(2, $history, 'The creation row and the start.');
        $this->assertSame(record::STATUS_NOT_STARTED, (int) $history[1]->fromstatus);
        $this->assertSame(record::STATUS_IN_PROGRESS, (int) $history[1]->tostatus);
        $this->assertSame((int) $this->ellie->id, (int) $history[1]->actorid);
    }

    /**
     * Clearing an answer that was never saved changes nothing and does not start the record.
     *
     * @return void
     */
    public function test_empty_save_does_not_start_the_record(): void {
        $this->assertNull($this->save($this->ellie, 'Self review', record_answer::ROLE_APPRAISEE, ''));
        $this->assertNull($this->save($this->ellie, 'Self review', record_answer::ROLE_APPRAISEE, null));

        $this->assertSame(record::STATUS_NOT_STARTED, $this->ctx()->record->get('status'));
        $this->assertSame([], api::get_answers($this->record->get('id')));
    }

    /**
     * An empty value clears an answer that was saved, and the record stays In progress.
     *
     * @return void
     */
    public function test_empty_value_clears_a_saved_answer(): void {
        $this->save($this->ellie, 'Self review', record_answer::ROLE_APPRAISEE, 'Something');
        $this->assertCount(1, api::get_answers($this->record->get('id')));

        $this->assertNull($this->save($this->ellie, 'Self review', record_answer::ROLE_APPRAISEE, ''));

        $this->assertSame([], api::get_answers($this->record->get('id')));
        $this->assertSame(record::STATUS_IN_PROGRESS, $this->ctx()->record->get('status'));
    }

    /**
     * Whether the other side's answers show, by status and by whether the appraisee has ever submitted.
     *
     * @return array[] Each: status, whether timesubmitted is set, the viewer's side, the answer's side, expected.
     */
    public static function visibility_provider(): array {
        $appraiser = record_answer::ROLE_APPRAISER;
        $appraisee = record_answer::ROLE_APPRAISEE;
        return [
            'own appraiser answers, not started' => [record::STATUS_NOT_STARTED, false, $appraiser, $appraiser, true],
            'own appraisee answers, not started' => [record::STATUS_NOT_STARTED, false, $appraisee, $appraisee, true],
            'appraisee answers hidden from the appraiser in progress' =>
                [record::STATUS_IN_PROGRESS, false, $appraiser, $appraisee, false],
            'appraiser answers hidden from the appraisee in progress' =>
                [record::STATUS_IN_PROGRESS, false, $appraisee, $appraiser, false],
            'appraisee sees the appraiser once submitted' => [record::STATUS_SUBMITTED, true, $appraisee, $appraiser, true],
            'appraiser sees the appraisee once submitted' => [record::STATUS_SUBMITTED, true, $appraiser, $appraisee, true],
            'still visible after a hand back' => [record::STATUS_IN_PROGRESS, true, $appraisee, $appraiser, true],
            'visible when completed' => [record::STATUS_COMPLETED, true, $appraisee, $appraiser, true],
            'visible when completed with nothing submitted' => [record::STATUS_COMPLETED, false, $appraiser, $appraisee, true],
            'hidden again after a reset' => [record::STATUS_NOT_STARTED, false, $appraisee, $appraiser, false],
        ];
    }

    /**
     * The visibility rule itself.
     *
     * @param int $status
     * @param bool $submitted
     * @param int $viewerrole
     * @param int $answerrole
     * @param bool $expected
     * @return void
     */
    #[DataProvider('visibility_provider')]
    public function test_answers_visible(int $status, bool $submitted, int $viewerrole, int $answerrole, bool $expected): void {
        $record = new record();
        $record->set('status', $status);
        $record->set('timesubmitted', $submitted ? 1000 : null);

        $this->assertSame($expected, api::answers_visible($record, $viewerrole, $answerrole));
    }

    /**
     * Before submission the other person's answers never come back from get_record_fields(), even though they exist.
     *
     * @return void
     */
    public function test_other_sides_answers_do_not_leave_the_api_before_submission(): void {
        $this->save($this->ellie, 'Goals', record_answer::ROLE_APPRAISEE, 'Ellie secret goal');
        $this->save($this->ellie, 'Self review', record_answer::ROLE_APPRAISEE, 'Ellie secret review');
        $this->save($this->alice, 'Goals', record_answer::ROLE_APPRAISER, 'Alice secret goal');
        $this->save($this->alice, 'Manager note', record_answer::ROLE_APPRAISER, 'Alice secret note');

        $asellie = $this->fields_for($this->ellie);
        $asalice = $this->fields_for($this->alice);
        $asben = $this->fields_for($this->ben);

        $this->assertSame('Ellie secret goal', $asellie['Goals']->own->get('valuetext'));
        $this->assertNull($asellie['Goals']->other, 'Alice\'s goal is hidden from Ellie.');
        $this->assertTrue($asellie['Goals']->otherhidden);
        $this->assertNull($asellie['Manager note']->own, 'Ellie does not complete this field.');
        $this->assertNull($asellie['Manager note']->other, 'Alice\'s note is hidden from Ellie.');

        $this->assertSame('Alice secret goal', $asalice['Goals']->own->get('valuetext'));
        $this->assertNull($asalice['Goals']->other, 'Ellie\'s goal is hidden from Alice.');
        $this->assertNull($asalice['Self review']->other, 'Ellie\'s review is hidden from Alice.');
        $this->assertTrue($asalice['Self review']->otherhidden);

        $this->assertNull($asben['Goals']->other, 'A second appraiser sees the appraiser side only.');
        $this->assertNull($asben['Self review']->other);
        $this->assertSame('Alice secret note', $asben['Manager note']->own->get('valuetext'), 'The appraiser side.');
        $this->assertSame('Alice secret goal', $asben['Goals']->own->get('valuetext'));

        $this->assertStringNotContainsString('Alice secret', json_encode($this->flatten($asellie, 'valuetext')));
        $this->assertStringNotContainsString('Ellie secret', json_encode($this->flatten($asben, 'valuetext')));
        $this->assertStringNotContainsString('Ellie secret', json_encode($this->flatten($asalice, 'valuetext')));
    }

    /**
     * Collect every text value a set of field rows carries, to prove none of the hidden ones are among them.
     *
     * @param \stdClass[] $rows From fields_for().
     * @param string $property The answer property to collect.
     * @return string[]
     */
    private function flatten(array $rows, string $property): array {
        $values = [];
        foreach ($rows as $row) {
            foreach ([$row->own, $row->other] as $answer) {
                if ($answer) {
                    $values[] = $answer->get($property);
                }
            }
        }
        return $values;
    }

    /**
     * After the appraisee submits, both sides see both answers; they stay visible after a hand back, and a reset
     * hides them again.
     *
     * @return void
     */
    public function test_answers_become_visible_after_submission_and_stay_so(): void {
        $this->save($this->ellie, 'Goals', record_answer::ROLE_APPRAISEE, 'Ellie goal');
        $this->save($this->alice, 'Goals', record_answer::ROLE_APPRAISER, 'Alice goal');

        $this->force_status(record::STATUS_SUBMITTED, true);
        $this->assertSame('Alice goal', $this->fields_for($this->ellie)['Goals']->other->get('valuetext'));
        $this->assertSame('Ellie goal', $this->fields_for($this->alice)['Goals']->other->get('valuetext'));
        $this->assertSame('Ellie goal', $this->fields_for($this->ben)['Goals']->other->get('valuetext'));

        $this->force_status(record::STATUS_IN_PROGRESS, true);
        $this->assertSame('Alice goal', $this->fields_for($this->ellie)['Goals']->other->get('valuetext'));

        $this->force_status(record::STATUS_NOT_STARTED, false);
        $this->assertNull($this->fields_for($this->ellie)['Goals']->other);
    }

    /**
     * A field is editable only on the viewer's own column and only while that side is open.
     *
     * @return void
     */
    public function test_editable_follows_the_write_path(): void {
        $asellie = $this->fields_for($this->ellie);
        $asalice = $this->fields_for($this->alice);
        $asben = $this->fields_for($this->ben);

        $this->assertTrue($asellie['Self review']->editable);
        $this->assertTrue($asellie['Goals']->editable);
        $this->assertFalse($asellie['Manager note']->editable);
        $this->assertTrue($asalice['Manager note']->editable);
        $this->assertFalse($asalice['Self review']->editable);
        $this->assertFalse($asben['Manager note']->editable, 'A second appraiser edits nothing.');

        $this->force_status(record::STATUS_SUBMITTED, true);
        $this->assertFalse($this->fields_for($this->ellie)['Self review']->editable);
        $this->assertTrue($this->fields_for($this->alice)['Manager note']->editable);

        $this->force_status(record::STATUS_COMPLETED, true);
        $this->assertFalse($this->fields_for($this->alice)['Manager note']->editable);
    }

    /**
     * Only the owner saves the overall comment; it is trimmed, plain text, and starts the record.
     *
     * @return void
     */
    public function test_owner_saves_the_overall_comment(): void {
        $record = api::save_comment($this->record->get('id'), "  A fair year.\n", (int) $this->alice->id);

        $this->assertSame('A fair year.', $record->get('comment'));
        $this->assertSame((int) FORMAT_PLAIN, $record->get('commentformat'));
        $this->assertSame(record::STATUS_IN_PROGRESS, $record->get('status'));

        $cleared = api::save_comment($this->record->get('id'), '   ', (int) $this->alice->id);
        $this->assertNull($cleared->get('comment'));
    }

    /**
     * An empty comment on a record that has not started does not start it.
     *
     * @return void
     */
    public function test_empty_comment_does_not_start_the_record(): void {
        $record = api::save_comment($this->record->get('id'), '', (int) $this->alice->id);

        $this->assertSame(record::STATUS_NOT_STARTED, $record->get('status'));
        $this->assertNull($record->get('comment'));
    }

    /**
     * Nobody but the owner saves the comment: not the appraisee, a second appraiser, an outsider or an
     * administrator who does not own the record.
     *
     * @return void
     */
    public function test_only_the_owner_saves_the_comment(): void {
        foreach ([$this->ellie, $this->ben, $this->uma, get_admin()] as $user) {
            try {
                api::save_comment($this->record->get('id'), 'Not mine to write', (int) $user->id);
                $this->fail("{$user->firstname} should not save the comment.");
            } catch (moodle_exception $e) {
                $this->assertSame('error:cannotwrite', $e->errorcode);
            }
        }
        $this->assertNull($this->ctx()->record->get('comment'));
    }

    /**
     * The comment is refused on a completed record, on a version that takes no comments, and when too long.
     *
     * @return void
     */
    public function test_comment_refusals(): void {
        // Built first: a caught exception leaves its transaction open, so nothing can be created after one.
        $nocomments = $this->gen->create_template([
            'commentsmode' => template_version::COMMENTS_OFF,
            'fields' => [['label' => 'Goals']],
        ]);
        $other = $this->gen->create_appraisal([
            'templateid' => $nocomments->template->get('id'),
            'userid' => $this->ellie->id,
            'appraiserid' => $this->alice->id,
        ]);

        try {
            api::save_comment(
                $this->record->get('id'),
                str_repeat('x', api::LONGTEXT_MAXLENGTH + 1),
                (int) $this->alice->id
            );
            $this->fail('An over-long comment should be refused.');
        } catch (moodle_exception $e) {
            $this->assertSame('error:answertoolong', $e->errorcode);
        }
        $this->assertNotNull(
            api::save_comment($this->record->get('id'), str_repeat('x', api::LONGTEXT_MAXLENGTH), (int) $this->alice->id)
        );

        try {
            api::save_comment($other->get('id'), 'Not wanted', (int) $this->alice->id);
            $this->fail('A version with comments off should refuse the comment.');
        } catch (moodle_exception $e) {
            $this->assertSame('error:commentsoff', $e->errorcode);
        }

        $this->force_status(record::STATUS_COMPLETED, true);
        try {
            api::save_comment($this->record->get('id'), 'Too late', (int) $this->alice->id);
            $this->fail('A completed record should refuse the comment.');
        } catch (moodle_exception $e) {
            $this->assertSame('error:cannotwrite', $e->errorcode);
        }
    }

    /**
     * The overall comment is visible to the owner always, and to the appraisee only once the answers are visible.
     *
     * @return void
     */
    public function test_overall_comment_follows_the_visibility_rule(): void {
        api::save_comment($this->record->get('id'), 'Private until submitted', (int) $this->alice->id);

        $beforesubmit = api::get_record_overall($this->ctx(), record_answer::ROLE_APPRAISEE);
        $this->assertStringNotContainsString('Private', json_encode($beforesubmit));
        $owner = api::get_record_overall($this->ctx(), record_answer::ROLE_APPRAISER);
        $this->assertStringContainsString('Private until submitted', json_encode($owner));

        $this->force_status(record::STATUS_SUBMITTED, true);
        $aftersubmit = api::get_record_overall($this->ctx(), record_answer::ROLE_APPRAISEE);
        $this->assertStringContainsString('Private until submitted', json_encode($aftersubmit));
    }
}
