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

use core\invalid_persistent_exception;
use local_appraisal_generator;
use moodle_exception;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the three dashboard access gates and the rules built on them.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(access::class)]
#[CoversClass(observer::class)]
final class access_test extends \advanced_testcase {
    /** @var local_appraisal_generator The plugin's data generator. */
    private local_appraisal_generator $gen;

    /**
     * Reset the database after each test, keep messages from being sent, and load the generator and cohort library.
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
    }

    /**
     * Create two cohorts, link them and put the given users in them.
     *
     * @param int[] $appraiserids Members of the appraiser cohort.
     * @param int[] $appraiseeids Members of the appraisee cohort.
     * @return \stdClass With appraisercohort, appraiseecohort and link.
     */
    private function create_link(array $appraiserids, array $appraiseeids): \stdClass {
        $appraisercohort = $this->getDataGenerator()->create_cohort();
        $appraiseecohort = $this->getDataGenerator()->create_cohort();
        foreach ($appraiserids as $userid) {
            cohort_add_member($appraisercohort->id, $userid);
        }
        foreach ($appraiseeids as $userid) {
            cohort_add_member($appraiseecohort->id, $userid);
        }
        $link = $this->gen->create_cohort_link([
            'appraisercohortid' => $appraisercohort->id,
            'appraiseecohortid' => $appraiseecohort->id,
        ]);
        return (object) compact('appraisercohort', 'appraiseecohort', 'link');
    }

    /**
     * Create a text profile field for the appraiser's username and switch profile-based access on.
     *
     * @return \stdClass The profile field.
     */
    private function enable_profile_gate(): \stdClass {
        $field = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'lineman',
            'name' => 'Line manager',
        ]);
        set_config('profileaccessenabled', 1, 'local_appraisal');
        set_config('appraiserfield', access::FIELD_CUSTOM . $field->id, 'local_appraisal');
        return $field;
    }

    /**
     * Store a value in a user's profile field.
     *
     * @param int $fieldid
     * @param int $userid
     * @param string $value
     * @return void
     */
    private function set_profile_value(int $fieldid, int $userid, string $value): void {
        global $DB;
        $DB->insert_record('user_info_data', (object) [
            'userid' => $userid,
            'fieldid' => $fieldid,
            'data' => $value,
            'dataformat' => 0,
        ]);
    }

    /**
     * The ids of the users a fragment from get_appraisees_sql() matches.
     *
     * @param \stdClass $viewer
     * @return int[] Sorted.
     */
    private function listed_user_ids(\stdClass $viewer): array {
        global $DB;
        [$sql, $params] = access::get_appraisees_sql($viewer);
        $ids = array_map('intval', $DB->get_fieldset_sql('SELECT u.id FROM {user} u WHERE ' . $sql, $params));
        sort($ids);
        return $ids;
    }

    /**
     * A user reaches their own dashboard with completeown, and loses it when the capability is prohibited.
     *
     * @return void
     */
    public function test_user_reaches_own_dashboard_only_with_completeown(): void {
        $user = $this->getDataGenerator()->create_user();

        $this->assertTrue(access::can_access_dashboard($user->id, $user->id));

        $this->gen->set_user_capability($user->id, 'local/appraisal:completeown', CAP_PROHIBIT);
        $this->assertFalse(access::can_access_dashboard($user->id, $user->id));
    }

    /**
     * Holding appraise is not enough: with no gate, nobody can appraise or reach another user's dashboard.
     *
     * @return void
     */
    public function test_no_gate_means_no_access(): void {
        $viewer = $this->getDataGenerator()->create_user();
        $user = $this->getDataGenerator()->create_user();

        $this->assertFalse(access::can_appraise($viewer->id, $user->id));
        $this->assertFalse(access::can_access_dashboard($viewer->id, $user->id));
        $this->assertFalse(access::is_appraiser($viewer->id));

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('error:nodashboardaccess', 'local_appraisal'));
        access::require_dashboard_access($viewer->id, $user->id);
    }

    /**
     * A cohort link reaches one way only: the appraiser cohort's members reach the appraisee cohort's, not back.
     *
     * @return void
     */
    public function test_cohort_link_reaches_one_way_only(): void {
        $manager = $this->getDataGenerator()->create_user();
        $member = $this->getDataGenerator()->create_user();
        $this->create_link([$manager->id], [$member->id]);

        $this->assertTrue(access::can_appraise($manager->id, $member->id));
        $this->assertTrue(access::can_access_dashboard($manager->id, $member->id));
        $this->assertFalse(access::can_appraise($member->id, $manager->id));
        $this->assertTrue(access::is_linked_appraiser($manager->id, $member->id));
        $this->assertFalse(access::is_linked_appraiser($member->id, $manager->id));
    }

    /**
     * Cohort membership is read live: joining the appraisee cohort grants access at once, leaving the appraiser
     * cohort takes it away, and the plugin needs no change either time.
     *
     * @return void
     */
    public function test_cohort_membership_changes_take_effect_at_once(): void {
        $manager = $this->getDataGenerator()->create_user();
        $newhire = $this->getDataGenerator()->create_user();
        $linked = $this->create_link([$manager->id], []);

        $this->assertFalse(access::can_appraise($manager->id, $newhire->id));

        cohort_add_member($linked->appraiseecohort->id, $newhire->id);
        $this->assertTrue(access::can_appraise($manager->id, $newhire->id));

        cohort_remove_member($linked->appraisercohort->id, $manager->id);
        $this->assertFalse(access::can_appraise($manager->id, $newhire->id));
    }

    /**
     * A manager who sits in both cohorts of a link reaches the others in the appraisee cohort, but never themselves.
     *
     * @return void
     */
    public function test_manager_in_both_cohorts_cannot_appraise_themselves(): void {
        $manager = $this->getDataGenerator()->create_user();
        $colleague = $this->getDataGenerator()->create_user();
        $this->create_link([$manager->id], [$manager->id, $colleague->id]);

        $this->assertFalse(access::can_appraise($manager->id, $manager->id));
        $this->assertTrue(access::can_appraise($manager->id, $colleague->id));
        // Colleagues in the same appraisee cohort cannot reach each other unless their own cohort is an appraiser one.
        $this->assertFalse(access::can_appraise($colleague->id, $manager->id));
    }

    /**
     * Without the appraise capability a cohort link grants nothing.
     *
     * @return void
     */
    public function test_cohort_link_needs_the_appraise_capability(): void {
        $manager = $this->getDataGenerator()->create_user();
        $member = $this->getDataGenerator()->create_user();
        $this->create_link([$manager->id], [$member->id]);
        $this->assertTrue(access::can_appraise($manager->id, $member->id));

        $this->gen->set_user_capability($manager->id, 'local/appraisal:appraise', CAP_PROHIBIT);

        $this->assertFalse(access::can_appraise($manager->id, $member->id));
        $this->assertFalse(access::is_appraiser($manager->id));
    }

    /**
     * The site-wide gate reaches everyone, but still not oneself, and still needs appraise.
     *
     * @return void
     */
    public function test_site_wide_gate(): void {
        $hr = $this->getDataGenerator()->create_user();
        $user = $this->getDataGenerator()->create_user();
        $this->gen->set_user_capability($hr->id, 'local/appraisal:accessalldashboards');

        $this->assertTrue(access::can_appraise($hr->id, $user->id));
        $this->assertTrue(access::can_access_dashboard($hr->id, $user->id));
        $this->assertFalse(access::can_appraise($hr->id, $hr->id));
        $this->assertTrue(access::is_appraiser($hr->id));

        $this->gen->set_user_capability($hr->id, 'local/appraisal:appraise', CAP_PROHIBIT);
        $this->assertFalse(access::can_appraise($hr->id, $user->id));
        $this->assertFalse(access::is_appraiser($hr->id));
    }

    /**
     * Nobody can be appraised through the guest user's id.
     *
     * @return void
     */
    public function test_guest_cannot_be_appraised(): void {
        $hr = $this->getDataGenerator()->create_user();
        $this->gen->set_user_capability($hr->id, 'local/appraisal:accessalldashboards');

        $this->assertFalse(access::can_appraise($hr->id, guest_user()->id));
    }

    /**
     * The profile-field gate: the user's field names the viewer, ignoring case and surrounding spaces, and only
     * while the setting is on.
     *
     * @return void
     */
    public function test_profile_gate(): void {
        $manager = $this->getDataGenerator()->create_user(['username' => 'linemanager']);
        $report = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $field = $this->enable_profile_gate();
        $this->set_profile_value($field->id, $report->id, '  LineManager ');

        $this->assertTrue(access::profile_access_enabled());
        $this->assertTrue(access::can_appraise($manager->id, $report->id));
        $this->assertFalse(access::can_appraise($manager->id, $stranger->id));
        $this->assertFalse(access::can_appraise($report->id, $manager->id));
        $this->assertSame('LineManager', access::get_appraiser_username($report->id));
        $this->assertNull(access::get_appraiser_username($stranger->id));

        set_config('profileaccessenabled', 0, 'local_appraisal');
        $this->assertFalse(access::profile_access_enabled());
        $this->assertFalse(access::can_appraise($manager->id, $report->id));
    }

    /**
     * The profile gate stays shut when the setting points at nothing usable.
     *
     * @return void
     */
    public function test_profile_gate_needs_a_custom_field(): void {
        $manager = $this->getDataGenerator()->create_user(['username' => 'linemanager']);
        $report = $this->getDataGenerator()->create_user();
        set_config('profileaccessenabled', 1, 'local_appraisal');

        set_config('appraiserfield', '', 'local_appraisal');
        $this->assertNull(access::get_appraiser_fieldid());
        $this->assertFalse(access::profile_access_enabled());

        set_config('appraiserfield', 'department', 'local_appraisal');
        $this->assertNull(access::get_appraiser_fieldid());
        $this->assertFalse(access::is_profile_appraiser($manager->id, $report->id));
    }

    /**
     * The field picker offers custom text fields only, and flags a field the user can edit.
     *
     * @return void
     */
    public function test_appraiser_field_options_and_editable_warning(): void {
        $text = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'lineman',
            'name' => 'Line manager',
        ]);
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'checkbox',
            'shortname' => 'tick',
            'name' => 'A tick box',
        ]);

        $options = access::get_appraiser_field_options();
        $this->assertArrayHasKey(access::FIELD_CUSTOM . $text->id, $options);
        $this->assertCount(2, $options, 'The empty choice and the one text field.');

        $this->assertFalse(access::is_appraiser_field_user_editable(), 'Nothing is configured yet.');
        set_config('appraiserfield', access::FIELD_CUSTOM . $text->id, 'local_appraisal');
        $this->assertTrue(access::is_appraiser_field_user_editable());

        global $DB;
        $DB->set_field('user_info_field', 'locked', 1, ['id' => $text->id]);
        $this->assertFalse(access::is_appraiser_field_user_editable());
    }

    /**
     * Deleting needs deleteappraisals on top of reaching the user, and is never available on oneself.
     *
     * @return void
     */
    public function test_can_delete_appraisal(): void {
        $hr = $this->getDataGenerator()->create_user();
        $user = $this->getDataGenerator()->create_user();
        $this->gen->set_user_capability($hr->id, 'local/appraisal:accessalldashboards');

        $this->assertFalse(access::can_delete_appraisal($hr->id, $user->id), 'Reach without the capability.');

        $this->gen->set_user_capability($hr->id, 'local/appraisal:deleteappraisals');
        $this->assertTrue(access::can_delete_appraisal($hr->id, $user->id));
        $this->assertFalse(access::can_delete_appraisal($hr->id, $hr->id), 'Never one\'s own.');

        $nogate = $this->getDataGenerator()->create_user();
        $this->gen->set_user_capability($nogate->id, 'local/appraisal:deleteappraisals');
        $this->assertFalse(access::can_delete_appraisal($nogate->id, $user->id), 'The capability without a gate.');
    }

    /**
     * Deleting a template needs managetemplates, and deleteappraisals as well once it has assignments.
     *
     * @return void
     */
    public function test_can_delete_template(): void {
        $builder = $this->getDataGenerator()->create_user();
        $appraisee = $this->getDataGenerator()->create_user();
        $appraiser = $this->getDataGenerator()->create_user();
        $template = $this->gen->create_template(['fields' => [['label' => 'Goals']]])->template;
        $templateid = $template->get('id');

        $this->assertFalse(access::can_delete_template($templateid, $builder->id), 'No managetemplates.');

        $this->gen->set_user_capability($builder->id, 'local/appraisal:managetemplates');
        $this->assertTrue(access::can_delete_template($templateid, $builder->id), 'Unassigned template.');

        $this->gen->create_appraisal([
            'templateid' => $templateid,
            'userid' => $appraisee->id,
            'appraiserid' => $appraiser->id,
            'nogate' => true,
        ]);
        $this->assertFalse(access::can_delete_template($templateid, $builder->id), 'Assigned, no deleteappraisals.');

        $this->gen->set_user_capability($builder->id, 'local/appraisal:deleteappraisals');
        $this->assertTrue(access::can_delete_template($templateid, $builder->id));
    }

    /**
     * An appraiser who reaches only themselves is not an appraiser of anyone, and owning a record is no gate.
     *
     * @return void
     */
    public function test_is_appraiser_ignores_self_and_record_ownership(): void {
        $manager = $this->getDataGenerator()->create_user();
        $this->create_link([$manager->id], [$manager->id]);
        $this->assertFalse(access::is_appraiser($manager->id), 'The only person the link reaches is themselves.');

        $owner = $this->getDataGenerator()->create_user();
        $appraisee = $this->getDataGenerator()->create_user();
        $template = $this->gen->create_template(['fields' => [['label' => 'Goals']]])->template;
        $this->gen->create_appraisal([
            'templateid' => $template->get('id'),
            'userid' => $appraisee->id,
            'appraiserid' => $owner->id,
            'nogate' => true,
        ]);
        $this->assertFalse(access::is_appraiser($owner->id), 'Owning a record is not a standing permission.');
    }

    /**
     * Every gate puts the people it reaches on the People I appraise list, and never the viewer or deleted users.
     *
     * @return void
     */
    public function test_appraisees_sql_lists_linked_and_profile_users_but_not_the_viewer(): void {
        $viewer = $this->getDataGenerator()->create_user(['username' => 'linemanager']);
        $linked = $this->getDataGenerator()->create_user();
        $deleted = $this->getDataGenerator()->create_user();
        $byprofile = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $field = $this->enable_profile_gate();
        $this->set_profile_value($field->id, $byprofile->id, 'linemanager');
        $this->create_link([$viewer->id], [$viewer->id, $linked->id, $deleted->id]);
        delete_user($deleted);

        $listed = $this->listed_user_ids($viewer);

        $this->assertContains((int) $linked->id, $listed);
        $this->assertContains((int) $byprofile->id, $listed);
        $this->assertNotContains((int) $viewer->id, $listed, 'Even in both cohorts of a link.');
        $this->assertNotContains((int) $deleted->id, $listed);
        $this->assertNotContains((int) $stranger->id, $listed);
    }

    /**
     * Without appraise the list is empty, whatever links the viewer sits in.
     *
     * @return void
     */
    public function test_appraisees_sql_is_empty_without_the_appraise_capability(): void {
        $viewer = $this->getDataGenerator()->create_user();
        $linked = $this->getDataGenerator()->create_user();
        $this->create_link([$viewer->id], [$linked->id]);
        $this->assertSame([(int) $linked->id], $this->listed_user_ids($viewer));

        $this->gen->set_user_capability($viewer->id, 'local/appraisal:appraise', CAP_PROHIBIT);

        $this->assertSame([], $this->listed_user_ids($viewer));
        [$sql] = access::get_appraisees_sql($viewer);
        $this->assertSame('1 = 0', $sql);
    }

    /**
     * The "records I own" branch of the list belongs to the site-wide gate: an owner without it is not listed
     * against the people they once appraised.
     *
     * @return void
     */
    public function test_appraisees_sql_record_branch_needs_the_site_wide_gate(): void {
        $owner = $this->getDataGenerator()->create_user();
        $appraisee = $this->getDataGenerator()->create_user();
        $template = $this->gen->create_template(['fields' => [['label' => 'Goals']]])->template;
        $this->gen->create_appraisal([
            'templateid' => $template->get('id'),
            'userid' => $appraisee->id,
            'appraiserid' => $owner->id,
            'nogate' => true,
        ]);

        $this->assertSame([], $this->listed_user_ids($owner), 'No gate: an owned record is not a route onto the list.');

        $this->gen->set_user_capability($owner->id, 'local/appraisal:accessalldashboards');
        $this->assertSame([(int) $appraisee->id], $this->listed_user_ids($owner));
    }

    /**
     * Adding a link fires its event and refuses a repeat, a missing cohort and a link to itself.
     *
     * @return void
     */
    public function test_cohort_link_refusals(): void {
        $first = $this->getDataGenerator()->create_cohort();
        $second = $this->getDataGenerator()->create_cohort();
        $sink = $this->redirectEvents();
        api::create_cohort_link($first->id, $second->id);
        $events = array_filter(
            $sink->get_events(),
            fn(\core\event\base $event): bool => $event instanceof event\cohort_link_created
        );
        $this->assertCount(1, $events);
        $sink->close();

        try {
            api::create_cohort_link($first->id, $second->id);
            $this->fail('A repeated pair should be refused.');
        } catch (moodle_exception $e) {
            $this->assertSame('error:linkexists', $e->errorcode);
        }
        // The reverse pair is a different link.
        $this->assertNotNull(api::create_cohort_link($second->id, $first->id));

        try {
            api::create_cohort_link($first->id, $first->id + 1000);
            $this->fail('A missing cohort should be refused.');
        } catch (moodle_exception $e) {
            $this->assertSame('error:cohortmissing', $e->errorcode);
        }

        $this->expectException(invalid_persistent_exception::class);
        api::create_cohort_link($first->id, $first->id);
    }

    /**
     * Deleting a cohort in core deletes every link that names it, on either side, and leaves other links alone.
     *
     * @return void
     */
    public function test_deleting_a_cohort_deletes_its_links(): void {
        $a = $this->getDataGenerator()->create_cohort();
        $b = $this->getDataGenerator()->create_cohort();
        $c = $this->getDataGenerator()->create_cohort();
        $d = $this->getDataGenerator()->create_cohort();
        $this->gen->create_cohort_link(['appraisercohortid' => $a->id, 'appraiseecohortid' => $b->id]);
        $this->gen->create_cohort_link(['appraisercohortid' => $c->id, 'appraiseecohortid' => $a->id]);
        $keep = $this->gen->create_cohort_link(['appraisercohortid' => $c->id, 'appraiseecohortid' => $d->id]);

        cohort_delete_cohort($a);

        $remaining = api::get_cohort_links();
        $this->assertCount(1, $remaining);
        $this->assertSame($keep->get('id'), reset($remaining)->get('id'));
    }

    /**
     * Deleting a link removes the access it gave, and fires the deleted event.
     *
     * @return void
     */
    public function test_deleting_a_link_removes_access(): void {
        $manager = $this->getDataGenerator()->create_user();
        $member = $this->getDataGenerator()->create_user();
        $linked = $this->create_link([$manager->id], [$member->id]);
        $this->assertTrue(access::can_appraise($manager->id, $member->id));

        $sink = $this->redirectEvents();
        api::delete_cohort_link($linked->link->get('id'));
        $events = array_filter(
            $sink->get_events(),
            fn(\core\event\base $event): bool => $event instanceof event\cohort_link_deleted
        );
        $this->assertCount(1, $events);
        $sink->close();

        $this->assertFalse(access::can_appraise($manager->id, $member->id));
    }

    /**
     * The links table lists names and member counts for both cohorts.
     *
     * @return void
     */
    public function test_cohort_link_rows_report_member_counts(): void {
        $users = [];
        for ($i = 0; $i < 3; $i++) {
            $users[] = $this->getDataGenerator()->create_user()->id;
        }
        $linked = $this->create_link([$users[0]], [$users[1], $users[2]]);

        $rows = api::get_cohort_link_rows();

        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame($linked->appraisercohort->name, $row->appraisername);
        $this->assertSame($linked->appraiseecohort->name, $row->appraiseename);
        $this->assertSame(1, $row->appraisercount);
        $this->assertSame(2, $row->appraiseecount);
    }
}
