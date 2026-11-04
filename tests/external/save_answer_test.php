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

namespace local_appraisal\external;

use core_external\external_api;
use local_appraisal\record;
use local_appraisal\record_answer;
use local_appraisal\template_field;
use local_appraisal_generator;
use moodle_exception;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the autosave answer web service: its role and capability checks and what it returns.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(save_answer::class)]
final class save_answer_test extends \advanced_testcase {
    /** @var local_appraisal_generator The plugin's data generator. */
    private local_appraisal_generator $gen;

    /** @var \stdClass The appraisee. */
    private \stdClass $ellie;

    /** @var \stdClass The record's owner. */
    private \stdClass $alice;

    /** @var \stdClass A second appraiser who does not own the record. */
    private \stdClass $ben;

    /** @var \stdClass A user with no route to the appraisee. */
    private \stdClass $uma;

    /** @var \stdClass The generated template: template, version and fields keyed by label. */
    private \stdClass $built;

    /** @var record The record under test. */
    private record $record;

    /**
     * Build one appraisal for Ellie, owned by Alice, with Ben as a second appraiser and Uma as an outsider.
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

        $this->ellie = $this->getDataGenerator()->create_user();
        $this->alice = $this->getDataGenerator()->create_user();
        $this->ben = $this->getDataGenerator()->create_user();
        $this->uma = $this->getDataGenerator()->create_user();
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
     * Call the service as the current user and clean the result the way the web service layer does.
     *
     * @param string $label The field.
     * @param int $role The side saved.
     * @param string $value
     * @return array
     */
    private function call(string $label, int $role, string $value): array {
        $result = save_answer::execute(
            $this->record->get('id'),
            $this->built->fields[$label]->get('id'),
            $role,
            $value
        );
        return external_api::clean_returnvalue(save_answer::execute_returns(), $result);
    }

    /**
     * The function is registered for the plugin's own AJAX calls only, as a write, not in any token-based service.
     *
     * @return void
     */
    public function test_registered_as_an_ajax_only_write(): void {
        $info = external_api::external_function_info('local_appraisal_save_answer');

        $this->assertTrue($info->allowed_from_ajax);
        $this->assertSame('write', $info->type);
        $this->assertSame(save_answer::class, $info->classname);
        $this->assertSame(
            'local/appraisal:appraise, local/appraisal:completeown',
            $info->capabilities
        );
    }

    /**
     * The appraisee saves their own answer and gets their own progress and the new status back.
     *
     * @return void
     */
    public function test_appraisee_saves_and_gets_own_progress(): void {
        $this->setUser($this->ellie);

        $result = $this->call('Self review', record_answer::ROLE_APPRAISEE, 'Good year');

        $this->assertTrue($result['answered']);
        $this->assertTrue($result['complete']);
        $this->assertNotSame('', $result['saved']);
        $this->assertSame(record::STATUS_IN_PROGRESS, $result['status']);
        $this->assertSame(1, $result['progresscomplete']);
        $this->assertSame(2, $result['progressrequired'], 'Goals and Self review are the appraisee\'s required fields.');
    }

    /**
     * The owner saves as the appraiser, and an empty value clears the answer and reports it.
     *
     * @return void
     */
    public function test_owner_saves_and_clears(): void {
        $this->setUser($this->alice);

        $saved = $this->call('Manager note', record_answer::ROLE_APPRAISER, 'Solid');
        $this->assertTrue($saved['answered']);
        $this->assertSame(record::STATUS_IN_PROGRESS, $saved['status']);

        $cleared = $this->call('Manager note', record_answer::ROLE_APPRAISER, '');
        $this->assertFalse($cleared['answered']);
        $this->assertFalse($cleared['complete']);
        $this->assertSame('', $cleared['saved']);
    }

    /**
     * The response never carries the other person's answer, before or after, only the saver's own state.
     *
     * @return void
     */
    public function test_response_never_carries_the_other_sides_answers(): void {
        $this->setUser($this->alice);
        $this->call('Goals', record_answer::ROLE_APPRAISER, 'Alice private goal');
        $this->setUser($this->ellie);

        $result = $this->call('Goals', record_answer::ROLE_APPRAISEE, 'Ellie goal');

        $this->assertStringNotContainsString('Alice private goal', json_encode($result));
        $this->assertEqualsCanonicalizing(
            ['answered', 'complete', 'saved', 'progresscomplete', 'progressrequired', 'status', 'statusname'],
            array_keys($result)
        );
    }

    /**
     * The progress in the response counts the saver's own answers only: the other person answering a field they both
     * complete must not move it, or the response would tell the saver whether the other has answered.
     *
     * @return void
     */
    public function test_progress_counts_only_the_savers_own_answers(): void {
        $this->setUser($this->alice);
        $this->call('Goals', record_answer::ROLE_APPRAISER, 'Alice goal');

        $this->setUser($this->ellie);
        $result = $this->call('Self review', record_answer::ROLE_APPRAISEE, 'Ellie review');

        $this->assertSame(1, $result['progresscomplete'], 'Alice\'s answer to Goals does not count for Ellie.');
        $this->assertSame(2, $result['progressrequired']);

        $ctx = \local_appraisal\api::get_record_context($this->record->get('id'));
        $this->assertSame(1, \local_appraisal\api::get_progress($ctx, record_answer::ROLE_APPRAISEE)['complete']);
        $this->assertSame(1, \local_appraisal\api::get_progress($ctx, record_answer::ROLE_APPRAISER)['complete']);
    }

    /**
     * The role in the request must be the user's own side: no one saves for the other side, and the stored answers
     * are untouched by the refusal.
     *
     * @return void
     */
    public function test_role_must_be_the_users_own_side(): void {
        $cases = [
            [$this->ellie, 'Manager note', record_answer::ROLE_APPRAISER],
            [$this->alice, 'Self review', record_answer::ROLE_APPRAISEE],
            [$this->ben, 'Manager note', record_answer::ROLE_APPRAISER],
            [$this->uma, 'Manager note', record_answer::ROLE_APPRAISER],
            [$this->uma, 'Self review', record_answer::ROLE_APPRAISEE],
        ];
        foreach ($cases as [$user, $label, $role]) {
            $this->setUser($user);
            try {
                $this->call($label, $role, 'Forged');
                $this->fail("{$user->id} saving {$label} as role {$role} should have been refused.");
            } catch (moodle_exception $e) {
                $this->assertSame('error:cannotwrite', $e->errorcode);
            }
        }
        $this->assertSame([], \local_appraisal\api::get_answers($this->record->get('id')));
    }

    /**
     * An administrator who is not the owner cannot write the owner's side.
     *
     * @return void
     */
    public function test_administrator_who_does_not_own_the_record_is_refused(): void {
        $this->setAdminUser();

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('error:cannotwrite', 'local_appraisal'));
        $this->call('Manager note', record_answer::ROLE_APPRAISER, 'Forged');
    }

    /**
     * A guest, with no side on the record, is refused.
     *
     * @return void
     */
    public function test_guest_is_refused(): void {
        $this->setGuestUser();

        try {
            $this->call('Self review', record_answer::ROLE_APPRAISEE, 'Forged');
            $this->fail('A guest should be refused.');
        } catch (moodle_exception $e) {
            $this->assertSame('error:cannotwrite', $e->errorcode);
        }
    }

    /**
     * Losing the capability for a side ends that person's write path, even on their own record.
     *
     * @return void
     */
    public function test_capability_is_required_for_the_side(): void {
        $this->gen->set_user_capability($this->ellie->id, 'local/appraisal:completeown', CAP_PROHIBIT);
        $this->gen->set_user_capability($this->alice->id, 'local/appraisal:appraise', CAP_PROHIBIT);

        $this->setUser($this->ellie);
        try {
            $this->call('Self review', record_answer::ROLE_APPRAISEE, 'x');
            $this->fail('The appraisee without completeown should be refused.');
        } catch (moodle_exception $e) {
            $this->assertSame('error:cannotwrite', $e->errorcode);
        }

        $this->setUser($this->alice);
        try {
            $this->call('Manager note', record_answer::ROLE_APPRAISER, 'x');
            $this->fail('The owner without appraise should be refused.');
        } catch (moodle_exception $e) {
            $this->assertSame('error:cannotwrite', $e->errorcode);
        }
    }

    /**
     * Once the appraisee has submitted, their own saves are refused but the owner's still work.
     *
     * @return void
     */
    public function test_appraisee_is_refused_after_submitting(): void {
        $this->setUser($this->ellie);
        $this->call('Self review', record_answer::ROLE_APPRAISEE, 'Before');
        $record = record::get_record(['id' => $this->record->get('id')], MUST_EXIST);
        $record->set('status', record::STATUS_SUBMITTED);
        $record->set('timesubmitted', time());
        $record->update();

        try {
            $this->call('Self review', record_answer::ROLE_APPRAISEE, 'After');
            $this->fail('A save after submitting should be refused.');
        } catch (moodle_exception $e) {
            $this->assertSame('error:cannotwrite', $e->errorcode);
        }

        $this->setUser($this->alice);
        $this->assertTrue($this->call('Manager note', record_answer::ROLE_APPRAISER, 'Still open')['answered']);
    }

    /**
     * A value the field cannot take is refused with the field's error and stores nothing.
     *
     * @return void
     */
    public function test_invalid_value_is_refused(): void {
        $this->setUser($this->alice);

        try {
            $this->call('Manager note', record_answer::ROLE_APPRAISER, str_repeat('x', 256));
            $this->fail('A short text over 255 characters should be refused.');
        } catch (moodle_exception $e) {
            $this->assertSame('error:answertoolong', $e->errorcode);
        }
        $this->assertSame([], \local_appraisal\api::get_answers($this->record->get('id')));
        $this->assertSame(
            record::STATUS_NOT_STARTED,
            \local_appraisal\api::get_record($this->record->get('id'))->get('status')
        );
    }

    /**
     * A record id that does not exist is refused.
     *
     * @return void
     */
    public function test_unknown_record_is_refused(): void {
        $this->setUser($this->alice);

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('error:recordnotfound', 'local_appraisal'));
        save_answer::execute(
            $this->record->get('id') + 1000,
            $this->built->fields['Manager note']->get('id'),
            record_answer::ROLE_APPRAISER,
            'x'
        );
    }
}
