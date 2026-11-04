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

use local_appraisal\task\send_due_reminders;
use local_appraisal_generator;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the due date reminders: when the window opens, who is reminded in which status, that each reminder goes
 * out once, and how a changed due date, an inactive recipient and a failing record are handled.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(api::class)]
#[CoversClass(send_due_reminders::class)]
final class reminders_test extends \advanced_testcase {
    /** @var local_appraisal_generator The plugin's data generator. */
    private local_appraisal_generator $gen;

    /** @var \stdClass The appraisee. */
    private \stdClass $ellie;

    /** @var \stdClass The appraiser who owns the records. */
    private \stdClass $alice;

    /** @var int The appraisee cohort, which Alice's cohort is linked to. */
    private int $appraiseecohortid;

    /** @var \stdClass The generated template: an appraiser field and an appraisee field, with a 7 day reminder. */
    private \stdClass $built;

    /** @var \phpunit_message_sink Collects every message the plugin sends. */
    private \phpunit_message_sink $sink;

    /** @var \core\clock Frozen at the start of the test. */
    private \core\clock $clock;

    /**
     * Build the people, a cohort link and a published template with a 7 day reminder.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->sink = $this->redirectMessages();
        $this->clock = $this->mock_clock_with_frozen(strtotime('2030-03-01 12:00:00 UTC'));
        $this->gen = $this->getDataGenerator()->get_plugin_generator('local_appraisal');

        $this->ellie = $this->getDataGenerator()->create_user(['firstname' => 'Ellie', 'lastname' => 'Appraisee']);
        $this->alice = $this->getDataGenerator()->create_user(['firstname' => 'Alice', 'lastname' => 'Owner']);
        $appraisers = $this->getDataGenerator()->create_cohort();
        $appraisees = $this->getDataGenerator()->create_cohort();
        $this->appraiseecohortid = (int) $appraisees->id;
        cohort_add_member($appraisers->id, $this->alice->id);
        cohort_add_member($appraisees->id, $this->ellie->id);
        $this->gen->create_cohort_link([
            'appraisercohortid' => $appraisers->id,
            'appraiseecohortid' => $appraisees->id,
        ]);

        $this->built = $this->gen->create_template([
            'name' => 'Annual review',
            'reminderdays' => 7,
            'fields' => [
                ['label' => 'Manager note', 'completedby' => template_field::COMPLETEDBY_APPRAISER],
                ['label' => 'Self review', 'completedby' => template_field::COMPLETEDBY_APPRAISEE],
            ],
        ]);
    }

    /**
     * A due date 14 days ahead: 23:59:59 on that day in the site timezone.
     *
     * @return int
     */
    private function due(): int {
        return api::due_date_from_day($this->clock->time() + 14 * DAYSECS);
    }

    /**
     * The first moment the 7 day reminder for a due date can go out: the start of the calendar day 7 days before.
     *
     * @param int $due A due date from api::due_date_from_day().
     * @return int
     */
    private function opens(int $due): int {
        return $due - 8 * DAYSECS + 1;
    }

    /**
     * Assign a template to a user, owned by Alice, and forget the `started` message.
     *
     * @param int|null $duedate
     * @param \stdClass|null $template A generated template, or the standard one.
     * @param \stdClass|null $user The appraisee, or Ellie.
     * @return record
     */
    private function assign(?int $duedate, ?\stdClass $template = null, ?\stdClass $user = null): record {
        $record = $this->gen->create_appraisal([
            'templateid' => ($template ?? $this->built)->template->get('id'),
            'userid' => ($user ?? $this->ellie)->id,
            'appraiserid' => $this->alice->id,
            'duedate' => $duedate,
        ]);
        $this->sink->clear();
        return $record;
    }

    /**
     * A new user in the appraisee cohort, whom Alice can appraise.
     *
     * @return \stdClass
     */
    private function new_appraisee(): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        cohort_add_member($this->appraiseecohortid, $user->id);
        return $user;
    }

    /**
     * The messages collected so far, optionally only those to one user.
     *
     * @param \stdClass|null $user Keep only this user's messages.
     * @return \stdClass[]
     */
    private function messages(?\stdClass $user = null): array {
        $messages = $this->sink->get_messages();
        if ($user) {
            $messages = array_filter($messages, fn(\stdClass $m): bool => (int) $m->useridto === (int) $user->id);
        }
        return array_values($messages);
    }

    /**
     * Save an answer for each side, and optionally submit Ellie's part.
     *
     * @param record $record
     * @param bool $submit Whether Ellie submits.
     * @return void
     */
    private function answer(record $record, bool $submit = false): void {
        foreach (api::get_fields($record->get('template_versionid')) as $field) {
            $appraiser = $field->is_completed_by_appraiser();
            api::save_answer(
                $record->get('id'),
                $field->get('id'),
                $appraiser ? record_answer::ROLE_APPRAISER : record_answer::ROLE_APPRAISEE,
                (int) ($appraiser ? $this->alice->id : $this->ellie->id),
                'Text'
            );
        }
        if ($submit) {
            api::submit_part($record->get('id'), (int) $this->ellie->id);
        }
        $this->sink->clear();
    }

    /**
     * The reminder window is calendar days: with 7 days set, a cycle due on a day is reminded from the first second of
     * the day 7 days before, not a second sooner.
     *
     * @return void
     */
    public function test_the_window_opens_at_the_start_of_the_calendar_day(): void {
        $due = $this->due();
        $record = $this->assign($due);
        $opens = $this->opens($due);

        $this->assertSame(0, api::send_due_reminders($opens - 1));
        $this->assertSame([], $this->messages());
        $this->assertNull(api::get_record($record->get('id'))->get('timereminded'));

        $this->assertSame(1, api::send_due_reminders($opens));
        $this->assertCount(2, $this->messages());
        $this->assertSame($opens, api::get_record($record->get('id'))->get('timereminded'));
        $this->assertSame(
            usergetmidnight($due, api::site_timezone()) - 7 * DAYSECS,
            $opens,
            'The window opens at midnight, 7 days before the due day.'
        );
    }

    /**
     * The due date itself is still inside the window: a cycle due at this very second is reminded.
     *
     * @return void
     */
    public function test_a_cycle_due_this_second_is_still_reminded(): void {
        $due = $this->due();
        $this->assign($due);

        $this->assertSame(1, api::send_due_reminders($due));
        $this->assertCount(2, $this->messages());
    }

    /**
     * Each reminder is sent once: a second run, and a later run, send nothing more, up to the due date itself.
     *
     * @return void
     */
    public function test_each_reminder_is_sent_once(): void {
        $due = $this->due();
        $record = $this->assign($due);
        $opens = $this->opens($due);

        $this->assertSame(1, api::send_due_reminders($opens));
        $this->sink->clear();

        $this->assertSame(0, api::send_due_reminders($opens));
        $this->assertSame(0, api::send_due_reminders($opens + DAYSECS));
        $this->assertSame(0, api::send_due_reminders($due));
        $this->assertSame([], $this->messages());
        $this->assertSame($opens, api::get_record($record->get('id'))->get('timereminded'), 'The first stamp stays.');
    }

    /**
     * While the appraisee's part is open (Not started, In progress, or handed back) both people are reminded; once they
     * have submitted, only the appraiser is.
     *
     * @return void
     */
    public function test_recipients_follow_the_status(): void {
        $due = $this->due();
        $opens = $this->opens($due);
        $notstarted = $this->assign($due);
        $inprogress = $this->assign($due, null, $this->new_appraisee());
        $submitted = $this->assign($due, null, $this->new_appraisee());
        $handedback = $this->assign($due, null, $this->new_appraisee());
        $appraisees = [];
        foreach ([$inprogress, $submitted, $handedback] as $record) {
            $assignment = api::get_assignment($record->get('assignmentid'));
            $appraisees[$record->get('id')] = (int) $assignment->get('userid');
            foreach (api::get_fields($record->get('template_versionid')) as $field) {
                $appraiser = $field->is_completed_by_appraiser();
                api::save_answer(
                    $record->get('id'),
                    $field->get('id'),
                    $appraiser ? record_answer::ROLE_APPRAISER : record_answer::ROLE_APPRAISEE,
                    $appraiser ? (int) $this->alice->id : $appraisees[$record->get('id')],
                    'Text'
                );
            }
        }
        api::submit_part($submitted->get('id'), $appraisees[$submitted->get('id')]);
        api::submit_part($handedback->get('id'), $appraisees[$handedback->get('id')]);
        api::hand_back($handedback->get('id'), (int) $this->alice->id);
        $this->assertSame(record::STATUS_NOT_STARTED, api::get_record($notstarted->get('id'))->get('status'));
        $this->assertSame(record::STATUS_IN_PROGRESS, api::get_record($inprogress->get('id'))->get('status'));
        $this->assertSame(record::STATUS_SUBMITTED, api::get_record($submitted->get('id'))->get('status'));
        $this->assertSame(record::STATUS_IN_PROGRESS, api::get_record($handedback->get('id'))->get('status'));
        $this->sink->clear();

        $this->assertSame(4, api::send_due_reminders($opens));

        $toalice = $this->messages($this->alice);
        $this->assertCount(4, $toalice, 'The owner is reminded of every record.');
        $ellie = $this->messages($this->ellie);
        $this->assertCount(1, $ellie);
        $this->assertSame(1, count($this->messages(\core_user::get_user($appraisees[$inprogress->get('id')]))));
        $this->assertSame([], $this->messages(\core_user::get_user($appraisees[$submitted->get('id')])), 'Submitted.');
        $this->assertSame(1, count($this->messages(\core_user::get_user($appraisees[$handedback->get('id')]))));
        $this->assertCount(7, $this->messages());
    }

    /**
     * A version with nothing for the appraisee to complete reminds the appraiser only.
     *
     * @return void
     */
    public function test_an_appraiser_only_version_reminds_the_appraiser(): void {
        $appraiseronly = $this->gen->create_template([
            'reminderdays' => 7,
            'fields' => [['label' => 'Note']],
        ]);
        $due = $this->due();
        $this->assign($due, $appraiseronly);

        $this->assertSame(1, api::send_due_reminders($this->opens($due)));

        $this->assertCount(1, $this->messages());
        $this->assertCount(1, $this->messages($this->alice));
        $this->assertSame([], $this->messages($this->ellie));
    }

    /**
     * Each reader gets wording written to them: the appraiser's names the appraisee and says it has not been signed off;
     * the appraisee's speaks of their own appraisal and names the appraiser.
     *
     * @return void
     */
    public function test_each_reminder_is_written_to_its_reader(): void {
        $due = $this->due();
        $record = $this->assign($due);

        api::send_due_reminders($this->opens($due));

        $day = userdate($due, get_string('strftimedate'), api::site_timezone());
        $url = (new \moodle_url('/local/appraisal/record.php', ['id' => $record->get('id')]))->out(false);
        $toalice = $this->messages($this->alice)[0];
        $this->assertSame('duereminder', $toalice->eventtype);
        $this->assertSame('Appraisal: Annual review for ' . fullname($this->ellie) . " is due on {$day}", $toalice->subject);
        $this->assertStringContainsString(
            'The Annual review appraisal for ' . fullname($this->ellie) . " is due on {$day} and has not been signed off yet.",
            $toalice->fullmessage
        );
        $this->assertStringContainsString($url, $toalice->fullmessage);

        $toellie = $this->messages($this->ellie)[0];
        $this->assertSame('duereminder', $toellie->eventtype);
        $this->assertSame("Appraisal: your Annual review appraisal is due on {$day}", $toellie->subject);
        $this->assertStringContainsString(
            "Your Annual review appraisal is due on {$day} and your part has not been submitted yet. "
                . fullname($this->alice) . ' is your appraiser.',
            $toellie->fullmessage
        );
        $this->assertStringNotContainsString(fullname($this->ellie), $toellie->fullmessage);
        $this->assertStringContainsString($url, $toellie->fullmessage);
    }

    /**
     * Nothing is sent for a completed record, a due date that has passed, no due date, a version with no reminder set,
     * or a due date still outside the window.
     *
     * @return void
     */
    public function test_nothing_is_sent_when_no_reminder_is_due(): void {
        $due = $this->due();
        $opens = $this->opens($due);

        // Completed.
        $completed = $this->assign($due);
        $this->answer($completed, true);
        api::sign_off($completed->get('id'), (int) $this->alice->id);

        // Past due: the due date went by before the window was reached.
        $overdue = $this->assign(api::due_date_from_day($this->clock->time() - 3 * DAYSECS), null, $this->new_appraisee());

        // No due date, and no reminder on the version.
        $nodue = $this->assign(null, null, $this->new_appraisee());
        $noreminder = $this->gen->create_template(['fields' => [['label' => 'Note']]]);
        $this->assign($due, $noreminder, $this->new_appraisee());

        // Due further ahead than the window.
        $farahead = $this->assign(api::due_date_from_day($this->clock->time() + 40 * DAYSECS), null, $this->new_appraisee());

        $this->assertSame(0, api::send_due_reminders($opens));
        $this->assertSame([], $this->messages());
        foreach ([$completed, $overdue, $nodue, $farahead] as $record) {
            $this->assertNull(api::get_record($record->get('id'))->get('timereminded'), (string) $record->get('id'));
        }

        // Run after the due date of the others too: still nothing (there is no repeating reminder).
        $this->assertSame(0, api::send_due_reminders($due + 1));
        $this->assertSame([], $this->messages());
    }

    /**
     * A due date changed to a different value is reminded again once; saving the same date, or a new label, is not.
     *
     * @return void
     */
    public function test_a_changed_due_date_is_reminded_again(): void {
        $due = $this->due();
        $record = $this->assign($due);
        $recordid = $record->get('id');
        $opens = $this->opens($due);
        $this->assertSame(1, api::send_due_reminders($opens));
        $this->sink->clear();

        api::update_record_details($recordid, (int) $this->alice->id, 'New label', $due);
        $this->assertNotNull(api::get_record($recordid)->get('timereminded'), 'Same date: the stamp stays.');
        $this->assertSame(0, api::send_due_reminders($opens + 1));

        $newdue = $due + 2 * DAYSECS;
        api::update_record_details($recordid, (int) $this->alice->id, 'New label', $newdue);
        $this->assertNull(api::get_record($recordid)->get('timereminded'));
        $this->assertSame(0, api::send_due_reminders($opens + 1), 'The window moved with the due date.');
        $this->assertSame(1, api::send_due_reminders($this->opens($newdue)));
        $this->assertCount(2, $this->messages());
        $this->assertSame(0, api::send_due_reminders($this->opens($newdue) + DAYSECS));
    }

    /**
     * A suspended recipient gets nothing, the other person still does, and the record is stamped so it is not tried
     * again every day.
     *
     * @return void
     */
    public function test_a_suspended_recipient_is_skipped_and_the_record_stamped(): void {
        global $DB;

        $due = $this->due();
        $opens = $this->opens($due);
        $first = $this->assign($due);
        $second = $this->assign($due, null, $this->new_appraisee());
        $DB->set_field('user', 'suspended', 1, ['id' => $this->ellie->id]);

        $this->assertSame(2, api::send_due_reminders($opens));

        $this->assertSame([], $this->messages($this->ellie));
        $this->assertCount(2, $this->messages($this->alice), 'The appraiser is reminded of both.');
        $this->assertCount(1, $this->messages(\core_user::get_user(
            api::get_assignment($second->get('assignmentid'))->get('userid')
        )));
        $this->assertSame($opens, api::get_record($first->get('id'))->get('timereminded'));

        $this->sink->clear();
        $DB->set_field('user', 'suspended', 0, ['id' => $this->ellie->id]);
        $this->assertSame(0, api::send_due_reminders($opens + DAYSECS), 'Not retried once stamped.');
        $this->assertSame([], $this->messages());
    }

    /**
     * A record that cannot be reminded does not stop the others: the failure is traced and the run goes on.
     *
     * @return void
     */
    public function test_one_failing_record_does_not_stop_the_rest(): void {
        global $DB;

        $due = $this->due();
        $broken = $this->gen->create_template(['reminderdays' => 7, 'fields' => [['label' => 'Note']]]);
        $this->assign($due, $broken, $this->new_appraisee());
        $good = $this->assign($due);
        // The template row is gone, as after a partial delete: the record is still found by the query, but not loaded.
        $DB->delete_records('local_appraisal_template', ['id' => $broken->template->get('id')]);
        $this->expectOutputRegex('/local_appraisal: reminder for record \d+ failed/');

        $this->assertSame(1, api::send_due_reminders($this->opens($due)));

        $this->assertCount(1, $this->messages($this->alice));
        $this->assertNotNull(api::get_record($good->get('id'))->get('timereminded'));
    }

    /**
     * The scheduled task is registered daily at 02:15, has a name, and sends the reminders due at the clock's time.
     *
     * @return void
     */
    public function test_the_scheduled_task(): void {
        $task = \core\task\manager::get_scheduled_task(send_due_reminders::class);
        $this->assertInstanceOf(send_due_reminders::class, $task);
        $this->assertSame('15', $task->get_minute());
        $this->assertSame('2', $task->get_hour());
        $this->assertSame('*', $task->get_day());
        $this->assertSame('*', $task->get_month());
        $this->assertSame('*', $task->get_day_of_week());
        $this->assertSame(get_string('task:senddue', 'local_appraisal'), $task->get_name());
        $this->assertNotSame('', $task->get_name());

        $due = $this->due();
        $record = $this->assign($due);
        $this->clock->set_to($this->opens($due));
        $this->expectOutputString("local_appraisal: 1 due date reminder(s) sent.\n");

        $task->execute();

        $this->assertCount(2, $this->messages());
        $this->assertSame($this->clock->time(), api::get_record($record->get('id'))->get('timereminded'));
    }
}
