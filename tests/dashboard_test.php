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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the queries behind the dashboards: assignable templates, due dates, a person's dashboard rows, their
 * completed history, and the People I appraise counts, filters and ordering.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(api::class)]
final class dashboard_test extends \advanced_testcase {
    /** @var local_appraisal_generator The plugin's data generator. */
    private local_appraisal_generator $gen;

    /** @var \stdClass The appraisee most tests look at. */
    private \stdClass $ellie;

    /** @var \stdClass An appraiser who reaches every appraisee through a cohort link. */
    private \stdClass $alice;

    /** @var \stdClass A second appraiser with the same reach. */
    private \stdClass $ben;

    /** @var int The appraisee cohort, which the appraiser cohort is linked to. */
    private int $appraiseecohortid;

    /** @var \core\clock Frozen at 2030-03-01 12:00 UTC. */
    private \core\clock $clock;

    /** @var scale A scale with the levels Low, Medium and High. */
    private scale $scale;

    /** @var int[] The scale's level ids, lowest first. */
    private array $levelids;

    /**
     * Build the people, the cohort link and a scale.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->redirectMessages();
        $this->clock = $this->mock_clock_with_frozen(strtotime('2030-03-01 12:00:00 UTC'));
        $this->gen = $this->getDataGenerator()->get_plugin_generator('local_appraisal');

        $this->ellie = $this->getDataGenerator()->create_user(['firstname' => 'Ellie', 'lastname' => 'Appraisee']);
        $this->alice = $this->getDataGenerator()->create_user(['firstname' => 'Alice', 'lastname' => 'Owner']);
        $this->ben = $this->getDataGenerator()->create_user(['firstname' => 'Ben', 'lastname' => 'Second']);
        $appraisers = $this->getDataGenerator()->create_cohort();
        $appraisees = $this->getDataGenerator()->create_cohort();
        $this->appraiseecohortid = (int) $appraisees->id;
        cohort_add_member($appraisers->id, $this->alice->id);
        cohort_add_member($appraisers->id, $this->ben->id);
        cohort_add_member($appraisees->id, $this->ellie->id);
        $this->gen->create_cohort_link([
            'appraisercohortid' => $appraisers->id,
            'appraiseecohortid' => $appraisees->id,
        ]);

        $this->scale = $this->gen->create_scale();
        $this->levelids = array_keys(api::get_scale_levels($this->scale->get('id')));
    }

    /**
     * A published template with an appraiser field and an optional appraisee field, and an optional overall grade.
     *
     * @param string $name
     * @return \stdClass As the generator returns it.
     */
    private function template(string $name): \stdClass {
        return $this->gen->create_template([
            'name' => $name,
            'gradingmode' => template_version::GRADE_OPTIONAL,
            'overallscaleid' => $this->scale->get('id'),
            'fields' => [
                ['label' => 'Note', 'completedby' => template_field::COMPLETEDBY_APPRAISER],
                ['label' => 'Self', 'completedby' => template_field::COMPLETEDBY_APPRAISEE, 'required' => false],
            ],
        ]);
    }

    /**
     * A new user in the appraisee cohort.
     *
     * @param string $firstname
     * @param string $lastname
     * @return \stdClass
     */
    private function appraisee(string $firstname, string $lastname): \stdClass {
        $user = $this->getDataGenerator()->create_user(['firstname' => $firstname, 'lastname' => $lastname]);
        cohort_add_member($this->appraiseecohortid, $user->id);
        return $user;
    }

    /**
     * Assign a template, owned by the given appraiser.
     *
     * @param \stdClass $built A template from template().
     * @param \stdClass $user The appraisee.
     * @param int|null $duedate
     * @param \stdClass|null $owner The appraiser, or Alice.
     * @return record
     */
    private function assign(\stdClass $built, \stdClass $user, ?int $duedate = null, ?\stdClass $owner = null): record {
        return $this->gen->create_appraisal([
            'templateid' => $built->template->get('id'),
            'userid' => $user->id,
            'appraiserid' => ($owner ?? $this->alice)->id,
            'duedate' => $duedate,
        ]);
    }

    /**
     * The appraisee submits and the owner signs a record off, a minute apart, optionally with an overall grade.
     *
     * @param record $record
     * @param int|null $levelid
     * @return record
     */
    private function complete(record $record, ?int $levelid = null): record {
        $fields = api::get_fields($record->get('template_versionid'));
        $note = reset($fields);
        $this->clock->bump(60);
        api::save_answer(
            $record->get('id'),
            $note->get('id'),
            record_answer::ROLE_APPRAISER,
            (int) $record->get('appraiserid'),
            'Done'
        );
        $this->clock->bump(60);
        $appraiseeid = (int) api::get_assignment($record->get('assignmentid'))->get('userid');
        api::submit_part($record->get('id'), $appraiseeid);
        $this->clock->bump(60);
        return api::sign_off($record->get('id'), (int) $record->get('appraiserid'), $levelid);
    }

    /**
     * Only active templates with a published version, not yet assigned to the user, can be assigned; they come
     * ordered by name, and another user is offered the ones this user already has.
     *
     * @return void
     */
    public function test_assignable_templates(): void {
        $zebra = $this->template('Zebra');
        $apple = $this->template('Apple');
        $archived = $this->template('Archived');
        api::archive_template($archived->template->get('id'));
        $draft = $this->gen->create_template(['name' => 'Draft only', 'fields' => [['label' => 'Note']], 'publish' => false]);
        $taken = $this->template('Taken');
        $this->assign($taken, $this->ellie);

        $offered = api::get_assignable_templates((int) $this->ellie->id);

        $this->assertSame(
            [$apple->template->get('id'), $zebra->template->get('id')],
            array_keys($offered),
            'Ordered by name; archived, unpublished and already assigned are left out.'
        );
        $this->assertArrayNotHasKey($draft->template->get('id'), $offered);

        $other = $this->appraisee('Fred', 'Other');
        $this->assertArrayHasKey($taken->template->get('id'), api::get_assignable_templates((int) $other->id));

        // A published template that gained an unpublished draft is still offered.
        api::create_draft($apple->template->get('id'));
        $this->assertArrayHasKey($apple->template->get('id'), api::get_assignable_templates((int) $this->ellie->id));

        api::unarchive_template($archived->template->get('id'));
        $this->assertArrayHasKey($archived->template->get('id'), api::get_assignable_templates((int) $this->ellie->id));
    }

    /**
     * A chosen day becomes 23:59:59 on that day in the site timezone, whatever the time of day chosen and whichever
     * timezone the chooser is in.
     *
     * @return void
     */
    public function test_due_date_from_day(): void {
        $sitezone = api::site_timezone();
        $day = make_timestamp(2030, 3, 15, 0, 0, 0, $sitezone);

        foreach ([$day, $day + 5 * HOURSECS, $day + DAYSECS - 1] as $chosen) {
            $due = api::due_date_from_day($chosen);
            $this->assertSame('2030-03-15 23:59:59', userdate($due, '%Y-%m-%d %H:%M:%S', $sitezone, false), (string) $chosen);
        }
        $this->assertSame('2030-03-16', userdate(api::due_date_from_day($day + DAYSECS), '%Y-%m-%d', $sitezone, false));

        // A date selector in another timezone returns midnight there; the day the chooser meant is kept.
        $this->setUser($this->getDataGenerator()->create_user(['timezone' => 'Pacific/Auckland']));
        $chosen = make_timestamp(2030, 3, 15, 0, 30, 0, 'Pacific/Auckland');
        $due = api::due_date_from_day($chosen);
        $this->assertSame('2030-03-15 23:59:59', userdate($due, '%Y-%m-%d %H:%M:%S', $sitezone, false));
    }

    /**
     * A user with no assignments has no rows.
     *
     * @return void
     */
    public function test_dashboard_rows_are_empty_without_assignments(): void {
        $this->assertSame([], api::get_dashboard_rows((int) $this->ellie->id, $this->clock->time()));
    }

    /**
     * One row per assignment, ordered by template name, each with its open cycle, whether it is overdue, the latest
     * completed cycle with its grade label, and how many cycles there are.
     *
     * @return void
     */
    public function test_dashboard_rows(): void {
        $now = $this->clock->time();
        $beta = $this->template('Beta');
        $alpha = $this->template('Alpha');
        $gamma = $this->template('Gamma');
        $delta = $this->template('Delta');

        $open = $this->assign($beta, $this->ellie, $now - DAYSECS);
        $first = $this->assign($alpha, $this->ellie);
        $first = $this->complete($first, $this->levelids[2]);
        $second = api::start_cycle($first->get('assignmentid'), (int) $this->alice->id, null, $now + 5 * DAYSECS);
        $notdue = $this->assign($gamma, $this->ellie);
        $nograde = $this->complete($this->assign($delta, $this->ellie));
        $this->assign($this->template('Someone else'), $this->appraisee('Fred', 'Other'));

        $rows = array_values(api::get_dashboard_rows((int) $this->ellie->id, $now + 1));

        $this->assertSame(['Alpha', 'Beta', 'Delta', 'Gamma'], array_column($rows, 'templatename'));
        [$alpharow, $betarow, $deltarow, $gammarow] = $rows;

        $this->assertSame($second->get('id'), $alpharow->open->get('id'));
        $this->assertFalse($alpharow->overdue, 'Due in the future.');
        $this->assertSame($first->get('id'), $alpharow->last->get('id'));
        $this->assertSame('High', $alpharow->lastgrade);
        $this->assertSame(2, $alpharow->records);
        $this->assertSame($alpha->template->get('id'), (int) $alpharow->templateid);

        $this->assertSame($open->get('id'), $betarow->open->get('id'));
        $this->assertTrue($betarow->overdue);
        $this->assertNull($betarow->last);
        $this->assertNull($betarow->lastgrade);
        $this->assertSame(1, $betarow->records);

        $this->assertNull($deltarow->open);
        $this->assertFalse($deltarow->overdue);
        $this->assertSame($nograde->get('id'), $deltarow->last->get('id'));
        $this->assertNull($deltarow->lastgrade, 'No overall grade was given.');

        $this->assertSame($notdue->get('id'), $gammarow->open->get('id'));
        $this->assertFalse($gammarow->overdue, 'No due date.');

        // Overdue is strict: due exactly now is not overdue yet.
        $assignmentid = $open->get('assignmentid');
        $atdue = api::get_dashboard_rows((int) $this->ellie->id, $open->get('duedate'));
        $afterdue = api::get_dashboard_rows((int) $this->ellie->id, $open->get('duedate') + 1);
        $this->assertFalse($atdue[$assignmentid]->overdue);
        $this->assertTrue($afterdue[$assignmentid]->overdue);
    }

    /**
     * The latest completed cycle is the one with the latest completion date, not the last created: a later cycle signed
     * off with an earlier date does not replace it.
     *
     * @return void
     */
    public function test_the_last_completed_cycle_is_the_latest_completed(): void {
        global $DB;

        $built = $this->template('Annual');
        $first = $this->complete($this->assign($built, $this->ellie), $this->levelids[0]);
        $second = api::start_cycle($first->get('assignmentid'), (int) $this->alice->id);
        $second = $this->complete($second, $this->levelids[1]);
        // A backdated sign-off: the later cycle was completed on an earlier day than the first.
        $DB->set_field(
            'local_appraisal_record',
            'timecompleted',
            $first->get('timecompleted') - 2 * DAYSECS,
            ['id' => $second->get('id')]
        );
        $third = api::start_cycle($first->get('assignmentid'), (int) $this->alice->id);

        $rows = api::get_dashboard_rows((int) $this->ellie->id, $this->clock->time());

        $row = $rows[$first->get('assignmentid')];
        $this->assertSame($first->get('id'), $row->last->get('id'));
        $this->assertSame('Low', $row->lastgrade);
        $this->assertSame($third->get('id'), $row->open->get('id'));
        $this->assertSame(3, $row->records);
    }

    /**
     * The history lists only completed cycles of the user, newest first, with the template, label, grade and the
     * appraiser's name, and can be filtered by template and paged.
     *
     * @return void
     */
    public function test_completed_cycles(): void {
        $alpha = $this->template('Alpha');
        $beta = $this->template('Beta');
        $a1 = $this->assign($alpha, $this->ellie, null);
        $a1 = $this->complete($a1, $this->levelids[0]);
        $b1 = $this->complete($this->assign($beta, $this->ellie, null, $this->ben), $this->levelids[1]);
        $a2 = api::start_cycle($a1->get('assignmentid'), (int) $this->alice->id, 'Spring');
        $a2 = $this->complete($a2);
        $openbeta = api::start_cycle($b1->get('assignmentid'), (int) $this->ben->id);
        $gamma = $this->template('Gamma');
        $this->assign($gamma, $this->ellie);
        $this->complete($this->assign($this->template('Other'), $this->appraisee('Fred', 'Other')));

        $all = api::get_completed_cycles((int) $this->ellie->id, 0, 10);

        $this->assertSame(3, $all['total']);
        $this->assertSame([$a2->get('id'), $b1->get('id'), $a1->get('id')], array_map('intval', array_keys($all['rows'])));
        $rows = array_values($all['rows']);
        $this->assertSame('Alpha', $rows[0]->templatename);
        $this->assertSame('Spring', $rows[0]->label);
        $this->assertNull($rows[0]->gradelabel);
        $this->assertSame('Medium', $rows[1]->gradelabel);
        $this->assertSame('Low', $rows[2]->gradelabel);
        $this->assertSame((int) $this->alice->id, (int) $rows[0]->appraiserid);
        $this->assertSame('Alice', $rows[0]->appraiser_firstname);
        $this->assertSame('Ben', $rows[1]->appraiser_firstname);
        $this->assertSame($a2->get('timecompleted'), (int) $rows[0]->timecompleted);
        $this->assertArrayNotHasKey($openbeta->get('id'), $all['rows'], 'Open cycles are not history.');

        $onlyalpha = api::get_completed_cycles((int) $this->ellie->id, 0, 10, $alpha->template->get('id'));
        $this->assertSame(2, $onlyalpha['total']);
        $this->assertSame([$a2->get('id'), $a1->get('id')], array_map('intval', array_keys($onlyalpha['rows'])));

        $page1 = api::get_completed_cycles((int) $this->ellie->id, 0, 2);
        $page2 = api::get_completed_cycles((int) $this->ellie->id, 1, 2);
        $this->assertSame(3, $page1['total']);
        $this->assertSame(3, $page2['total']);
        $this->assertSame([$a2->get('id'), $b1->get('id')], array_map('intval', array_keys($page1['rows'])));
        $this->assertSame([$a1->get('id')], array_map('intval', array_keys($page2['rows'])));

        $none = api::get_completed_cycles((int) $this->alice->id, 0, 10);
        $this->assertSame(0, $none['total']);
        $this->assertSame([], $none['rows']);
        $this->assertSame(
            [$alpha->template->get('id') => 'Alpha', $beta->template->get('id') => 'Beta'],
            api::get_completed_templates((int) $this->ellie->id)
        );
        $this->assertSame([], api::get_completed_templates((int) $this->alice->id));
        $this->assertArrayNotHasKey($gamma->template->get('id'), api::get_completed_templates((int) $this->ellie->id));
        $this->assertSame(0, api::get_completed_cycles((int) $this->ellie->id, 0, 10, $gamma->template->get('id'))['total']);
    }

    /**
     * An appraiser whose account is gone still leaves the cycle in the history, with no name fields.
     *
     * @return void
     */
    public function test_completed_cycles_survive_a_removed_appraiser(): void {
        global $DB;

        $record = $this->complete($this->assign($this->template('Annual'), $this->ellie));
        $DB->set_field('local_appraisal_record', 'appraiserid', 0, ['id' => $record->get('id')]);

        $rows = array_values(api::get_completed_cycles((int) $this->ellie->id, 0, 10)['rows']);

        $this->assertCount(1, $rows);
        $this->assertSame(0, (int) $rows[0]->appraiserid);
        $this->assertNull($rows[0]->appraiser_firstname);
        $this->assertNull($rows[0]->appraiser_lastname);
    }

    /**
     * The counts per person: assignments, open cycles, those awaiting this viewer's sign-off, overdue ones and the
     * soonest due date; people with nothing get zeros, and awaiting counts only the viewer's own records.
     *
     * @return void
     */
    public function test_summarise_people(): void {
        $now = $this->clock->time();
        $alpha = $this->template('Alpha');
        $beta = $this->template('Beta');
        $fred = $this->appraisee('Fred', 'Submitted');
        $gina = $this->appraisee('Gina', 'Bens');
        $hal = $this->appraisee('Hal', 'Done');
        $ivy = $this->appraisee('Ivy', 'Nothing');

        $this->assign($alpha, $this->ellie, $now - 2 * DAYSECS);
        $this->assign($beta, $this->ellie, $now + 9 * DAYSECS);
        $this->assign($this->template('Gamma'), $this->ellie, $now - DAYSECS);
        $fredrecord = $this->assign($alpha, $fred, $now + 3 * DAYSECS);
        $ginarecord = $this->assign($alpha, $gina, null, $this->ben);
        $this->complete($this->assign($alpha, $hal, $now - 5 * DAYSECS));
        api::submit_part($fredrecord->get('id'), (int) $fred->id);
        api::submit_part($ginarecord->get('id'), (int) $gina->id);

        $ids = [(int) $this->ellie->id, (int) $fred->id, (int) $gina->id, (int) $hal->id, (int) $ivy->id];
        $summary = api::summarise_people($ids, (int) $this->alice->id, $now);

        $this->assertSame($ids, array_keys($summary));
        $this->assertEquals(
            (object) ['assignments' => 3, 'open' => 3, 'awaiting' => 0, 'overdue' => 2, 'nextdue' => $now - 2 * DAYSECS],
            $summary[$this->ellie->id]
        );
        $this->assertEquals(
            (object) ['assignments' => 1, 'open' => 1, 'awaiting' => 1, 'overdue' => 0, 'nextdue' => $now + 3 * DAYSECS],
            $summary[$fred->id]
        );
        $this->assertEquals(
            (object) ['assignments' => 1, 'open' => 1, 'awaiting' => 0, 'overdue' => 0, 'nextdue' => null],
            $summary[$gina->id],
            'Submitted, but Ben owns it, so it is not Alice to sign off.'
        );
        $this->assertEquals(
            (object) ['assignments' => 1, 'open' => 0, 'awaiting' => 0, 'overdue' => 0, 'nextdue' => null],
            $summary[$hal->id],
            'A completed cycle is neither open nor overdue.'
        );
        $this->assertEquals(
            (object) ['assignments' => 0, 'open' => 0, 'awaiting' => 0, 'overdue' => 0, 'nextdue' => null],
            $summary[$ivy->id]
        );

        $forben = api::summarise_people($ids, (int) $this->ben->id, $now);
        $this->assertSame(1, $forben[$gina->id]->awaiting);
        $this->assertSame(0, $forben[$fred->id]->awaiting);

        $this->assertSame([], api::summarise_people([], (int) $this->alice->id, $now));
    }

    /**
     * Overdue is strict: a cycle due exactly now is not yet overdue.
     *
     * @return void
     */
    public function test_a_cycle_due_now_is_not_overdue(): void {
        $now = $this->clock->time();
        $this->assign($this->template('Alpha'), $this->ellie, $now);

        $at = api::summarise_people([(int) $this->ellie->id], (int) $this->alice->id, $now);
        $after = api::summarise_people([(int) $this->ellie->id], (int) $this->alice->id, $now + 1);

        $this->assertSame(0, $at[$this->ellie->id]->overdue);
        $this->assertSame(1, $after[$this->ellie->id]->overdue);
    }

    /**
     * Three people for the filter and ordering tests: names, open, awaiting, overdue and next due.
     *
     * @return \stdClass[] Keyed by a label.
     */
    private function people(): array {
        $person = fn(string $name, int $open, int $awaiting, int $overdue, ?int $nextdue): \stdClass => (object) [
            'fullname' => $name, 'open' => $open, 'awaiting' => $awaiting, 'overdue' => $overdue, 'nextdue' => $nextdue,
        ];
        return [
            'calm' => $person('Calm Quiet', 0, 0, 0, null),
            'soon' => $person('Soon Due', 1, 0, 0, 1000),
            'later' => $person('Later Due', 1, 0, 0, 5000),
            'awaiting' => $person('Awaiting Sign', 1, 1, 0, 9000),
            'overdue' => $person('Overdue Late', 1, 0, 2, 100),
            'both' => $person('Both Things', 2, 1, 1, 50),
            'open' => $person('Aaron Open', 1, 0, 0, null),
        ];
    }

    /**
     * The names of a filtered, ordered list.
     *
     * @param string $filter
     * @return string[]
     */
    private function ordered(string $filter): array {
        return array_column(api::filter_and_sort_people($this->people(), $filter), 'fullname');
    }

    /**
     * The order is overdue first (more overdue first), then awaiting sign-off, then soonest due with people with
     * nothing due last, then by name.
     *
     * @return void
     */
    public function test_people_are_ordered_by_urgency(): void {
        $this->assertSame(
            [
                'Overdue Late', 'Both Things', 'Awaiting Sign', 'Soon Due', 'Later Due', 'Aaron Open', 'Calm Quiet',
            ],
            $this->ordered('all')
        );
    }

    /**
     * Needs attention keeps the overdue and the awaiting; Open keeps anyone with an open cycle; anything else keeps
     * everyone.
     *
     * @param string $filter
     * @param string[] $expected
     * @return void
     */
    #[DataProvider('filter_provider')]
    public function test_people_filters(string $filter, array $expected): void {
        $this->assertEqualsCanonicalizing($expected, $this->ordered($filter));
    }

    /**
     * Filters and who each keeps.
     *
     * @return array
     */
    public static function filter_provider(): array {
        $everyone = [
            'Overdue Late', 'Both Things', 'Awaiting Sign', 'Soon Due', 'Later Due', 'Aaron Open', 'Calm Quiet',
        ];
        return [
            'attention' => ['attention', ['Overdue Late', 'Both Things', 'Awaiting Sign']],
            'open' => ['open', array_values(array_diff($everyone, ['Calm Quiet']))],
            'all' => ['all', $everyone],
            'unknown' => ['nonsense', $everyone],
            'empty' => ['', $everyone],
        ];
    }

    /**
     * People who tie on urgency are ordered by name, and the list is re-indexed.
     *
     * @return void
     */
    public function test_ties_are_ordered_by_name(): void {
        $make = fn(string $name): \stdClass => (object) [
            'fullname' => $name, 'open' => 1, 'awaiting' => 0, 'overdue' => 0, 'nextdue' => 700,
        ];
        $sorted = api::filter_and_sort_people(['x' => $make('Zoe'), 'y' => $make('Adam'), 'z' => $make('Mia')], 'all');

        $this->assertSame([0, 1, 2], array_keys($sorted));
        $this->assertSame(['Adam', 'Mia', 'Zoe'], array_column($sorted, 'fullname'));
        $this->assertSame([], api::filter_and_sort_people([], 'attention'));
    }
}
