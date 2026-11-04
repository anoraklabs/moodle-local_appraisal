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
 * Tests for the record lifecycle: submit, hand back, sign off, reset, cancel, take ownership and changing the due
 * date and label, with what each action is allowed from, who may take it, what it leaves behind and what it logs.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(api::class)]
final class lifecycle_test extends \advanced_testcase {
    /** @var local_appraisal_generator The plugin's data generator. */
    private local_appraisal_generator $gen;

    /** @var \stdClass The appraisee. */
    private \stdClass $ellie;

    /** @var \stdClass The record's owner. */
    private \stdClass $alice;

    /** @var \stdClass A second appraiser who reaches the appraisee but does not own the record. */
    private \stdClass $ben;

    /** @var \stdClass A user with no gate to the appraisee. */
    private \stdClass $uma;

    /** @var \stdClass The generated template: template, version and fields keyed by label. */
    private \stdClass $built;

    /** @var record The record under test, owned by Alice for Ellie. */
    private record $record;

    /** @var int The appraiser cohort's id. */
    private int $appraisercohortid;

    /**
     * Build one appraisal: Ellie appraised by Alice, with Ben as another appraiser and Uma as an outsider.
     *
     * The required fields are Goals (long text, both), Manager note (appraiser only) and Self review (appraisee
     * only). Extra note (appraiser only) is optional.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        // The lifecycle rules roll their own transactions back when they refuse, so the test must not be wrapped in one.
        $this->preventResetByRollback();
        $this->redirectMessages();
        $this->gen = $this->getDataGenerator()->get_plugin_generator('local_appraisal');

        $this->ellie = $this->getDataGenerator()->create_user(['firstname' => 'Ellie']);
        $this->alice = $this->getDataGenerator()->create_user(['firstname' => 'Alice']);
        $this->ben = $this->getDataGenerator()->create_user(['firstname' => 'Ben']);
        $this->uma = $this->getDataGenerator()->create_user(['firstname' => 'Uma']);

        $appraisers = $this->getDataGenerator()->create_cohort();
        $appraisees = $this->getDataGenerator()->create_cohort();
        $this->appraisercohortid = (int) $appraisers->id;
        cohort_add_member($appraisers->id, $this->alice->id);
        cohort_add_member($appraisers->id, $this->ben->id);
        cohort_add_member($appraisees->id, $this->ellie->id);
        $this->gen->create_cohort_link([
            'appraisercohortid' => $appraisers->id,
            'appraiseecohortid' => $appraisees->id,
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
                [
                    'type' => template_field::TYPE_SHORTTEXT,
                    'label' => 'Extra note',
                    'completedby' => template_field::COMPLETEDBY_APPRAISER,
                    'required' => false,
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
     * The record under test, read afresh.
     *
     * @return record
     */
    private function fresh(): record {
        return record::get_record(['id' => $this->record->get('id')], MUST_EXIST);
    }

    /**
     * The record's id.
     *
     * @return int
     */
    private function rid(): int {
        return $this->record->get('id');
    }

    /**
     * Save one answer for a user as the given side.
     *
     * @param \stdClass $user
     * @param string $label The field.
     * @param int $role
     * @return void
     */
    private function answer(\stdClass $user, string $label, int $role): void {
        api::save_answer($this->rid(), $this->built->fields[$label]->get('id'), $role, (int) $user->id, 'Text for ' . $label);
    }

    /**
     * Complete every required appraisee answer.
     *
     * @return void
     */
    private function complete_appraisee(): void {
        $this->answer($this->ellie, 'Goals', record_answer::ROLE_APPRAISEE);
        $this->answer($this->ellie, 'Self review', record_answer::ROLE_APPRAISEE);
    }

    /**
     * Change an appraisee answer so the appraisee can resubmit after a hand back.
     *
     * @return void
     */
    private function complete_appraisee_again(): void {
        $this->answer($this->ellie, 'Self review', record_answer::ROLE_APPRAISEE);
    }

    /**
     * Complete every required appraiser answer.
     *
     * @return void
     */
    private function complete_appraiser(): void {
        $this->answer($this->alice, 'Goals', record_answer::ROLE_APPRAISER);
        $this->answer($this->alice, 'Manager note', record_answer::ROLE_APPRAISER);
    }

    /**
     * Both people complete their parts and the appraisee submits.
     *
     * @return void
     */
    private function submitted(): void {
        $this->complete_appraisee();
        $this->complete_appraiser();
        api::submit_part($this->rid(), (int) $this->ellie->id);
    }

    /**
     * Assert that a callable is refused with the given error code.
     *
     * @param string $errorcode
     * @param callable $work
     * @return void
     */
    private function assert_refused(string $errorcode, callable $work): void {
        try {
            $work();
            $this->fail("Expected the error {$errorcode}.");
        } catch (moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * The events of one class in a sink.
     *
     * @param \phpunit_event_sink $sink
     * @param string $class
     * @return array
     */
    private function events_of(\phpunit_event_sink $sink, string $class): array {
        return array_values(array_filter($sink->get_events(), fn(\core\event\base $event): bool => $event instanceof $class));
    }

    /**
     * Submitting is refused while a required appraisee answer is missing, and says how many.
     *
     * @return void
     */
    public function test_submit_needs_every_required_appraisee_answer(): void {
        $ellie = (int) $this->ellie->id;
        $this->assert_refused('error:submitnotready', fn(): mixed => api::submit_part($this->rid(), $ellie));

        $this->answer($this->ellie, 'Goals', record_answer::ROLE_APPRAISEE);
        try {
            api::submit_part($this->rid(), $ellie);
            $this->fail('One required answer is still missing.');
        } catch (moodle_exception $e) {
            $this->assertSame('error:submitnotready', $e->errorcode);
            $this->assertStringContainsString('1', $e->a);
        }
        $this->assertSame(record::STATUS_IN_PROGRESS, $this->fresh()->get('status'));

        $this->answer($this->ellie, 'Self review', record_answer::ROLE_APPRAISEE);
        $this->assertSame(record::STATUS_SUBMITTED, api::submit_part($this->rid(), $ellie)->get('status'));
    }

    /**
     * The appraiser's answers and the optional fields do not count towards the appraisee's submit.
     *
     * @return void
     */
    public function test_submit_counts_only_the_appraisees_required_fields(): void {
        $this->complete_appraiser();
        $this->assert_refused('error:submitnotready', fn(): mixed => api::submit_part($this->rid(), (int) $this->ellie->id));

        $this->complete_appraisee();
        $this->assertSame(
            record::STATUS_SUBMITTED,
            api::submit_part($this->rid(), (int) $this->ellie->id)->get('status')
        );
    }

    /**
     * A required field completed by both sides must be answered by each of them for their own part.
     *
     * @return void
     */
    public function test_a_both_field_is_required_of_each_side_separately(): void {
        $this->answer($this->alice, 'Goals', record_answer::ROLE_APPRAISER);
        $this->answer($this->alice, 'Manager note', record_answer::ROLE_APPRAISER);
        $this->answer($this->ellie, 'Self review', record_answer::ROLE_APPRAISEE);
        // Ellie has not answered Goals, although Alice has.
        $this->assert_refused('error:submitnotready', fn(): mixed => api::submit_part($this->rid(), (int) $this->ellie->id));

        $this->answer($this->ellie, 'Goals', record_answer::ROLE_APPRAISEE);
        api::submit_part($this->rid(), (int) $this->ellie->id);

        // The appraiser side is judged on the appraiser's own answers.
        $this->assertSame([], api::get_signoff_outstanding(api::get_record_context($this->rid())));
    }

    /**
     * Submitting stamps the first submission and the start, locks the appraisee, logs it and fires the events.
     *
     * @return void
     */
    public function test_submit_locks_the_appraisee_and_logs_it(): void {
        $clock = $this->mock_clock_with_frozen();
        $this->complete_appraisee();
        $started = $this->fresh()->get('timestarted');
        $clock->bump(60);
        $sink = $this->redirectEvents();

        $record = api::submit_part($this->rid(), (int) $this->ellie->id);

        $this->assertSame($clock->time(), $record->get('timesubmitted'));
        $this->assertSame($started, $record->get('timestarted'));
        $this->assertCount(1, $this->events_of($sink, event\record_submitted::class));
        $this->assertCount(0, $this->events_of($sink, event\record_started::class), 'Already started by the first save.');
        $sink->close();

        $history = array_values(api::get_history($this->rid()));
        $last = end($history);
        $this->assertSame(record::STATUS_IN_PROGRESS, (int) $last->fromstatus);
        $this->assertSame(record::STATUS_SUBMITTED, (int) $last->tostatus);
        $this->assertSame((int) $this->ellie->id, (int) $last->actorid);

        $ctx = api::get_record_context($this->rid());
        $this->assertFalse(api::can_write_role($ctx, (int) $this->ellie->id, record_answer::ROLE_APPRAISEE));
        $this->assertTrue(api::can_write_role($ctx, (int) $this->alice->id, record_answer::ROLE_APPRAISER));
        $this->assert_refused('error:cannotsubmit', fn(): mixed => api::submit_part($this->rid(), (int) $this->ellie->id));
    }

    /**
     * Submitting from Not started is allowed when nothing is required, and fires the started event too.
     *
     * @return void
     */
    public function test_submit_from_not_started_starts_the_record(): void {
        $built = $this->gen->create_template([
            'fields' => [[
                'label' => 'Optional self review',
                'completedby' => template_field::COMPLETEDBY_APPRAISEE,
                'required' => false,
            ]],
        ]);
        $record = $this->gen->create_appraisal([
            'templateid' => $built->template->get('id'),
            'userid' => $this->ellie->id,
            'appraiserid' => $this->alice->id,
        ]);
        $sink = $this->redirectEvents();

        $submitted = api::submit_part($record->get('id'), (int) $this->ellie->id);

        $this->assertSame(record::STATUS_SUBMITTED, $submitted->get('status'));
        $this->assertNotNull($submitted->get('timestarted'));
        $this->assertCount(1, $this->events_of($sink, event\record_started::class));
        $this->assertCount(1, $this->events_of($sink, event\record_submitted::class));
        $sink->close();
    }

    /**
     * Only the appraisee submits their part: not the owner, another appraiser, an outsider or an administrator.
     *
     * @return void
     */
    public function test_only_the_appraisee_submits(): void {
        $this->complete_appraisee();
        foreach ([$this->alice, $this->ben, $this->uma, get_admin()] as $user) {
            $this->assert_refused('error:cannotsubmit', fn(): mixed => api::submit_part($this->rid(), (int) $user->id));
        }
        $this->assertSame(record::STATUS_IN_PROGRESS, $this->fresh()->get('status'));
    }

    /**
     * A version with no appraisee fields has nothing to submit.
     *
     * @return void
     */
    public function test_submit_is_refused_when_the_version_has_no_appraisee_fields(): void {
        $built = $this->gen->create_template(['fields' => [['label' => 'Only the appraiser']]]);
        $record = $this->gen->create_appraisal([
            'templateid' => $built->template->get('id'),
            'userid' => $this->ellie->id,
            'appraiserid' => $this->alice->id,
        ]);

        $this->assertFalse(api::version_has_appraisee_fields($built->version->get('id')));
        $this->assertTrue(api::version_has_appraisee_fields($this->built->version->get('id')));
        $this->assert_refused('error:cannotsubmit', fn(): mixed => api::submit_part($record->get('id'), (int) $this->ellie->id));
    }

    /**
     * A hand back unlocks the appraisee, keeps the first-submission time and logs the note on its history row.
     *
     * @return void
     */
    public function test_hand_back(): void {
        $clock = $this->mock_clock_with_frozen();
        $this->submitted();
        $firstsubmitted = $this->fresh()->get('timesubmitted');
        $clock->bump(3600);
        $sink = $this->redirectEvents();

        $record = api::hand_back($this->rid(), (int) $this->alice->id, "  Please add detail.  \n");

        $this->assertSame(record::STATUS_IN_PROGRESS, $record->get('status'));
        $this->assertSame($firstsubmitted, $record->get('timesubmitted'), 'What the appraisee has seen stays visible.');
        $this->assertCount(1, $this->events_of($sink, event\record_handedback::class));
        $sink->close();

        $history = array_values(api::get_history($this->rid()));
        $last = end($history);
        $this->assertSame(record::STATUS_SUBMITTED, (int) $last->fromstatus);
        $this->assertSame(record::STATUS_IN_PROGRESS, (int) $last->tostatus);
        $this->assertSame('Please add detail.', $last->comment);
        $this->assertSame((int) $this->alice->id, (int) $last->actorid);

        $ctx = api::get_record_context($this->rid());
        $this->assertTrue(api::can_write_role($ctx, (int) $this->ellie->id, record_answer::ROLE_APPRAISEE));
    }

    /**
     * A hand back with a blank note stores none, and a note over the limit is refused.
     *
     * @return void
     */
    public function test_hand_back_note_rules(): void {
        $this->submitted();
        $alice = (int) $this->alice->id;

        $this->assert_refused('error:answertoolong', fn(): mixed => api::hand_back($this->rid(), $alice, str_repeat('x', 5001)));
        $this->assertSame(record::STATUS_SUBMITTED, $this->fresh()->get('status'));

        api::hand_back($this->rid(), $alice, str_repeat('x', 5000));
        $history = array_values(api::get_history($this->rid()));
        $this->assertSame(5000, \core_text::strlen(end($history)->comment), 'The limit itself is allowed.');

        $this->complete_appraisee_again();
        api::submit_part($this->rid(), (int) $this->ellie->id);
        api::hand_back($this->rid(), $alice, '   ');
        $history = array_values(api::get_history($this->rid()));
        $this->assertNull(end($history)->comment);
    }

    /**
     * Only the owner hands back, and only a Submitted record.
     *
     * @return void
     */
    public function test_hand_back_is_the_owners_and_only_from_submitted(): void {
        $this->complete_appraisee();
        $this->assert_refused('error:cannothandback', fn(): mixed => api::hand_back($this->rid(), (int) $this->alice->id));

        api::submit_part($this->rid(), (int) $this->ellie->id);
        foreach ([$this->ellie, $this->ben, $this->uma, get_admin()] as $user) {
            $this->assert_refused('error:cannothandback', fn(): mixed => api::hand_back($this->rid(), (int) $user->id));
        }
        $this->assertSame(record::STATUS_SUBMITTED, $this->fresh()->get('status'));
    }

    /**
     * Sign off is refused until the appraisee has submitted, then until every required appraiser answer is in.
     *
     * @return void
     */
    public function test_sign_off_readiness(): void {
        $alice = (int) $this->alice->id;
        $this->assert_refused('error:signoffnotready', fn(): mixed => api::sign_off($this->rid(), $alice));

        $this->complete_appraisee();
        $this->assert_refused('error:signoffnotready', fn(): mixed => api::sign_off($this->rid(), $alice));
        api::submit_part($this->rid(), (int) $this->ellie->id);

        $this->answer($this->alice, 'Goals', record_answer::ROLE_APPRAISER);
        $this->assert_refused('error:signoffnotready', fn(): mixed => api::sign_off($this->rid(), $alice));

        $this->answer($this->alice, 'Manager note', record_answer::ROLE_APPRAISER);
        $this->assertSame(record::STATUS_COMPLETED, api::sign_off($this->rid(), $alice)->get('status'));
    }

    /**
     * Sign off completes the record at the moment of sign-off, logs it, fires the event and leaves nothing writable.
     *
     * @return void
     */
    public function test_sign_off_completes_and_locks_the_record(): void {
        $clock = $this->mock_clock_with_frozen();
        $this->submitted();
        $clock->bump(120);
        $sink = $this->redirectEvents();

        $record = api::sign_off($this->rid(), (int) $this->alice->id);

        $this->assertSame(record::STATUS_COMPLETED, $record->get('status'));
        $this->assertSame($clock->time(), $record->get('timecompleted'));
        $this->assertNull($record->get('overalllevelid'));
        $this->assertCount(1, $this->events_of($sink, event\record_completed::class));
        $sink->close();

        $history = array_values(api::get_history($this->rid()));
        $last = end($history);
        $this->assertSame(record::STATUS_SUBMITTED, (int) $last->fromstatus);
        $this->assertSame(record::STATUS_COMPLETED, (int) $last->tostatus);
        $this->assertSame($clock->time(), (int) $last->timecreated);
        $this->assertSame((int) $this->alice->id, (int) $last->actorid);

        $ctx = api::get_record_context($this->rid());
        $this->assertFalse(api::can_write_role($ctx, (int) $this->alice->id, record_answer::ROLE_APPRAISER));
        $this->assertFalse(api::can_write_role($ctx, (int) $this->ellie->id, record_answer::ROLE_APPRAISEE));
        $actions = api::get_record_actions($ctx, (int) $this->alice->id);
        foreach (['submit', 'handback', 'signoff', 'reset', 'cancel', 'takeownership', 'editdetails'] as $action) {
            $this->assertFalse($actions->$action, "{$action} on a Completed record.");
        }
        foreach (['handback', 'reset', 'cancel', 'takeownership'] as $name) {
            $this->assertFalse(api::get_record_actions($ctx, (int) $this->ben->id)->$name, $name);
        }
        $this->assert_refused('error:cannotsignoff', fn(): mixed => api::sign_off($this->rid(), (int) $this->alice->id));
        $this->assert_refused('error:cannothandback', fn(): mixed => api::hand_back($this->rid(), (int) $this->alice->id));
        $this->assert_refused('error:cannotreset', fn(): mixed => api::reset_record($this->rid(), (int) $this->alice->id));
        $this->assert_refused(
            'error:cannottakeownership',
            fn(): mixed => api::take_ownership($this->rid(), (int) $this->ben->id)
        );
        $this->assert_refused(
            'error:cannotact',
            fn(): mixed => api::update_record_details($this->rid(), (int) $this->alice->id, 'Late', null)
        );
    }

    /**
     * Only the owner signs off, not the appraisee, another appraiser, an outsider or an administrator.
     *
     * @return void
     */
    public function test_only_the_owner_signs_off(): void {
        $this->submitted();
        foreach ([$this->ellie, $this->ben, $this->uma, get_admin()] as $user) {
            $this->assert_refused('error:cannotsignoff', fn(): mixed => api::sign_off($this->rid(), (int) $user->id));
        }
        $this->assertSame(record::STATUS_SUBMITTED, $this->fresh()->get('status'));
    }

    /**
     * Required overall comments block the sign-off until the owner has written them.
     *
     * @return void
     */
    public function test_required_comments_block_sign_off(): void {
        $built = $this->gen->create_template([
            'commentsmode' => template_version::COMMENTS_REQUIRED,
            'fields' => [['label' => 'Rating note']],
        ]);
        $record = $this->gen->create_appraisal([
            'templateid' => $built->template->get('id'),
            'userid' => $this->ellie->id,
            'appraiserid' => $this->alice->id,
        ]);
        $recordid = $record->get('id');
        $alice = (int) $this->alice->id;
        api::save_answer($recordid, $built->fields['Rating note']->get('id'), record_answer::ROLE_APPRAISER, $alice, 'Fine');

        $this->assert_refused('error:signoffnotready', fn(): mixed => api::sign_off($recordid, $alice));

        api::save_comment($recordid, 'Well done.', $alice);
        $this->assertSame(record::STATUS_COMPLETED, api::sign_off($recordid, $alice)->get('status'));
    }

    /**
     * A version with no appraisee fields is signed off from In progress, and not from Not started, even when
     * nothing it asks of the appraiser is required.
     *
     * @return void
     */
    public function test_sign_off_without_appraisee_fields(): void {
        $built = $this->gen->create_template(['fields' => [['label' => 'Assessment', 'required' => false]]]);
        $record = $this->gen->create_appraisal([
            'templateid' => $built->template->get('id'),
            'userid' => $this->ellie->id,
            'appraiserid' => $this->alice->id,
        ]);
        $recordid = $record->get('id');
        $alice = (int) $this->alice->id;

        $this->assertNotEmpty(api::get_signoff_blocker(api::get_record_context($recordid)));
        $this->assert_refused('error:signoffnotready', fn(): mixed => api::sign_off($recordid, $alice));
        $this->assertSame(record::STATUS_NOT_STARTED, record::get_record(['id' => $recordid])->get('status'));

        api::save_answer($recordid, $built->fields['Assessment']->get('id'), record_answer::ROLE_APPRAISER, $alice, 'Good');
        $this->assertNull(api::get_signoff_blocker(api::get_record_context($recordid)));
        $this->assertSame(record::STATUS_COMPLETED, api::sign_off($recordid, $alice)->get('status'));
    }

    /**
     * The overall grade rule per mode: refused when off, required when required, a level of the chosen scale only.
     *
     * @return void
     */
    public function test_overall_grade_rules(): void {
        $scale = $this->gen->create_scale();
        $otherscale = $this->gen->create_scale(['name' => 'Other', 'levels' => ['No' => 1, 'Yes' => 2]]);
        $levels = array_values(api::get_scale_levels($scale->get('id')));
        $otherlevels = array_values(api::get_scale_levels($otherscale->get('id')));

        $off = api::get_version($this->built->version->get('id'));
        $this->assertNull(api::get_overall_grade_error($off, null));
        $this->assertSame('error:gradenotoffered', api::get_overall_grade_error($off, $levels[0]->get('id')));

        foreach ([template_version::GRADE_OPTIONAL, template_version::GRADE_REQUIRED] as $mode) {
            $built = $this->gen->create_template([
                'gradingmode' => $mode,
                'overallscaleid' => $scale->get('id'),
                'fields' => [['label' => 'Note']],
            ]);
            $version = $built->version;
            $this->assertNull(api::get_overall_grade_error($version, $levels[1]->get('id')));
            $this->assertSame('error:gradeinvalid', api::get_overall_grade_error($version, $otherlevels[0]->get('id')));
            $this->assertSame('error:gradeinvalid', api::get_overall_grade_error($version, 999999));
            $this->assertSame(
                $mode === template_version::GRADE_REQUIRED ? 'error:graderequired' : null,
                api::get_overall_grade_error($version, null)
            );
        }
    }

    /**
     * Sign off stores the chosen overall grade, and refuses a missing, foreign or unwanted one.
     *
     * @return void
     */
    public function test_sign_off_stores_the_overall_grade(): void {
        $scale = $this->gen->create_scale();
        $otherscale = $this->gen->create_scale(['name' => 'Other', 'levels' => ['No' => 1, 'Yes' => 2]]);
        $levels = array_values(api::get_scale_levels($scale->get('id')));
        $otherlevel = array_values(api::get_scale_levels($otherscale->get('id')))[0];
        $alice = (int) $this->alice->id;

        $built = $this->gen->create_template([
            'gradingmode' => template_version::GRADE_REQUIRED,
            'overallscaleid' => $scale->get('id'),
            'fields' => [['label' => 'Note']],
        ]);
        $record = $this->gen->create_appraisal([
            'templateid' => $built->template->get('id'),
            'userid' => $this->ellie->id,
            'appraiserid' => $alice,
        ]);
        $recordid = $record->get('id');
        api::save_answer($recordid, $built->fields['Note']->get('id'), record_answer::ROLE_APPRAISER, $alice, 'x');

        $this->assert_refused('error:graderequired', fn(): mixed => api::sign_off($recordid, $alice));
        $this->assert_refused('error:gradeinvalid', fn(): mixed => api::sign_off($recordid, $alice, $otherlevel->get('id')));
        $this->assertSame(record::STATUS_IN_PROGRESS, record::get_record(['id' => $recordid])->get('status'));

        $signed = api::sign_off($recordid, $alice, $levels[2]->get('id'));
        $this->assertSame($levels[2]->get('id'), $signed->get('overalllevelid'));

        // A version that takes no grade refuses one.
        $this->submitted();
        $this->assert_refused(
            'error:gradenotoffered',
            fn(): mixed => api::sign_off($this->rid(), $alice, $levels[0]->get('id'))
        );
    }

    /**
     * A sign-off date may be backdated but not future or before the start; today means the moment of sign-off.
     *
     * @return void
     */
    public function test_completion_date_rules(): void {
        $clock = $this->mock_clock_with_frozen();
        $this->setTimezone('UTC');
        $this->complete_appraisee();
        $start = $this->fresh()->get('timestarted');
        $clock->bump(5 * DAYSECS);
        $now = $clock->time();
        $record = $this->fresh();

        $this->assertSame('error:completiondatefuture', api::get_completion_date_error($record, $now + 1, $now));
        $this->assertSame(
            'error:completiondatebeforestart',
            api::get_completion_date_error($record, $start - 2 * DAYSECS, $now)
        );
        $this->assertNull(api::get_completion_date_error($record, $start + DAYSECS, $now));
        $this->assertNull(api::get_completion_date_error($record, $now, $now));

        $today = usergetmidnight($now);
        $this->assertSame($now, api::completion_date_from_day($today, $now));
        $yesterday = $today - DAYSECS;
        $this->assertSame($yesterday + 12 * HOURSECS, api::completion_date_from_day($yesterday, $now));
        $this->assertGreaterThan($now, api::completion_date_from_day($today + DAYSECS, $now));
    }

    /**
     * The start-day rule is about days: a record started in the afternoon may be signed off on its start day, which
     * is stored as midday, earlier than the start.
     *
     * @return void
     */
    public function test_completion_on_the_start_day_is_allowed(): void {
        $clock = $this->mock_clock_with_frozen(strtotime('2030-03-04 15:00:00 UTC'));
        $this->setTimezone('UTC');
        $this->submitted();
        $started = $this->fresh()->get('timestarted');
        $clock->bump(3 * DAYSECS);
        $day = usergetmidnight($started);

        $record = api::sign_off(
            $this->rid(),
            (int) $this->alice->id,
            null,
            api::completion_date_from_day($day, $clock->time())
        );

        $this->assertSame($day + 12 * HOURSECS, $record->get('timecompleted'));
        $this->assertLessThan($started, $record->get('timecompleted'));
    }

    /**
     * Sign off with a backdated day stores midday of it, and refuses a future day or one before the start.
     *
     * @return void
     */
    public function test_sign_off_with_a_chosen_date(): void {
        $clock = $this->mock_clock_with_frozen();
        $this->setTimezone('UTC');
        $this->submitted();
        $clock->bump(5 * DAYSECS);
        $now = $clock->time();
        $alice = (int) $this->alice->id;
        $started = $this->fresh()->get('timestarted');

        $tomorrow = api::completion_date_from_day(usergetmidnight($now) + DAYSECS, $now);
        $this->assert_refused(
            'error:completiondatefuture',
            fn(): mixed => api::sign_off($this->rid(), $alice, null, $tomorrow)
        );
        $this->assert_refused(
            'error:completiondatebeforestart',
            fn(): mixed => api::sign_off($this->rid(), $alice, null, usergetmidnight($started) - DAYSECS + 12 * HOURSECS)
        );
        $this->assertSame(record::STATUS_SUBMITTED, $this->fresh()->get('status'));

        $day = usergetmidnight($now) - 2 * DAYSECS;
        $record = api::sign_off($this->rid(), $alice, null, api::completion_date_from_day($day, $now));
        $this->assertSame($day + 12 * HOURSECS, $record->get('timecompleted'));
        $history = array_values(api::get_history($this->rid()));
        $this->assertSame($now, (int) end($history)->timecreated, 'The history keeps the real time of the sign-off.');
    }

    /**
     * A reset deletes both sides' answers, the comment and grade, clears the start and submission times, and the
     * visibility rule starts again.
     *
     * @return void
     */
    public function test_reset_clears_everything_and_restarts_visibility(): void {
        $this->submitted();
        api::save_comment($this->rid(), 'Overall note', (int) $this->alice->id);
        $other = $this->gen->create_template(['fields' => [['label' => 'Other note']]]);
        $otherrecord = $this->gen->create_appraisal([
            'templateid' => $other->template->get('id'),
            'userid' => $this->ellie->id,
            'appraiserid' => $this->alice->id,
        ]);
        api::save_answer(
            $otherrecord->get('id'),
            $other->fields['Other note']->get('id'),
            record_answer::ROLE_APPRAISER,
            (int) $this->alice->id,
            'Keep me'
        );
        $historybefore = count(api::get_history($this->rid()));
        $sink = $this->redirectEvents();

        $record = api::reset_record($this->rid(), (int) $this->alice->id);

        $this->assertSame(record::STATUS_NOT_STARTED, $record->get('status'));
        $this->assertNull($record->get('timestarted'));
        $this->assertNull($record->get('timesubmitted'));
        $this->assertNull($record->get('comment'));
        $this->assertNull($record->get('overalllevelid'));
        $this->assertCount(0, api::get_answers($this->rid()));
        $this->assertCount(1, $this->events_of($sink, event\record_reset::class));
        $sink->close();

        $history = array_values(api::get_history($this->rid()));
        $this->assertCount($historybefore + 1, $history, 'Earlier history rows survive a reset.');
        $this->assertSame(record::STATUS_SUBMITTED, (int) end($history)->fromstatus);
        $this->assertSame(record::STATUS_NOT_STARTED, (int) end($history)->tostatus);
        $this->assertSame((int) $this->alice->id, (int) end($history)->actorid);

        // Another record's answers and history are not touched.
        $this->assertCount(1, api::get_answers($otherrecord->get('id')));
        $this->assertCount(2, api::get_history($otherrecord->get('id')));

        // Nothing of the other side is visible again until the appraisee submits afresh.
        $this->answer($this->alice, 'Manager note', record_answer::ROLE_APPRAISER);
        $ctx = api::get_record_context($this->rid());
        $hidden = 0;
        foreach (api::get_record_fields($ctx, record_answer::ROLE_APPRAISEE, (int) $this->ellie->id) as $row) {
            if ($row->theirs) {
                $hidden++;
                $this->assertNull($row->other, 'The appraiser answer stays hidden from the appraisee.');
                $this->assertTrue($row->otherhidden);
            }
        }
        $this->assertGreaterThan(0, $hidden);
        $this->assertTrue(api::can_write_role($ctx, (int) $this->ellie->id, record_answer::ROLE_APPRAISEE));
    }

    /**
     * A reset is the owner's, and only while In progress or Submitted.
     *
     * @return void
     */
    public function test_reset_rules(): void {
        $alice = (int) $this->alice->id;
        $this->assert_refused('error:cannotreset', fn(): mixed => api::reset_record($this->rid(), $alice));

        $this->answer($this->alice, 'Manager note', record_answer::ROLE_APPRAISER);
        foreach ([$this->ellie, $this->ben, $this->uma, get_admin()] as $user) {
            $this->assert_refused('error:cannotreset', fn(): mixed => api::reset_record($this->rid(), (int) $user->id));
        }
        $this->assertCount(1, api::get_answers($this->rid()));

        $this->assertSame(record::STATUS_NOT_STARTED, api::reset_record($this->rid(), $alice)->get('status'));
    }

    /**
     * Cancelling deletes a Not started record with its history, for the owner or a site administrator.
     *
     * @return void
     */
    public function test_cancel_a_not_started_record(): void {
        $alice = (int) $this->alice->id;
        foreach ([$this->ellie, $this->ben, $this->uma] as $user) {
            $this->assert_refused('error:cannotcancel', fn(): mixed => api::cancel_record($this->rid(), (int) $user->id));
        }
        $this->assertTrue(record::record_exists($this->rid()));

        api::cancel_record($this->rid(), $alice);

        $this->assertFalse(record::record_exists($this->rid()));
        $this->assertSame([], api::get_history($this->rid()));
        $this->assertTrue(assignment::record_exists($this->record->get('assignmentid')), 'The assignment stays.');
    }

    /**
     * A site administrator who can reach the appraisee may cancel someone else's record; others may not.
     *
     * @return void
     */
    public function test_a_site_administrator_may_cancel(): void {
        $this->assertFalse(api::get_record_actions(api::get_record_context($this->rid()), (int) $this->ben->id)->cancel);
        $this->assertTrue(api::get_record_actions(api::get_record_context($this->rid()), (int) get_admin()->id)->cancel);

        api::cancel_record($this->rid(), (int) get_admin()->id);

        $this->assertFalse(record::record_exists($this->rid()));
    }

    /**
     * Cancelling also removes the record's transfer log.
     *
     * @return void
     */
    public function test_cancel_removes_the_transfer_log(): void {
        global $DB;
        api::take_ownership($this->rid(), (int) $this->ben->id);
        $this->assertCount(1, api::get_transfers($this->rid()));

        api::cancel_record($this->rid(), (int) $this->ben->id);

        $this->assertSame(0, $DB->count_records('local_appraisal_record_transfer', ['recordid' => $this->rid()]));
    }

    /**
     * An administrator may cancel only a record of someone they can reach, never their own appraisal.
     *
     * @return void
     */
    public function test_an_administrator_cannot_cancel_their_own_appraisal(): void {
        $admin = get_admin();
        $record = $this->gen->create_appraisal([
            'templateid' => $this->built->template->get('id'),
            'userid' => $admin->id,
            'appraiserid' => $this->alice->id,
            'nogate' => true,
        ]);
        $ctx = api::get_record_context($record->get('id'));

        $this->assertFalse(api::get_record_actions($ctx, (int) $admin->id)->cancel);
        $this->assert_refused('error:cannotcancel', fn(): mixed => api::cancel_record($record->get('id'), (int) $admin->id));
        $this->assertTrue(record::record_exists($record->get('id')));
    }

    /**
     * An owner who has left the appraiser cohort can no longer act on the record, though it is still theirs.
     *
     * @return void
     */
    public function test_an_owner_who_loses_the_gate_loses_every_action(): void {
        $this->submitted();
        cohort_remove_member($this->appraisercohortid, $this->alice->id);

        $this->assert_owner_actions_refused();
    }

    /**
     * An owner who loses the appraise capability can no longer act on the record either.
     *
     * @return void
     */
    public function test_an_owner_who_loses_the_capability_loses_every_action(): void {
        $this->submitted();
        $this->gen->set_user_capability($this->alice->id, 'local/appraisal:appraise', CAP_PROHIBIT);

        $this->assert_owner_actions_refused();
    }

    /**
     * Assert that Alice, still the record's appraiser, is refused every owner action.
     *
     * @return void
     */
    private function assert_owner_actions_refused(): void {
        $alice = (int) $this->alice->id;
        $this->assertSame($alice, $this->fresh()->get('appraiserid'));
        $actions = api::get_record_actions(api::get_record_context($this->rid()), $alice);
        foreach (['handback', 'signoff', 'reset', 'cancel', 'editdetails'] as $name) {
            $this->assertFalse($actions->$name, $name);
        }
        $this->assert_refused('error:cannothandback', fn(): mixed => api::hand_back($this->rid(), $alice));
        $this->assert_refused('error:cannotsignoff', fn(): mixed => api::sign_off($this->rid(), $alice));
        $this->assert_refused('error:cannotreset', fn(): mixed => api::reset_record($this->rid(), $alice));
        $this->assert_refused('error:cannotcancel', fn(): mixed => api::cancel_record($this->rid(), $alice));
        $this->assert_refused(
            'error:cannotact',
            fn(): mixed => api::update_record_details($this->rid(), $alice, 'Late', null)
        );
        $this->assertSame(record::STATUS_SUBMITTED, $this->fresh()->get('status'));
    }

    /**
     * Cancel is refused once the record has started, or holds an answer or comment, and the record stays.
     *
     * @return void
     */
    public function test_cancel_is_refused_once_started_or_with_content(): void {
        $alice = (int) $this->alice->id;
        $this->answer($this->alice, 'Manager note', record_answer::ROLE_APPRAISER);
        $this->assert_refused('error:cannotcancel', fn(): mixed => api::cancel_record($this->rid(), $alice));

        // A Not started record holding something is refused for the content, not the status.
        $record = $this->fresh();
        $record->set('status', record::STATUS_NOT_STARTED);
        $record->update();
        $this->assert_refused('error:recordhascontent', fn(): mixed => api::cancel_record($this->rid(), $alice));

        global $DB;
        $DB->delete_records(record_answer::TABLE, ['recordid' => $this->rid()]);
        $record = $this->fresh();
        $record->set('comment', 'A comment');
        $record->update();
        $this->assert_refused('error:recordhascontent', fn(): mixed => api::cancel_record($this->rid(), $alice));
        $this->assertTrue(record::record_exists($this->rid()));
    }

    /**
     * Taking ownership moves the record, logs the transfer, fires the event and the new owner replaces answers as
     * themselves.
     *
     * @return void
     */
    public function test_take_ownership(): void {
        $clock = $this->mock_clock_with_frozen();
        $this->answer($this->alice, 'Manager note', record_answer::ROLE_APPRAISER);
        $noteid = $this->built->fields['Manager note']->get('id');
        $clock->bump(600);
        $sink = $this->redirectEvents();

        $record = api::take_ownership($this->rid(), (int) $this->ben->id);

        $this->assertSame((int) $this->ben->id, $record->get('appraiserid'));
        $this->assertCount(1, $this->events_of($sink, event\record_transferred::class));
        $sink->close();
        $transfers = array_values(api::get_transfers($this->rid()));
        $this->assertCount(1, $transfers);
        $this->assertSame((int) $this->alice->id, (int) $transfers[0]->fromappraiserid);
        $this->assertSame((int) $this->ben->id, (int) $transfers[0]->toappraiserid);
        $this->assertSame((int) $this->ben->id, (int) $transfers[0]->actorid);

        $ctx = api::get_record_context($this->rid());
        $this->assertFalse(api::can_write_role($ctx, (int) $this->alice->id, record_answer::ROLE_APPRAISER));
        $this->assertTrue(api::can_write_role($ctx, (int) $this->ben->id, record_answer::ROLE_APPRAISER));

        // The new owner overwrites the previous owner's answer, and the answer shows who saved it last.
        api::save_answer($this->rid(), $noteid, record_answer::ROLE_APPRAISER, (int) $this->ben->id, 'Ben wrote this');
        $answers = array_values(array_filter(
            api::get_answers($this->rid()),
            fn(record_answer $answer): bool => $answer->get('fieldid') === $noteid
        ));
        $this->assertCount(1, $answers);
        $this->assertSame('Ben wrote this', $answers[0]->get('valuetext'));
        $this->assertSame((int) $this->ben->id, $answers[0]->get('userid'));
        $this->assertSame($clock->time(), $answers[0]->get('timeanswered'));
    }

    /**
     * Take ownership is for another appraiser who reaches the appraisee, on an open record only.
     *
     * @return void
     */
    public function test_take_ownership_rules(): void {
        foreach ([$this->alice, $this->ellie, $this->uma] as $user) {
            $this->assert_refused(
                'error:cannottakeownership',
                fn(): mixed => api::take_ownership($this->rid(), (int) $user->id)
            );
        }
        $this->assertSame((int) $this->alice->id, $this->fresh()->get('appraiserid'));
        $this->assertSame([], api::get_transfers($this->rid()));

        $this->gen->set_user_capability($this->ben->id, 'local/appraisal:appraise', CAP_PROHIBIT);
        $this->assert_refused(
            'error:cannottakeownership',
            fn(): mixed => api::take_ownership($this->rid(), (int) $this->ben->id)
        );

        // Nobody appraises themselves: with a gate that would otherwise reach her, Ellie is still refused.
        cohort_add_member($this->appraisercohortid, $this->ellie->id);
        $this->assertFalse(
            api::get_record_actions(api::get_record_context($this->rid()), (int) $this->ellie->id)->takeownership
        );
        $this->assert_refused(
            'error:cannottakeownership',
            fn(): mixed => api::take_ownership($this->rid(), (int) $this->ellie->id)
        );
    }

    /**
     * A site administrator reaches the appraisee through the site-wide gate, so may take ownership.
     *
     * @return void
     */
    public function test_take_ownership_by_an_administrator(): void {
        $record = api::take_ownership($this->rid(), (int) get_admin()->id);

        $this->assertSame((int) get_admin()->id, $record->get('appraiserid'));
    }

    /**
     * Changing the due date clears the reminder stamp; keeping it leaves the stamp, and the label is normalised.
     *
     * @return void
     */
    public function test_update_details(): void {
        $alice = (int) $this->alice->id;
        $clock = $this->mock_clock_with_frozen();
        $due = api::due_date_from_day($clock->time() + 10 * DAYSECS);
        $record = api::update_record_details($this->rid(), $alice, '  Mid-year  ', $due);
        $this->assertSame('Mid-year', $record->get('label'));
        $this->assertSame($due, $record->get('duedate'));

        $record->set('timereminded', $clock->time());
        $record->update();
        $record = api::update_record_details($this->rid(), $alice, 'Mid-year review', $due);
        $this->assertNotNull($record->get('timereminded'), 'The same due date keeps the stamp.');
        $this->assertSame('Mid-year review', $record->get('label'));

        $record = api::update_record_details($this->rid(), $alice, '   ', $due + DAYSECS);
        $this->assertNull($record->get('timereminded'), 'A new due date earns its own reminder.');
        $this->assertNull($record->get('label'));

        $record = api::update_record_details($this->rid(), $alice, null, null);
        $this->assertNull($record->get('duedate'));
    }

    /**
     * Only the owner changes the label and due date.
     *
     * @return void
     */
    public function test_update_details_is_the_owners(): void {
        foreach ([$this->ellie, $this->ben, $this->uma, get_admin()] as $user) {
            $this->assert_refused(
                'error:cannotact',
                fn(): mixed => api::update_record_details($this->rid(), (int) $user->id, 'Other', null)
            );
        }
        $this->assertNull($this->fresh()->get('label'));
    }

    /**
     * What each person may do to the record in each status.
     *
     * @return void
     */
    public function test_the_action_matrix(): void {
        $names = ['submit', 'handback', 'signoff', 'reset', 'cancel', 'takeownership', 'editdetails'];
        $who = ['ellie' => $this->ellie, 'alice' => $this->alice, 'ben' => $this->ben, 'uma' => $this->uma];
        $expected = [
            'not started' => [
                'ellie' => ['submit'],
                'alice' => ['signoff', 'cancel', 'editdetails'],
                'ben' => ['takeownership'],
                'uma' => [],
            ],
            'in progress' => [
                'ellie' => ['submit'],
                'alice' => ['signoff', 'reset', 'editdetails'],
                'ben' => ['takeownership'],
                'uma' => [],
            ],
            'submitted' => [
                'ellie' => [],
                'alice' => ['handback', 'signoff', 'reset', 'editdetails'],
                'ben' => ['takeownership'],
                'uma' => [],
            ],
            'completed' => ['ellie' => [], 'alice' => [], 'ben' => [], 'uma' => []],
        ];
        $statuses = [
            'not started' => record::STATUS_NOT_STARTED,
            'in progress' => record::STATUS_IN_PROGRESS,
            'submitted' => record::STATUS_SUBMITTED,
            'completed' => record::STATUS_COMPLETED,
        ];

        foreach ($statuses as $label => $status) {
            $record = $this->fresh();
            $record->set('status', $status);
            $record->update();
            $ctx = api::get_record_context($this->rid());
            foreach ($who as $person => $user) {
                $actions = api::get_record_actions($ctx, (int) $user->id);
                $allowed = array_values(array_filter($names, fn(string $name): bool => $actions->$name));
                $this->assertEqualsCanonicalizing($expected[$label][$person], $allowed, "{$person} when {$label}");
            }
        }
    }

    /**
     * The readiness lists say what is outstanding, and empty out as the answers arrive.
     *
     * @return void
     */
    public function test_outstanding_lists(): void {
        $ctx = api::get_record_context($this->rid());
        $actions = api::get_record_actions($ctx, (int) $this->ellie->id);
        $this->assertCount(1, $actions->submitoutstanding);
        $this->assertSame([], api::get_record_actions($ctx, (int) $this->uma->id)->submitoutstanding);

        $this->complete_appraisee();
        $ctx = api::get_record_context($this->rid());
        $this->assertSame([], api::get_submit_outstanding($ctx));
        $this->assertNotEmpty(api::get_signoff_blocker($ctx), 'Not submitted yet.');
        $this->assertNotEmpty(api::get_record_actions($ctx, (int) $this->alice->id)->signoffoutstanding);

        api::submit_part($this->rid(), (int) $this->ellie->id);
        $this->complete_appraiser();
        $ctx = api::get_record_context($this->rid());
        $this->assertNull(api::get_signoff_blocker($ctx));
        $this->assertSame([], api::get_record_actions($ctx, (int) $this->alice->id)->signoffoutstanding);
    }

    /**
     * A full path with a hand back: submit, hand back, resubmit, sign off, with the history in order.
     *
     * @return void
     */
    public function test_the_full_path_with_a_hand_back(): void {
        $clock = $this->mock_clock_with_frozen();
        $this->submitted();
        $firstsubmitted = $this->fresh()->get('timesubmitted');
        api::hand_back($this->rid(), (int) $this->alice->id, 'Add detail');
        $this->answer($this->ellie, 'Self review', record_answer::ROLE_APPRAISEE);
        $clock->bump(DAYSECS);
        $resubmitted = api::submit_part($this->rid(), (int) $this->ellie->id);
        $this->assertSame($firstsubmitted, $resubmitted->get('timesubmitted'), 'A resubmit keeps the first submission.');
        api::sign_off($this->rid(), (int) $this->alice->id);

        $steps = array_map(
            fn(\stdClass $row): array => [$row->fromstatus === null ? null : (int) $row->fromstatus, (int) $row->tostatus],
            array_values(api::get_history($this->rid()))
        );
        $this->assertSame([
            [null, record::STATUS_NOT_STARTED],
            [record::STATUS_NOT_STARTED, record::STATUS_IN_PROGRESS],
            [record::STATUS_IN_PROGRESS, record::STATUS_SUBMITTED],
            [record::STATUS_SUBMITTED, record::STATUS_IN_PROGRESS],
            [record::STATUS_IN_PROGRESS, record::STATUS_SUBMITTED],
            [record::STATUS_SUBMITTED, record::STATUS_COMPLETED],
        ], $steps);
    }
}
