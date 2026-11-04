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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the plugin's notifications: who is told at each lifecycle step, in wording written to them, with the
 * record link, and without anything they are not yet allowed to read.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(messaging::class)]
final class messaging_test extends \advanced_testcase {
    /** @var local_appraisal_generator The plugin's data generator. */
    private local_appraisal_generator $gen;

    /** @var \stdClass The appraisee. */
    private \stdClass $ellie;

    /** @var \stdClass The appraiser who owns the records. */
    private \stdClass $alice;

    /** @var \stdClass A second appraiser, who can take ownership. */
    private \stdClass $ben;

    /** @var \stdClass The generated template: an appraiser field and an appraisee field. */
    private \stdClass $built;

    /** @var \phpunit_message_sink Collects every message the plugin sends. */
    private \phpunit_message_sink $sink;

    /**
     * Build the people, a cohort link and a published template with a field for each side.
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
        $this->gen = $this->getDataGenerator()->get_plugin_generator('local_appraisal');

        $this->ellie = $this->getDataGenerator()->create_user(['firstname' => 'Ellie', 'lastname' => 'Appraisee']);
        $this->alice = $this->getDataGenerator()->create_user(['firstname' => 'Alice', 'lastname' => 'Owner']);
        $this->ben = $this->getDataGenerator()->create_user(['firstname' => 'Ben', 'lastname' => 'Second']);
        $appraisers = $this->getDataGenerator()->create_cohort();
        $appraisees = $this->getDataGenerator()->create_cohort();
        cohort_add_member($appraisers->id, $this->alice->id);
        cohort_add_member($appraisers->id, $this->ben->id);
        cohort_add_member($appraisees->id, $this->ellie->id);
        $this->gen->create_cohort_link([
            'appraisercohortid' => $appraisers->id,
            'appraiseecohortid' => $appraisees->id,
        ]);

        $this->built = $this->gen->create_template([
            'name' => 'Annual review',
            'fields' => [
                ['label' => 'Manager note', 'completedby' => template_field::COMPLETEDBY_APPRAISER],
                ['label' => 'Self review', 'completedby' => template_field::COMPLETEDBY_APPRAISEE],
            ],
        ]);
    }

    /**
     * The messages collected so far, optionally only those to one user.
     *
     * @param \stdClass|null $user Keep only this user's messages.
     * @return \stdClass[] In the order they were sent.
     */
    private function messages(?\stdClass $user = null): array {
        $messages = $this->sink->get_messages();
        if ($user) {
            $messages = array_filter($messages, fn(\stdClass $m): bool => (int) $m->useridto === (int) $user->id);
        }
        return array_values($messages);
    }

    /**
     * Assign the generated template to Ellie, owned by Alice, and forget the `started` message.
     *
     * @param int|null $duedate
     * @return record
     */
    private function assign(?int $duedate = null): record {
        $record = $this->gen->create_appraisal([
            'templateid' => $this->built->template->get('id'),
            'userid' => $this->ellie->id,
            'appraiserid' => $this->alice->id,
            'duedate' => $duedate,
        ]);
        $this->sink->clear();
        return $record;
    }

    /**
     * Save an answer for each side of the generated template, then forget the messages so far.
     *
     * @param record $record
     * @param string $appraiseetext What Ellie writes.
     * @return void
     */
    private function answer_both(record $record, string $appraiseetext = 'Self text'): void {
        $recordid = $record->get('id');
        foreach (api::get_fields($record->get('template_versionid')) as $field) {
            $appraiser = $field->is_completed_by_appraiser();
            api::save_answer(
                $recordid,
                $field->get('id'),
                $appraiser ? record_answer::ROLE_APPRAISER : record_answer::ROLE_APPRAISEE,
                (int) ($appraiser ? $this->alice->id : $this->ellie->id),
                $appraiser ? 'Manager text' : $appraiseetext
            );
        }
        $this->sink->clear();
    }

    /**
     * The record page URL a message should carry.
     *
     * @param record $record
     * @return string
     */
    private function url(record $record): string {
        return (new \moodle_url('/local/appraisal/record.php', ['id' => $record->get('id')]))->out(false);
    }

    /**
     * Assigning tells the appraisee, and only the appraisee, in wording written to them: the appraiser's name, the
     * template, a link to the record, and no due date when there is none.
     *
     * @return void
     */
    public function test_started_goes_to_the_appraisee_only(): void {
        $record = $this->gen->create_appraisal([
            'templateid' => $this->built->template->get('id'),
            'userid' => $this->ellie->id,
            'appraiserid' => $this->alice->id,
        ]);

        $messages = $this->messages();
        $this->assertCount(1, $messages);
        $message = $messages[0];
        $this->assertSame((int) $this->ellie->id, (int) $message->useridto);
        $this->assertSame('local_appraisal', $message->component);
        $this->assertSame('started', $message->eventtype);
        $this->assertSame('Appraisal: your Annual review appraisal has started', $message->subject);
        $this->assertStringContainsString(
            fullname($this->alice) . ' has started your Annual review appraisal. Please complete your part.',
            $message->fullmessage
        );
        $this->assertStringNotContainsString(' by ', $message->fullmessage);
        $this->assertStringContainsString($this->url($record), $message->fullmessage);
        $this->assertStringContainsString($this->url($record), $message->contexturl);
        $this->assertStringNotContainsString(fullname($this->ellie), $message->fullmessage, 'Written to Ellie, not about her.');
    }

    /**
     * A cycle with a due date says so, in the site timezone, so the day is the one the appraiser chose.
     *
     * @return void
     */
    public function test_started_names_the_due_date(): void {
        global $DB;

        // Everyone involved is in a timezone five hours ahead of the site's, where the due date falls on the next day.
        $DB->set_field('user', 'timezone', 'Pacific/Auckland', ['id' => $this->ellie->id]);
        $DB->set_field('user', 'timezone', 'Pacific/Auckland', ['id' => $this->alice->id]);
        $this->setUser(\core_user::get_user($this->alice->id));
        $clock = $this->mock_clock_with_frozen();
        $due = api::due_date_from_day($clock->time() + 14 * DAYSECS);

        $this->gen->create_appraisal([
            'templateid' => $this->built->template->get('id'),
            'userid' => $this->ellie->id,
            'appraiserid' => $this->alice->id,
            'duedate' => $due,
        ]);

        $messages = $this->messages();
        $this->assertCount(1, $messages);
        $day = userdate($due, get_string('strftimedate'), api::site_timezone());
        $this->assertStringContainsString("Please complete your part by {$day}.", $messages[0]->fullmessage);
        $theirday = userdate($due, get_string('strftimedate'), 'Pacific/Auckland');
        $this->assertNotSame($day, $theirday, 'The two timezones disagree about the day, so the test can tell them apart.');
        $this->assertStringNotContainsString($theirday, $messages[0]->fullmessage);
        $this->assertSame('Appraisal: your Annual review appraisal has started', $messages[0]->subject);
    }

    /**
     * A version with nothing for the appraisee to complete sends no `started` message, from either way of making a
     * cycle.
     *
     * @return void
     */
    public function test_started_is_not_sent_when_the_appraisee_has_nothing_to_do(): void {
        $appraiseronly = $this->gen->create_template(['fields' => [['label' => 'Note']]]);
        $record = api::assign_template(
            $appraiseronly->template->get('id'),
            (int) $this->ellie->id,
            (int) $this->alice->id
        );
        $this->assertSame([], $this->messages());

        // Complete it so a second cycle can start.
        $field = reset($appraiseronly->fields);
        api::save_answer($record->get('id'), $field->get('id'), record_answer::ROLE_APPRAISER, (int) $this->alice->id, 'Done');
        api::sign_off($record->get('id'), (int) $this->alice->id);
        $this->sink->clear();
        api::start_cycle($record->get('assignmentid'), (int) $this->alice->id);
        $this->assertSame([], $this->messages());
    }

    /**
     * Starting a later cycle tells the appraisee again.
     *
     * @return void
     */
    public function test_a_new_cycle_is_announced(): void {
        $record = $this->assign();
        $this->answer_both($record);
        api::submit_part($record->get('id'), (int) $this->ellie->id);
        api::sign_off($record->get('id'), (int) $this->alice->id);
        $this->sink->clear();

        $next = api::start_cycle($record->get('assignmentid'), (int) $this->alice->id);

        $messages = $this->messages();
        $this->assertCount(1, $messages);
        $this->assertSame((int) $this->ellie->id, (int) $messages[0]->useridto);
        $this->assertSame('started', $messages[0]->eventtype);
        $this->assertStringContainsString($this->url($next), $messages[0]->fullmessage);
    }

    /**
     * Submitting tells the owner, not the appraisee, names the appraisee, and carries none of their answers.
     *
     * @return void
     */
    public function test_submitted_goes_to_the_owner_without_the_answers(): void {
        $record = $this->assign();
        $this->answer_both($record, 'PRIVATE-SELF-ASSESSMENT');

        api::submit_part($record->get('id'), (int) $this->ellie->id);

        $messages = $this->messages();
        $this->assertCount(1, $messages);
        $message = $messages[0];
        $this->assertSame((int) $this->alice->id, (int) $message->useridto);
        $this->assertSame('submitted', $message->eventtype);
        $this->assertSame(
            'Appraisal: ' . fullname($this->ellie) . ' has submitted their Annual review appraisal',
            $message->subject
        );
        $this->assertStringContainsString(fullname($this->ellie) . ' has submitted their part', $message->fullmessage);
        $this->assertStringContainsString($this->url($record), $message->fullmessage);
        foreach ([$message->fullmessage, $message->fullmessagehtml, $message->smallmessage, $message->subject] as $text) {
            $this->assertStringNotContainsString('PRIVATE-SELF-ASSESSMENT', $text);
            $this->assertStringNotContainsString('Manager text', $text);
        }
    }

    /**
     * Handing back tells the appraisee and quotes the appraiser's note when there is one; without a note it uses the
     * plain wording.
     *
     * @return void
     */
    public function test_handed_back_with_and_without_a_note(): void {
        $record = $this->assign();
        $this->answer_both($record);
        api::submit_part($record->get('id'), (int) $this->ellie->id);
        $this->sink->clear();

        api::hand_back($record->get('id'), (int) $this->alice->id, '  Please add detail to your goals.  ');

        $messages = $this->messages();
        $this->assertCount(1, $messages);
        $this->assertSame((int) $this->ellie->id, (int) $messages[0]->useridto);
        $this->assertSame('handedback', $messages[0]->eventtype);
        $this->assertSame('Appraisal: your Annual review appraisal has been handed back', $messages[0]->subject);
        $this->assertStringContainsString(
            fullname($this->alice) . ' has handed your Annual review appraisal back',
            $messages[0]->fullmessage
        );
        $this->assertStringContainsString("Their note:\n\nPlease add detail to your goals.\n\n", $messages[0]->fullmessage);
        $this->assertStringContainsString($this->url($record), $messages[0]->fullmessage);

        api::submit_part($record->get('id'), (int) $this->ellie->id);
        $this->sink->clear();
        api::hand_back($record->get('id'), (int) $this->alice->id);

        $messages = $this->messages();
        $this->assertCount(1, $messages);
        $this->assertSame('handedback', $messages[0]->eventtype);
        $this->assertStringNotContainsString('Their note', $messages[0]->fullmessage);
        $this->assertStringContainsString('Update your answers and submit your part again.', $messages[0]->fullmessage);
    }

    /**
     * A refused step sends nothing: a hand back by someone who does not own the record tells nobody.
     *
     * @return void
     */
    public function test_a_refused_step_sends_nothing(): void {
        $record = $this->assign();
        $this->answer_both($record);
        api::submit_part($record->get('id'), (int) $this->ellie->id);
        $this->sink->clear();

        try {
            api::hand_back($record->get('id'), (int) $this->ben->id, 'Not mine');
            $this->fail('Expected a refusal.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:cannothandback', $e->errorcode);
        }
        $this->assertSame([], $this->messages());
    }

    /**
     * Signing off tells the appraisee who did it and says the appraisal can be read; it does not give the grade.
     *
     * @return void
     */
    public function test_completed_goes_to_the_appraisee_without_the_grade(): void {
        $scale = $this->gen->create_scale(['levels' => ['Needs work' => 1, 'Outstanding' => 2]]);
        $graded = $this->gen->create_template([
            'name' => 'Graded review',
            'gradingmode' => template_version::GRADE_REQUIRED,
            'overallscaleid' => $scale->get('id'),
            'fields' => [['label' => 'Note', 'completedby' => template_field::COMPLETEDBY_APPRAISER]],
        ]);
        $record = api::assign_template($graded->template->get('id'), (int) $this->ellie->id, (int) $this->alice->id);
        api::save_answer(
            $record->get('id'),
            reset($graded->fields)->get('id'),
            record_answer::ROLE_APPRAISER,
            (int) $this->alice->id,
            'Done'
        );
        $levels = array_values(api::get_scale_levels($scale->get('id')));
        $this->sink->clear();

        api::sign_off($record->get('id'), (int) $this->alice->id, $levels[1]->get('id'));

        $messages = $this->messages();
        $this->assertCount(1, $messages);
        $message = $messages[0];
        $this->assertSame((int) $this->ellie->id, (int) $message->useridto);
        $this->assertSame('completed', $message->eventtype);
        $this->assertSame('Appraisal: your Graded review appraisal is complete', $message->subject);
        $this->assertStringContainsString(
            'Your Graded review appraisal was signed off by ' . fullname($this->alice),
            $message->fullmessage
        );
        $this->assertStringContainsString($this->url($record), $message->fullmessage);
        $this->assertStringNotContainsString('Outstanding', $message->fullmessage);
        $this->assertStringNotContainsString('Needs work', $message->fullmessage);
    }

    /**
     * Taking ownership tells the outgoing owner, in wording that names who took over, and nobody else.
     *
     * @return void
     */
    public function test_reassigned_goes_to_the_outgoing_owner_only(): void {
        $record = $this->assign();

        api::take_ownership($record->get('id'), (int) $this->ben->id);

        $messages = $this->messages();
        $this->assertCount(1, $messages);
        $message = $messages[0];
        $this->assertSame((int) $this->alice->id, (int) $message->useridto);
        $this->assertSame('reassigned', $message->eventtype);
        $this->assertSame(
            'Appraisal: Annual review for ' . fullname($this->ellie) . ' taken over by ' . fullname($this->ben),
            $message->subject
        );
        $this->assertStringContainsString(
            fullname($this->ben) . ' has taken over the Annual review appraisal for ' . fullname($this->ellie),
            $message->fullmessage
        );
        $this->assertStringContainsString('You no longer own it.', $message->fullmessage);
        $this->assertSame([], $this->messages($this->ben));
        $this->assertSame([], $this->messages($this->ellie));
    }

    /**
     * The HTML copy turns the record URL into a link, and escapes anything else in the text.
     *
     * @return void
     */
    public function test_the_html_copy_links_the_record_and_escapes_the_rest(): void {
        $record = $this->gen->create_appraisal([
            'templateid' => $this->built->template->get('id'),
            'userid' => $this->ellie->id,
            'appraiserid' => $this->alice->id,
        ]);
        $url = $this->url($record);
        $link = '<a href="' . s($url) . '">' . s($url) . '</a>';

        $message = $this->messages()[0];
        $this->assertStringContainsString($link, $message->fullmessagehtml);
        $this->assertStringNotContainsString($url . "
", $message->fullmessagehtml, 'The link ends the message.');

        $html = messaging::body_to_html("A <b>bold</b> & \"quoted\" claim

{$url}", $url);
        $this->assertStringContainsString('A &lt;b&gt;bold&lt;/b&gt; &amp; &quot;quoted&quot; claim', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString($link, $html);
        $this->assertStringContainsString('<br />', $html, 'Line breaks are kept.');
    }

    /**
     * Nothing is sent to a suspended, deleted or missing recipient.
     *
     * @return void
     */
    public function test_nobody_inactive_is_messaged(): void {
        $record = $this->assign();
        $suspended = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $deleted = $this->getDataGenerator()->create_user();
        delete_user($deleted);

        messaging::send('started', (int) $suspended->id, $record);
        messaging::send('started', (int) $deleted->id, $record);
        messaging::send('started', 0, $record);
        messaging::send('started', 99999999, $record);

        $this->assertSame([], $this->messages());
    }

    /**
     * A suspended appraisee is skipped by a real step, and the step itself still works.
     *
     * @return void
     */
    public function test_a_suspended_appraisee_is_skipped_but_the_step_works(): void {
        global $DB;

        $DB->set_field('user', 'suspended', 1, ['id' => $this->ellie->id]);

        $record = $this->gen->create_appraisal([
            'templateid' => $this->built->template->get('id'),
            'userid' => $this->ellie->id,
            'appraiserid' => $this->alice->id,
        ]);

        $this->assertTrue(record::record_exists($record->get('id')));
        $this->assertSame([], $this->messages());
    }

    /**
     * Every message is written to the person who reads it: what Ellie receives never names her, and what Alice
     * receives names her appraisee.
     *
     * @return void
     */
    public function test_wording_is_per_audience(): void {
        $record = $this->assign();
        $this->answer_both($record);
        api::submit_part($record->get('id'), (int) $this->ellie->id);
        api::hand_back($record->get('id'), (int) $this->alice->id, 'Note');
        api::submit_part($record->get('id'), (int) $this->ellie->id);
        api::sign_off($record->get('id'), (int) $this->alice->id);

        $ellie = $this->messages($this->ellie);
        $alice = $this->messages($this->alice);
        $this->assertSame(['handedback', 'completed'], array_map(fn(\stdClass $m): string => $m->eventtype, $ellie));
        $this->assertSame(['submitted', 'submitted'], array_map(fn(\stdClass $m): string => $m->eventtype, $alice));
        foreach ($ellie as $message) {
            $this->assertStringContainsString('your', strtolower($message->subject));
            $this->assertStringNotContainsString(fullname($this->ellie), $message->fullmessage);
            $this->assertStringNotContainsString(fullname($this->ellie), $message->subject);
        }
        foreach ($alice as $message) {
            $this->assertStringContainsString(fullname($this->ellie), $message->subject);
            $this->assertStringContainsString(fullname($this->ellie), $message->fullmessage);
        }
    }

    /**
     * The six providers are registered with popup and email on by default, each with a name for the preferences page,
     * and every wording the code selects has both its subject and its body.
     *
     * @return void
     */
    public function test_providers_and_strings_exist(): void {
        global $CFG;

        $messageproviders = [];
        require($CFG->dirroot . '/local/appraisal/db/messages.php');
        $this->assertSame(
            ['started', 'submitted', 'handedback', 'completed', 'duereminder', 'reassigned'],
            array_keys($messageproviders)
        );
        $expected = MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED;
        $strings = get_string_manager();
        foreach ($messageproviders as $name => $provider) {
            $this->assertSame($expected, $provider['defaults']['popup'], $name);
            $this->assertSame($expected, $provider['defaults']['email'], $name);
            $this->assertTrue($strings->string_exists("messageprovider:{$name}", 'local_appraisal'), $name);
        }
        $wordings = [
            'started', 'starteddue', 'submitted', 'handedback', 'handedbacknote', 'completed', 'duereminder',
            'duereminderappraisee', 'reassigned',
        ];
        foreach ($wordings as $wording) {
            $this->assertTrue($strings->string_exists("message:{$wording}_subject", 'local_appraisal'), $wording);
            $this->assertTrue($strings->string_exists("message:{$wording}_body", 'local_appraisal'), $wording);
        }
    }
}
