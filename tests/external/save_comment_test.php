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
use local_appraisal\api;
use local_appraisal\record;
use local_appraisal\template_version;
use local_appraisal_generator;
use moodle_exception;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the autosave overall comments web service.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(save_comment::class)]
final class save_comment_test extends \advanced_testcase {
    /** @var local_appraisal_generator The plugin's data generator. */
    private local_appraisal_generator $gen;

    /** @var \stdClass The appraisee. */
    private \stdClass $ellie;

    /** @var \stdClass The record's owner. */
    private \stdClass $alice;

    /** @var \stdClass A second appraiser who does not own the record. */
    private \stdClass $ben;

    /** @var \stdClass The generated template. */
    private \stdClass $built;

    /** @var record The record under test. */
    private record $record;

    /**
     * Build one appraisal for Ellie, owned by Alice, with Ben as a second appraiser.
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
        $appraisers = $this->getDataGenerator()->create_cohort();
        $appraisees = $this->getDataGenerator()->create_cohort();
        cohort_add_member($appraisers->id, $this->alice->id);
        cohort_add_member($appraisers->id, $this->ben->id);
        cohort_add_member($appraisees->id, $this->ellie->id);
        $this->gen->create_cohort_link([
            'appraisercohortid' => $appraisers->id,
            'appraiseecohortid' => $appraisees->id,
        ]);

        $this->built = $this->gen->create_template(['fields' => [['label' => 'Goals']]]);
        $this->record = $this->gen->create_appraisal([
            'templateid' => $this->built->template->get('id'),
            'userid' => $this->ellie->id,
            'appraiserid' => $this->alice->id,
        ]);
    }

    /**
     * Call the service as the current user and clean the result the way the web service layer does.
     *
     * @param string $comment
     * @return array
     */
    private function call(string $comment): array {
        $result = save_comment::execute($this->record->get('id'), $comment);
        return external_api::clean_returnvalue(save_comment::execute_returns(), $result);
    }

    /**
     * The function is registered for the plugin's own AJAX calls only, as a write, not in any token-based service.
     *
     * @return void
     */
    public function test_registered_as_an_ajax_only_write(): void {
        $info = external_api::external_function_info('local_appraisal_save_comment');

        $this->assertTrue($info->allowed_from_ajax);
        $this->assertSame('write', $info->type);
        $this->assertSame(save_comment::class, $info->classname);
        $this->assertSame('local/appraisal:appraise', $info->capabilities);
    }

    /**
     * The owner saves the comment, which starts the record, and clears it again with an empty one.
     *
     * @return void
     */
    public function test_owner_saves_and_clears_the_comment(): void {
        $this->setUser($this->alice);

        $saved = $this->call('Overall a good year');
        $this->assertSame(record::STATUS_IN_PROGRESS, $saved['status']);
        $this->assertSame('Overall a good year', api::get_record($this->record->get('id'))->get('comment'));

        $cleared = $this->call('');
        $this->assertSame(record::STATUS_IN_PROGRESS, $cleared['status'], 'Clearing does not move the status back.');
        $this->assertNull(api::get_record($this->record->get('id'))->get('comment'));
    }

    /**
     * No one but the owner saves the comment: not the appraisee, a second appraiser, an outsider or an administrator.
     *
     * @return void
     */
    public function test_only_the_owner_may_save_the_comment(): void {
        $outsider = $this->getDataGenerator()->create_user();
        foreach ([$this->ellie, $this->ben, $outsider, get_admin()] as $user) {
            $this->setUser($user);
            try {
                $this->call('Forged');
                $this->fail("User {$user->id} should not save the comment.");
            } catch (moodle_exception $e) {
                $this->assertSame('error:cannotwrite', $e->errorcode);
            }
        }
        $this->assertNull(api::get_record($this->record->get('id'))->get('comment'));
    }

    /**
     * A guest is refused.
     *
     * @return void
     */
    public function test_guest_is_refused(): void {
        $this->setGuestUser();

        try {
            $this->call('Forged');
            $this->fail('A guest should be refused.');
        } catch (moodle_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
    }

    /**
     * The owner without the appraise capability is refused before anything is written.
     *
     * @return void
     */
    public function test_capability_is_required(): void {
        $this->gen->set_user_capability($this->alice->id, 'local/appraisal:appraise', CAP_PROHIBIT);
        $this->setUser($this->alice);

        try {
            $this->call('Forged');
            $this->fail('The owner without appraise should be refused.');
        } catch (moodle_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
    }

    /**
     * A version that takes no comments refuses them, and a completed record refuses them.
     *
     * @return void
     */
    public function test_refused_when_comments_are_off_or_the_record_is_completed(): void {
        // Built first: a caught exception leaves its transaction open, so nothing can be created after one.
        $off = $this->gen->create_template([
            'commentsmode' => template_version::COMMENTS_OFF,
            'fields' => [['label' => 'Goals']],
        ]);
        $offrecord = $this->gen->create_appraisal([
            'templateid' => $off->template->get('id'),
            'userid' => $this->ellie->id,
            'appraiserid' => $this->alice->id,
        ]);
        $this->setUser($this->alice);

        $record = record::get_record(['id' => $this->record->get('id')], MUST_EXIST);
        $record->set('status', record::STATUS_COMPLETED);
        $record->update();
        try {
            $this->call('Too late');
            $this->fail('A completed record should refuse the comment.');
        } catch (moodle_exception $e) {
            $this->assertSame('error:cannotwrite', $e->errorcode);
        }

        $this->record = $offrecord;
        try {
            $this->call('Not wanted');
            $this->fail('A version with comments off should refuse the comment.');
        } catch (moodle_exception $e) {
            $this->assertSame('error:commentsoff', $e->errorcode);
        }
    }

    /**
     * A comment over the limit is refused and nothing is stored.
     *
     * @return void
     */
    public function test_over_long_comment_is_refused(): void {
        $this->setUser($this->alice);

        try {
            $this->call(str_repeat('x', api::LONGTEXT_MAXLENGTH + 1));
            $this->fail('An over-long comment should be refused.');
        } catch (moodle_exception $e) {
            $this->assertSame('error:answertoolong', $e->errorcode);
        }
        $this->assertNull(api::get_record($this->record->get('id'))->get('comment'));
    }
}
