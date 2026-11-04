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
use local_appraisal_generator;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the Find a user web service: who may call it, who it finds and who it leaves out.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(search_users::class)]
final class search_users_test extends \advanced_testcase {
    /** @var local_appraisal_generator The plugin's data generator. */
    private local_appraisal_generator $gen;

    /** @var \stdClass The searcher, who holds accessalldashboards. */
    private \stdClass $searcher;

    /**
     * Sign in as a user who holds accessalldashboards.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->gen = $this->getDataGenerator()->get_plugin_generator('local_appraisal');
        $this->searcher = $this->getDataGenerator()->create_user(['firstname' => 'Sally', 'lastname' => 'Searcher']);
        $this->gen->set_user_capability((int) $this->searcher->id, 'local/appraisal:accessalldashboards');
        $this->setUser($this->searcher);
    }

    /**
     * Run the service the way the AJAX layer does and clean the result against its declared structure.
     *
     * @param string $query
     * @return array
     */
    private function search(string $query): array {
        return external_api::clean_returnvalue(search_users::execute_returns(), search_users::execute($query));
    }

    /**
     * The full names found for a query.
     *
     * @param string $query
     * @return string[] In the order returned.
     */
    private function names(string $query): array {
        return array_column($this->search($query), 'fullname');
    }

    /**
     * The function is registered for the plugin's own AJAX calls only, as a read, needing accessalldashboards.
     *
     * @return void
     */
    public function test_registered_as_an_ajax_only_read(): void {
        $info = external_api::external_function_info('local_appraisal_search_users');

        $this->assertTrue($info->allowed_from_ajax);
        $this->assertSame('read', $info->type);
        $this->assertSame(search_users::class, $info->classname);
        $this->assertSame('local/appraisal:accessalldashboards', $info->capabilities);
    }

    /**
     * A user is found by any part of their name, in any case, with their id and full name returned, ordered by last
     * name then first name.
     *
     * @return void
     */
    public function test_finds_users_by_part_of_the_name(): void {
        $zed = $this->getDataGenerator()->create_user(['firstname' => 'Pat', 'lastname' => 'Zedd']);
        $abe = $this->getDataGenerator()->create_user(['firstname' => 'Pat', 'lastname' => 'Abel']);
        $this->getDataGenerator()->create_user(['firstname' => 'Quentin', 'lastname' => 'Other']);

        $results = $this->search('  pAT ');

        $this->assertSame([fullname($abe), fullname($zed)], array_column($results, 'fullname'));
        $this->assertSame([(int) $abe->id, (int) $zed->id], array_column($results, 'id'));
        $this->assertSame([fullname($zed)], $this->names('edd'));
        $this->assertSame([fullname($abe)], $this->names('Pat Abel'), 'A full name matches.');
    }

    /**
     * An empty or blank query returns nothing, and so does one that matches nobody.
     *
     * @return void
     */
    public function test_empty_and_unmatched_queries_return_nothing(): void {
        $this->getDataGenerator()->create_user(['firstname' => 'Pat', 'lastname' => 'Abel']);

        $this->assertSame([], $this->search(''));
        $this->assertSame([], $this->search('   '));
        $this->assertSame([], $this->search('Nobody-by-this-name'));
    }

    /**
     * The searcher, the guest, suspended users and deleted users are never returned.
     *
     * @return void
     */
    public function test_leaves_out_the_searcher_guest_suspended_and_deleted(): void {
        $found = $this->getDataGenerator()->create_user(['firstname' => 'Kim', 'lastname' => 'Found']);
        $this->getDataGenerator()->create_user(['firstname' => 'Kim', 'lastname' => 'Suspended', 'suspended' => 1]);
        $deleted = $this->getDataGenerator()->create_user(['firstname' => 'Kim', 'lastname' => 'Deleted']);
        delete_user($deleted);

        $this->assertSame([fullname($found)], $this->names('Kim'));
        $this->assertSame([], $this->names('Sally'), 'The searcher is left out.');
        $this->assertSame([], $this->names('Guest'));
        $this->assertSame([], $this->names('Searcher'));
    }

    /**
     * At most 30 users come back for one query.
     *
     * @return void
     */
    public function test_results_are_limited(): void {
        for ($i = 0; $i < search_users::LIMIT + 5; $i++) {
            $this->getDataGenerator()->create_user(['firstname' => 'Bulk', 'lastname' => sprintf('Person%03d', $i)]);
        }

        $names = $this->names('Bulk');

        $this->assertCount(30, $names);
        $this->assertSame(30, search_users::LIMIT);
        $this->assertStringContainsString('Person000', $names[0]);
    }

    /**
     * The identity fields the searcher is allowed to see are searched and shown; a field they may not see is neither
     * searched nor shown.
     *
     * @return void
     */
    public function test_identity_fields_follow_what_the_searcher_may_see(): void {
        global $CFG;

        $CFG->showuseridentity = 'email';
        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Ida',
            'lastname' => 'Identity',
            'email' => 'ida.identity@example.com',
        ]);

        // Without moodle/site:viewuseridentity the email is hidden from the searcher, so it is not searched either.
        $this->assertSame([], $this->search('ida.identity@'));
        $this->assertSame('', $this->search('Identity')[0]['extra']);

        $this->gen->set_user_capability((int) $this->searcher->id, 'moodle/site:viewuseridentity');
        $results = $this->search('ida.identity@');
        $this->assertSame([(int) $user->id], array_column($results, 'id'));
        $this->assertSame('ida.identity@example.com', $results[0]['extra']);

        $CFG->showuseridentity = '';
        $this->assertSame([], $this->search('ida.identity@'), 'A field the site does not show is not searched.');
        $this->assertSame('', $this->search('Identity')[0]['extra']);
    }

    /**
     * Someone without accessalldashboards, an appraiser included, is refused, and so are nobody and the guest.
     *
     * @return void
     */
    public function test_refused_without_accessalldashboards(): void {
        $this->getDataGenerator()->create_user(['firstname' => 'Kim', 'lastname' => 'Found']);

        $appraiser = $this->getDataGenerator()->create_user();
        $this->gen->set_user_capability((int) $appraiser->id, 'local/appraisal:appraise');
        foreach ([$this->getDataGenerator()->create_user(), $appraiser] as $user) {
            $this->setUser($user);
            try {
                search_users::execute('Kim');
                $this->fail('Expected a refusal.');
            } catch (\required_capability_exception $e) {
                $this->assertInstanceOf(\required_capability_exception::class, $e);
            }
        }

        $this->setUser(0);
        try {
            search_users::execute('Kim');
            $this->fail('Nobody should be let in.');
        } catch (\require_login_exception $e) {
            $this->assertInstanceOf(\require_login_exception::class, $e);
        }

        $this->setGuestUser();
        try {
            search_users::execute('Kim');
            $this->fail('The guest should be refused.');
        } catch (\Exception $e) {
            $this->assertTrue($e instanceof \require_login_exception || $e instanceof \required_capability_exception);
        }
    }
}
