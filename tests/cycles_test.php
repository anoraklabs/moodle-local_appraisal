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
 * Tests for assignments and cycles: assigning a template, one open cycle per assignment, the ban on appraising
 * oneself, starting a new cycle on the latest version, removing and deleting assignments, and the events they fire.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(api::class)]
final class cycles_test extends \advanced_testcase {
    /** @var local_appraisal_generator The plugin's data generator. */
    private local_appraisal_generator $gen;

    /** @var \stdClass The appraisee. */
    private \stdClass $ellie;

    /** @var \stdClass An appraiser who reaches Ellie through a cohort link. */
    private \stdClass $alice;

    /** @var \stdClass A user with no gate to Ellie. */
    private \stdClass $uma;

    /** @var \stdClass The generated template: template, version and fields keyed by label. */
    private \stdClass $built;

    /** @var int The appraiser cohort's id. */
    private int $appraisercohortid;

    /** @var int The appraisee cohort's id, which the appraiser cohort is linked to. */
    private int $appraiseecohortid;

    /**
     * Build the people and one published template with a field the appraiser completes and one the appraisee does.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        // Refusals inside the plugin's locks roll their transaction back, so the test must not be wrapped in one.
        $this->preventResetByRollback();
        $this->redirectMessages();
        $this->gen = $this->getDataGenerator()->get_plugin_generator('local_appraisal');

        $this->ellie = $this->getDataGenerator()->create_user(['firstname' => 'Ellie']);
        $this->alice = $this->getDataGenerator()->create_user(['firstname' => 'Alice']);
        $this->uma = $this->getDataGenerator()->create_user(['firstname' => 'Uma']);

        $appraisers = $this->getDataGenerator()->create_cohort();
        $appraisees = $this->getDataGenerator()->create_cohort();
        $this->appraisercohortid = (int) $appraisers->id;
        $this->appraiseecohortid = (int) $appraisees->id;
        cohort_add_member($appraisers->id, $this->alice->id);
        cohort_add_member($appraisees->id, $this->ellie->id);
        $this->gen->create_cohort_link([
            'appraisercohortid' => $appraisers->id,
            'appraiseecohortid' => $appraisees->id,
        ]);

        $this->built = $this->gen->create_template([
            'fields' => [
                ['label' => 'Manager note', 'completedby' => template_field::COMPLETEDBY_APPRAISER],
                ['label' => 'Self review', 'completedby' => template_field::COMPLETEDBY_APPRAISEE],
            ],
        ]);
    }

    /**
     * The id of the generated template.
     *
     * @return int
     */
    private function templateid(): int {
        return $this->built->template->get('id');
    }

    /**
     * Assign the generated template to Ellie, owned by Alice.
     *
     * @return record
     */
    private function assign(): record {
        return $this->gen->create_appraisal([
            'templateid' => $this->templateid(),
            'userid' => $this->ellie->id,
            'appraiserid' => $this->alice->id,
        ]);
    }

    /**
     * Complete and sign off a record, as both people would.
     *
     * @param record $record
     * @return record The completed record.
     */
    private function complete(record $record): record {
        $recordid = $record->get('id');
        $fields = api::get_fields($record->get('template_versionid'));
        foreach ($fields as $field) {
            $appraiser = $field->is_completed_by_appraiser();
            api::save_answer(
                $recordid,
                $field->get('id'),
                $appraiser ? record_answer::ROLE_APPRAISER : record_answer::ROLE_APPRAISEE,
                (int) ($appraiser ? $this->alice->id : $this->ellie->id),
                'Done'
            );
        }
        api::submit_part($recordid, (int) $this->ellie->id);
        return api::sign_off($recordid, (int) $this->alice->id);
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
     * Assigning creates the assignment and a Not started record on the published version, owned by the assigner,
     * with its history row and the two events.
     *
     * @return void
     */
    public function test_assign_template(): void {
        $clock = $this->mock_clock_with_frozen();
        $sink = $this->redirectEvents();
        $due = api::due_date_from_day($clock->time() + 14 * DAYSECS);

        $record = api::assign_template($this->templateid(), (int) $this->ellie->id, (int) $this->alice->id, '  Spring  ', $due);

        $this->assertSame(record::STATUS_NOT_STARTED, $record->get('status'));
        $this->assertSame((int) $this->alice->id, $record->get('appraiserid'));
        $this->assertSame($this->built->version->get('id'), $record->get('template_versionid'));
        $this->assertSame('Spring', $record->get('label'));
        $this->assertSame($due, $record->get('duedate'));
        $this->assertCount(1, $this->events_of($sink, event\assignment_created::class));
        $this->assertCount(1, $this->events_of($sink, event\record_created::class));
        $sink->close();

        $assignment = api::get_assignment($record->get('assignmentid'));
        $this->assertSame((int) $this->ellie->id, $assignment->get('userid'));
        $this->assertSame($this->templateid(), $assignment->get('templateid'));
        $history = array_values(api::get_history($record->get('id')));
        $this->assertCount(1, $history);
        $this->assertNull($history[0]->fromstatus);
        $this->assertSame(record::STATUS_NOT_STARTED, (int) $history[0]->tostatus);
    }

    /**
     * Nobody is the appraiser of their own appraisal, whichever way the record is made.
     *
     * @return void
     */
    public function test_nobody_appraises_themselves(): void {
        $ellie = (int) $this->ellie->id;
        $this->assert_refused('error:selfappraisal', fn(): mixed => api::assign_template($this->templateid(), $ellie, $ellie));

        $record = $this->assign();
        $assignmentid = $record->get('assignmentid');
        $versionid = $record->get('template_versionid');
        $this->assert_refused('error:selfappraisal', fn(): mixed => api::create_record($assignmentid, $versionid, $ellie));

        api::cancel_record($record->get('id'), (int) $this->alice->id);

        // With a gate that would otherwise reach her, Ellie still cannot start a cycle on her own assignment.
        cohort_add_member($this->appraisercohortid, $this->ellie->id);
        $this->assertFalse(access::can_appraise($ellie, $ellie));
        $this->assert_refused('error:nodashboardaccess', fn(): mixed => api::start_cycle($assignmentid, $ellie));
        $this->assertSame(0, record::count_records(['assignmentid' => $assignmentid]));
    }

    /**
     * Assigning is refused for an archived or unpublished template and for one already assigned to the user.
     *
     * @return void
     */
    public function test_assign_template_refusals(): void {
        $this->assign();
        $ellie = (int) $this->ellie->id;
        $alice = (int) $this->alice->id;
        $this->assert_refused('error:cannotassign', fn(): mixed => api::assign_template($this->templateid(), $ellie, $alice));

        $unpublished = $this->gen->create_template(['fields' => [['label' => 'Note']], 'publish' => false]);
        $this->assert_refused(
            'error:cannotassign',
            fn(): mixed => api::assign_template($unpublished->template->get('id'), $ellie, $alice)
        );

        $archived = $this->gen->create_template(['fields' => [['label' => 'Note']]]);
        api::archive_template($archived->template->get('id'));
        $this->assert_refused(
            'error:cannotassign',
            fn(): mixed => api::assign_template($archived->template->get('id'), $ellie, $alice)
        );
        $this->assertArrayNotHasKey($archived->template->get('id'), api::get_assignable_templates($ellie));
        $this->assertArrayNotHasKey($this->templateid(), api::get_assignable_templates($ellie), 'Already assigned.');
    }

    /**
     * An assignment has at most one open cycle: a second record is refused while one is open, and allowed once it
     * is completed.
     *
     * @return void
     */
    public function test_one_open_cycle_per_assignment(): void {
        $first = $this->assign();
        $assignmentid = $first->get('assignmentid');
        $versionid = $first->get('template_versionid');
        $alice = (int) $this->alice->id;

        $assertopen = function () use ($assignmentid, $versionid, $alice, $first): void {
            $this->assert_refused('error:opencycleexists', fn(): mixed => api::create_record($assignmentid, $versionid, $alice));
            $this->assert_refused('error:opencycleexists', fn(): mixed => api::start_cycle($assignmentid, $alice));
            $this->assertSame('error:opencycleexists', api::get_start_blocker(api::get_assignment($assignmentid)));
            $this->assertSame(1, record::count_records(['assignmentid' => $assignmentid]));
            $this->assertSame($first->get('id'), api::get_open_record($assignmentid)->get('id'));
        };

        // Open in every status before Completed.
        $assertopen();
        $managernote = $this->built->fields['Manager note']->get('id');
        api::save_answer($first->get('id'), $managernote, record_answer::ROLE_APPRAISER, $alice, 'Started');
        $this->assertSame(record::STATUS_IN_PROGRESS, record::get_record(['id' => $first->get('id')])->get('status'));
        $assertopen();
        $selfreview = $this->built->fields['Self review']->get('id');
        api::save_answer($first->get('id'), $selfreview, record_answer::ROLE_APPRAISEE, (int) $this->ellie->id, 'Mine');
        api::submit_part($first->get('id'), (int) $this->ellie->id);
        $this->assertSame(record::STATUS_SUBMITTED, record::get_record(['id' => $first->get('id')])->get('status'));
        $assertopen();

        api::sign_off($first->get('id'), $alice);

        $this->assertNull(api::get_open_record($assignmentid));
        $this->assertNull(api::get_start_blocker(api::get_assignment($assignmentid)));
        $second = api::start_cycle($assignmentid, $alice, 'Autumn');
        $this->assertSame(2, record::count_records(['assignmentid' => $assignmentid]));
        $this->assertSame($second->get('id'), api::get_open_record($assignmentid)->get('id'));
    }

    /**
     * A new cycle uses the template's latest published version, owned by whoever starts it, and leaves the
     * completed cycle untouched.
     *
     * @return void
     */
    public function test_a_new_cycle_uses_the_latest_version(): void {
        $first = $this->complete($this->assign());
        $oldversionid = $first->get('template_versionid');
        $completedat = $first->get('timecompleted');

        $draft = api::create_draft($this->templateid());
        api::add_field(
            $draft->get('id'),
            template_field::TYPE_SHORTTEXT,
            'A new question',
            template_field::COMPLETEDBY_APPRAISER,
            true
        );
        $newversion = api::publish_version($draft->get('id'));

        $sink = $this->redirectEvents();
        $second = api::start_cycle($first->get('assignmentid'), (int) $this->alice->id, '  Autumn ', null);

        $this->assertSame($newversion->get('id'), $second->get('template_versionid'));
        $this->assertNotSame($oldversionid, $second->get('template_versionid'));
        $this->assertSame((int) $this->alice->id, $second->get('appraiserid'));
        $this->assertSame('Autumn', $second->get('label'));
        $this->assertSame(record::STATUS_NOT_STARTED, $second->get('status'));
        $this->assertCount(1, $this->events_of($sink, event\record_created::class));
        $this->assertCount(0, $this->events_of($sink, event\assignment_created::class));
        $sink->close();

        $reread = record::get_record(['id' => $first->get('id')], MUST_EXIST);
        $this->assertSame($oldversionid, $reread->get('template_versionid'), 'The completed cycle stays on its version.');
        $this->assertSame(record::STATUS_COMPLETED, $reread->get('status'));
        $this->assertSame($completedat, $reread->get('timecompleted'));
        $this->assertSame($first->get('id'), api::get_previous_record($second)->get('id'));
    }

    /**
     * A cycle can only be started by someone who can appraise the appraisee, on an active, published template.
     *
     * @return void
     */
    public function test_start_cycle_refusals(): void {
        $first = $this->complete($this->assign());
        $assignmentid = $first->get('assignmentid');

        $this->assert_refused('error:nodashboardaccess', fn(): mixed => api::start_cycle($assignmentid, (int) $this->uma->id));
        $this->assert_refused('error:nodashboardaccess', fn(): mixed => api::start_cycle($assignmentid, (int) $this->ellie->id));

        api::archive_template($this->templateid());
        $this->assertSame('error:templateinactive', api::get_start_blocker(api::get_assignment($assignmentid)));
        $this->assert_refused('error:templateinactive', fn(): mixed => api::start_cycle($assignmentid, (int) $this->alice->id));

        api::unarchive_template($this->templateid());
        $this->assertNull(api::get_start_blocker(api::get_assignment($assignmentid)));
        $this->assertSame(1, record::count_records(['assignmentid' => $assignmentid]));
    }

    /**
     * A record can only be created on the template's published version of an active template.
     *
     * @return void
     */
    public function test_create_record_needs_the_published_version(): void {
        $assignment = api::create_assignment($this->templateid(), (int) $this->ellie->id);
        $alice = (int) $this->alice->id;
        $draft = api::create_draft($this->templateid());

        $this->assert_refused(
            'error:cannotstartcycle',
            fn(): mixed => api::create_record($assignment->get('id'), $draft->get('id'), $alice)
        );
        $this->assert_refused(
            'error:cannotstartcycle',
            fn(): mixed => api::create_record($assignment->get('id'), $this->built->version->get('id') + 1000, $alice)
        );

        api::archive_template($this->templateid());
        $this->assert_refused(
            'error:cannotstartcycle',
            fn(): mixed => api::create_record($assignment->get('id'), $this->built->version->get('id'), $alice)
        );
        $this->assertSame(0, record::count_records(['assignmentid' => $assignment->get('id')]));
    }

    /**
     * A template cannot be assigned to the same user twice.
     *
     * @return void
     */
    public function test_one_assignment_per_template_and_user(): void {
        api::create_assignment($this->templateid(), (int) $this->ellie->id);

        $this->assert_refused(
            'error:alreadyassigned',
            fn(): mixed => api::create_assignment($this->templateid(), (int) $this->ellie->id)
        );
        $other = api::create_assignment($this->templateid(), (int) $this->uma->id);
        $this->assertSame((int) $this->uma->id, $other->get('userid'));
    }

    /**
     * Remove assignment works on an assignment with no cycles, and only for someone who can appraise the appraisee.
     *
     * @return void
     */
    public function test_remove_assignment(): void {
        $record = $this->assign();
        $assignmentid = $record->get('assignmentid');
        $alice = (int) $this->alice->id;

        $this->assertSame('error:assignmenthasrecords', api::get_remove_blocker($assignmentid));
        $this->assert_refused('error:assignmenthasrecords', fn(): mixed => api::remove_assignment($assignmentid, $alice));
        $uma = (int) $this->uma->id;
        $this->assert_refused('error:nodashboardaccess', fn(): mixed => api::remove_assignment($assignmentid, $uma));

        api::cancel_record($record->get('id'), $alice);
        $this->assertNull(api::get_remove_blocker($assignmentid));
        $uma = (int) $this->uma->id;
        $this->assert_refused('error:nodashboardaccess', fn(): mixed => api::remove_assignment($assignmentid, $uma));
        api::remove_assignment($assignmentid, $alice);

        $this->assertFalse(assignment::record_exists($assignmentid));
    }

    /**
     * Losing the gate ends the right to start a cycle or remove an assignment.
     *
     * @return void
     */
    public function test_cycle_actions_follow_the_gate(): void {
        $record = $this->assign();
        api::cancel_record($record->get('id'), (int) $this->alice->id);
        $assignmentid = $record->get('assignmentid');

        cohort_remove_member($this->appraisercohortid, $this->alice->id);

        $this->assert_refused('error:nodashboardaccess', fn(): mixed => api::start_cycle($assignmentid, (int) $this->alice->id));
        $alice = (int) $this->alice->id;
        $this->assert_refused('error:nodashboardaccess', fn(): mixed => api::remove_assignment($assignmentid, $alice));
    }

    /**
     * Deleting an assignment removes every cycle with its answers, history and transfers, and fires one event that
     * keeps the template name and counts.
     *
     * @return void
     */
    public function test_delete_assignment_removes_everything(): void {
        global $DB;

        $first = $this->complete($this->assign());
        $assignmentid = $first->get('assignmentid');
        $second = api::start_cycle($assignmentid, (int) $this->alice->id);
        api::save_answer(
            $second->get('id'),
            $this->built->fields['Manager note']->get('id'),
            record_answer::ROLE_APPRAISER,
            (int) $this->alice->id,
            'Draft'
        );
        api::log_transfer($second->get('id'), (int) $this->alice->id, (int) $this->alice->id, (int) $this->alice->id);
        $recordids = [$first->get('id'), $second->get('id')];

        $summary = api::get_assignment_deletion_summary($assignmentid);
        $this->assertSame(2, $summary->records);
        $this->assertSame(1, $summary->completed);

        $sink = $this->redirectEvents();
        api::delete_assignment($assignmentid);
        $events = $this->events_of($sink, event\assignment_deleted::class);
        $sink->close();

        $this->assertFalse(assignment::record_exists($assignmentid));
        [$insql, $params] = $DB->get_in_or_equal($recordids);
        $this->assertSame(0, $DB->count_records_select('local_appraisal_record', 'id ' . $insql, $params));
        foreach (['local_appraisal_record_answer', 'local_appraisal_record_history', 'local_appraisal_record_transfer'] as $table) {
            $this->assertSame(0, $DB->count_records_select($table, 'recordid ' . $insql, $params), $table);
        }
        $this->assertCount(1, $events);
        $this->assertSame((int) $this->ellie->id, (int) $events[0]->relateduserid);
        $this->assertSame(2, $events[0]->other['records']);
        $this->assertSame(1, $events[0]->other['completed']);
        $this->assertSame($this->built->template->get('name'), $events[0]->other['templatename']);
        $this->assertTrue(template::record_exists($this->templateid()), 'The template stays.');
    }

    /**
     * A privacy deletion removes the data without writing an event about the person.
     *
     * @return void
     */
    public function test_delete_assignment_without_the_event(): void {
        $record = $this->assign();
        $sink = $this->redirectEvents();

        api::delete_assignment($record->get('assignmentid'), false);

        $this->assertCount(0, $this->events_of($sink, event\assignment_deleted::class));
        $sink->close();
        $this->assertFalse(record::record_exists($record->get('id')));
    }

    /**
     * Deleting one assignment leaves another assignment's cycle, answers, history and transfers alone.
     *
     * @return void
     */
    public function test_delete_assignment_leaves_other_cycles_alone(): void {
        global $DB;

        $mine = $this->assign();
        $other = $this->gen->create_template(['fields' => [['label' => 'Other note']]]);
        $kept = $this->gen->create_appraisal([
            'templateid' => $other->template->get('id'),
            'userid' => $this->ellie->id,
            'appraiserid' => $this->alice->id,
        ]);
        $keptid = $kept->get('id');
        api::save_answer(
            $keptid,
            $other->fields['Other note']->get('id'),
            record_answer::ROLE_APPRAISER,
            (int) $this->alice->id,
            'Keep me'
        );
        api::log_transfer($keptid, (int) $this->alice->id, (int) $this->alice->id, (int) $this->alice->id);

        api::delete_assignment($mine->get('assignmentid'));

        $this->assertTrue(record::record_exists($keptid));
        $this->assertCount(1, api::get_answers($keptid));
        $this->assertCount(2, api::get_history($keptid), 'Created and started.');
        $this->assertCount(1, api::get_transfers($keptid));
        $this->assertTrue(assignment::record_exists($kept->get('assignmentid')));
        $this->assertSame(1, $DB->count_records('local_appraisal_assignment', ['templateid' => $other->template->get('id')]));
    }

    /**
     * Deleting one person's assignment leaves other people's cycles on the same template alone.
     *
     * @return void
     */
    public function test_delete_assignment_leaves_other_people_alone(): void {
        $mine = $this->assign();
        $una = $this->getDataGenerator()->create_user(['firstname' => 'Una']);
        cohort_add_member($this->appraiseecohortid, $una->id);
        $other = api::assign_template($this->templateid(), (int) $una->id, (int) $this->alice->id);

        api::delete_assignment($mine->get('assignmentid'));

        $this->assertTrue(record::record_exists($other->get('id')));
        $this->assertTrue(assignment::record_exists($other->get('assignmentid')));
    }

    /**
     * Assigning needs an access gate to the appraisee, as the Assign template page does: an outsider, someone
     * without the appraise capability, and a gate that points the other way are all refused, and nothing is created.
     *
     * @return void
     */
    public function test_assign_template_needs_a_gate_to_the_appraisee(): void {
        global $DB;

        $ellie = (int) $this->ellie->id;
        $alice = (int) $this->alice->id;
        $uma = (int) $this->uma->id;
        $templateid = $this->templateid();

        $this->assert_refused('error:nodashboardaccess', fn(): mixed => api::assign_template($templateid, $ellie, $uma));
        // The link reaches one way only: the appraisee cannot assign to the appraiser.
        $this->assert_refused('error:nodashboardaccess', fn(): mixed => api::assign_template($templateid, $alice, $ellie));
        $this->assertSame(0, $DB->count_records('local_appraisal_assignment'));
        $this->assertSame(0, $DB->count_records('local_appraisal_record'));

        // The gate is not enough without the capability.
        $this->gen->set_user_capability($alice, 'local/appraisal:appraise', CAP_PROHIBIT);
        $this->assert_refused('error:nodashboardaccess', fn(): mixed => api::assign_template($templateid, $ellie, $alice));
        $this->assertSame(0, $DB->count_records('local_appraisal_assignment'));
    }

    /**
     * The cohort gate and the site-wide gate let their holder assign (the profile gate shares `access::can_appraise()`
     * and is covered in access_test), and a gate lost since is lost for assigning too.
     *
     * @return void
     */
    public function test_assign_template_through_each_gate(): void {
        $ellie = (int) $this->ellie->id;
        $templateid = $this->templateid();

        // The cohort link.
        $record = api::assign_template($templateid, $ellie, (int) $this->alice->id);
        $this->assertSame((int) $this->alice->id, $record->get('appraiserid'));
        api::delete_assignment($record->get('assignmentid'));

        // The site-wide gate.
        $uma = (int) $this->uma->id;
        $this->gen->set_user_capability($uma, 'local/appraisal:accessalldashboards');
        $record = api::assign_template($templateid, $ellie, $uma);
        $this->assertSame($uma, $record->get('appraiserid'));
        api::delete_assignment($record->get('assignmentid'));

        // Leaving the appraiser cohort ends the cohort gate.
        cohort_remove_member($this->appraisercohortid, $this->alice->id);
        $this->assert_refused(
            'error:nodashboardaccess',
            fn(): mixed => api::assign_template($templateid, $ellie, (int) $this->alice->id)
        );
    }
}
