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

namespace local_appraisal\privacy;

use core\context\system;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use local_appraisal\api;
use local_appraisal\record;
use local_appraisal\record_answer;
use local_appraisal\template_field;
use local_appraisal\template_version;
use local_appraisal_generator;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the privacy provider: the metadata, which users and contexts hold data, what each kind of person
 * exports (the appraisee sees nothing the plugin hides from them), and what each kind of deletion removes or
 * anonymises.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(provider::class)]
final class provider_test extends provider_testcase {
    /** @var local_appraisal_generator The plugin's data generator. */
    private local_appraisal_generator $gen;

    /** @var \stdClass The appraisee of the main record. */
    private \stdClass $ellie;

    /** @var \stdClass The appraiser who starts the main record. */
    private \stdClass $alice;

    /** @var \stdClass A second appraiser, who later takes ownership. */
    private \stdClass $ben;

    /** @var \stdClass A second appraisee, whose record Alice owns. */
    private \stdClass $fay;

    /** @var \stdClass Someone with no part in any appraisal. */
    private \stdClass $uma;

    /** @var \stdClass The author of the template and the scale. */
    private \stdClass $tim;

    /** @var \stdClass The generated template: template, version and fields keyed by label. */
    private \stdClass $built;

    /** @var \local_appraisal\scale The overall grade scale. */
    private \local_appraisal\scale $scale;

    /** @var record Ellie's cycle, owned by Alice. */
    private record $record;

    /**
     * Build the people, a cohort link and one published template with an overall grade.
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
        $this->gen = $this->getDataGenerator()->get_plugin_generator('local_appraisal');

        $names = ['ellie', 'alice', 'ben', 'fay', 'uma', 'tim'];
        foreach ($names as $name) {
            $this->{$name} = $this->getDataGenerator()->create_user(['firstname' => ucfirst($name), 'lastname' => 'Test']);
        }
        $appraisers = $this->getDataGenerator()->create_cohort();
        $appraisees = $this->getDataGenerator()->create_cohort();
        cohort_add_member($appraisers->id, $this->alice->id);
        cohort_add_member($appraisers->id, $this->ben->id);
        cohort_add_member($appraisees->id, $this->ellie->id);
        cohort_add_member($appraisees->id, $this->fay->id);

        $this->setUser($this->tim);
        $this->gen->create_cohort_link([
            'appraisercohortid' => $appraisers->id,
            'appraiseecohortid' => $appraisees->id,
        ]);
        $this->scale = $this->gen->create_scale(['name' => 'Rating', 'levels' => ['Low' => 10, 'Good' => 20, 'Great' => 30]]);
        $this->built = $this->gen->create_template([
            'name' => 'Review',
            'commentsmode' => template_version::COMMENTS_OPTIONAL,
            'gradingmode' => template_version::GRADE_REQUIRED,
            'overallscaleid' => $this->scale->get('id'),
            'fields' => [
                ['label' => 'Manager note', 'completedby' => template_field::COMPLETEDBY_APPRAISER],
                ['label' => 'Self review', 'completedby' => template_field::COMPLETEDBY_APPRAISEE],
            ],
        ]);

        $this->setUser($this->alice);
        $this->record = $this->gen->create_appraisal([
            'templateid' => $this->built->template->get('id'),
            'userid' => $this->ellie->id,
            'appraiserid' => $this->alice->id,
            'label' => 'Spring',
        ]);
    }

    /**
     * Save one field of a record as the person whose side it is.
     *
     * @param record $record
     * @param string $label The field's label.
     * @param \stdClass $user The saver.
     * @param string $value
     * @return void
     */
    private function answer(record $record, string $label, \stdClass $user, string $value): void {
        $field = $this->built->fields[$label];
        $role = $field->get('completedby') === template_field::COMPLETEDBY_APPRAISEE
            ? record_answer::ROLE_APPRAISEE
            : record_answer::ROLE_APPRAISER;
        api::save_answer($record->get('id'), $field->get('id'), $role, (int) $user->id, $value);
    }

    /**
     * Both people answer, Ellie submits, Ben takes ownership and signs off with the overall grade.
     *
     * @return void
     */
    private function complete_main_record(): void {
        $id = $this->record->get('id');
        $this->answer($this->record, 'Self review', $this->ellie, 'I enjoyed the year');
        $this->answer($this->record, 'Manager note', $this->alice, 'Needs more delegation');
        api::submit_part($id, (int) $this->ellie->id);
        api::save_comment($id, 'Well done overall', (int) $this->alice->id);
        api::take_ownership($id, (int) $this->ben->id);
        $levels = array_values(api::get_scale_levels($this->scale->get('id')));
        api::sign_off($id, (int) $this->ben->id, $levels[1]->get('id'));
    }

    /**
     * A second appraisee, Fay, with a record owned by Alice and an answer Alice saved.
     *
     * @return record
     */
    private function create_fay_record(): record {
        $this->setUser($this->alice);
        $fay = $this->gen->create_appraisal([
            'templateid' => $this->built->template->get('id'),
            'userid' => $this->fay->id,
            'appraiserid' => $this->alice->id,
            'label' => 'Fay cycle',
        ]);
        $this->answer($fay, 'Manager note', $this->alice, 'Fay is steady');
        return $fay;
    }

    /**
     * Assign the template to Fay and touch nothing: no answer, no status change.
     *
     * @return record
     */
    private function create_fay_record_untouched(): record {
        $this->setUser($this->alice);
        return $this->gen->create_appraisal([
            'templateid' => $this->built->template->get('id'),
            'userid' => $this->fay->id,
            'appraiserid' => $this->alice->id,
        ]);
    }

    /**
     * Export a user's data and read back one subcontext.
     *
     * @param \stdClass $user
     * @param string[] $path The subcontext below the plugin's folder.
     * @return \stdClass|null
     */
    private function export(\stdClass $user, array $path): ?\stdClass {
        writer::reset();
        $this->export_all_data_for_user((int) $user->id, 'local_appraisal');
        $subcontext = array_merge([get_string('pluginname', 'local_appraisal')], $path);
        $data = writer::with_context(system::instance())->get_data($subcontext);
        return $data ?: null;
    }

    /**
     * The subcontext of Ellie's main record in her own export.
     *
     * @return string[]
     */
    private function appraisee_path(): array {
        return [
            get_string('privacyasappraisee', 'local_appraisal'),
            'Review (' . $this->record->get('assignmentid') . ')',
            'Spring (' . $this->record->get('id') . ')',
        ];
    }

    /**
     * The labels of the exported answers.
     *
     * @param \stdClass $data
     * @return string[]
     */
    private function answer_fields(\stdClass $data): array {
        return array_map(fn(\stdClass $answer): string => $answer->field, $data->answers);
    }

    /**
     * Every table is described, every personal column has a string, and the messaging link is present.
     *
     * @return void
     */
    public function test_metadata_describes_every_table_and_personal_column(): void {
        global $DB;

        $collection = provider::get_metadata(new collection('local_appraisal'));
        $items = $collection->get_collection();
        $tables = [];
        foreach ($items as $item) {
            if ($item instanceof \core_privacy\local\metadata\types\database_table) {
                $tables[$item->get_name()] = $item;
                foreach ($item->get_privacy_fields() as $field => $stringkey) {
                    $this->assertTrue(
                        get_string_manager()->string_exists($stringkey, 'local_appraisal'),
                        "Missing string {$stringkey} for {$item->get_name()}.{$field}"
                    );
                }
                $this->assertTrue(get_string_manager()->string_exists($item->get_summary(), 'local_appraisal'));
            }
        }
        $installed = array_filter(
            array_keys($DB->get_tables(false)),
            fn(string $table): bool => str_starts_with($table, 'local_appraisal_')
        );
        $this->assertEqualsCanonicalizing($installed, array_keys($tables));

        foreach (['assignment', 'record', 'record_answer', 'record_transfer', 'record_history'] as $short) {
            $table = 'local_appraisal_' . $short;
            $expected = array_diff(array_keys($DB->get_columns($table)), ['id', 'commentformat']);
            $this->assertEqualsCanonicalizing($expected, array_keys($tables[$table]->get_privacy_fields()), $table);
        }
        foreach (['scale', 'template', 'template_field', 'cohort_link'] as $short) {
            $this->assertArrayHasKey('usermodified', $tables['local_appraisal_' . $short]->get_privacy_fields());
        }
        $links = array_filter($items, fn(\core_privacy\local\metadata\types\type $item): bool => $item instanceof
                \core_privacy\local\metadata\types\subsystem_link);
        $this->assertCount(1, $links);
    }

    /**
     * The system context is listed for everyone the plugin names, and for nobody else.
     *
     * @return void
     */
    public function test_contexts_for_users_with_and_without_data(): void {
        $this->complete_main_record();
        $syscontext = system::instance();
        foreach (['ellie', 'alice', 'ben', 'tim'] as $name) {
            $contextlist = provider::get_contexts_for_userid((int) $this->{$name}->id);
            $this->assertEquals([$syscontext->id], $contextlist->get_contextids(), $name);
        }
        $this->assertEmpty(provider::get_contexts_for_userid((int) $this->uma->id)->get_contextids());
        $this->assertEmpty(provider::get_contexts_for_userid((int) $this->fay->id)->get_contextids());
        $this->assertEmpty(provider::get_contexts_for_userid(0)->get_contextids());
    }

    /**
     * An appraisee whose only trace is an assignment with a cycle nobody has touched is still found, in both lists.
     *
     * @return void
     */
    public function test_an_untouched_assignment_is_found(): void {
        $this->create_fay_record_untouched();
        $this->assertEquals(
            [system::instance()->id],
            provider::get_contexts_for_userid((int) $this->fay->id)->get_contextids()
        );
        $userlist = new userlist(system::instance(), 'local_appraisal');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $this->fay->id, array_map('intval', $userlist->get_userids()));
    }

    /**
     * Every column of the schema that references a user is covered by the provider's list, so adding one without
     * updating the provider fails here. The appraisee column is the only one deleted instead of anonymised.
     *
     * @return void
     */
    public function test_user_columns_match_the_schema(): void {
        global $CFG;

        $xml = new \DOMDocument();
        $xml->load($CFG->dirroot . '/local/appraisal/db/install.xml');
        $found = [];
        foreach ($xml->getElementsByTagName('TABLE') as $table) {
            foreach ($table->getElementsByTagName('KEY') as $key) {
                if ($key->getAttribute('TYPE') === 'foreign' && $key->getAttribute('REFTABLE') === 'user') {
                    $found[$table->getAttribute('NAME')][] = $key->getAttribute('FIELDS');
                }
            }
        }
        $covered = (new \ReflectionClass(provider::class))->getConstant('USER_COLUMNS');
        $covered['local_appraisal_assignment'][] = 'userid';
        foreach ($found as $table => $columns) {
            $this->assertEqualsCanonicalizing($columns, $covered[$table] ?? [], $table);
        }
        // The list has no column the schema lacks, and the history actor (a nullable column) is in it.
        $this->assertEqualsCanonicalizing(array_keys($found), array_keys($covered));
    }

    /**
     * The user list holds the appraisee, the appraisers, the actors and the author, and only them.
     *
     * @return void
     */
    public function test_users_in_context(): void {
        $this->complete_main_record();
        $userlist = new userlist(system::instance(), 'local_appraisal');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing(
            [$this->ellie->id, $this->alice->id, $this->ben->id, $this->tim->id],
            $userlist->get_userids()
        );

        $other = new userlist(\core\context\user::instance($this->uma->id), 'local_appraisal');
        provider::get_users_in_context($other);
        $this->assertEmpty($other->get_userids());
    }

    /**
     * Before the appraisee submits, their export holds their own answer only: no appraiser answer, comment or grade.
     *
     * @return void
     */
    public function test_appraisee_export_hides_what_the_screen_hides(): void {
        $this->answer($this->record, 'Self review', $this->ellie, 'I enjoyed the year');
        $this->answer($this->record, 'Manager note', $this->alice, 'Needs more delegation');
        api::save_comment($this->record->get('id'), 'Private for now', (int) $this->alice->id);

        $data = $this->export($this->ellie, $this->appraisee_path());
        $this->assertNotNull($data);
        $this->assertSame('Review', $data->template);
        $this->assertSame('Spring', $data->label);
        $this->assertSame(['Self review'], $this->answer_fields($data));
        $this->assertSame('I enjoyed the year', $data->answers[0]->answer);
        $this->assertNull($data->overallcomments);
        $this->assertNull($data->overallgrade);
        $this->assertStringNotContainsString('Needs more delegation', json_encode($data));
        $this->assertStringNotContainsString('Private for now', json_encode($data));
        $this->assertSame('Alice Test', $data->appraiser);
    }

    /**
     * After submission the appraiser's answers and comments are exported as words, and after sign-off so is the grade.
     *
     * @return void
     */
    public function test_appraisee_export_once_visible_uses_words(): void {
        $this->complete_main_record();
        $data = $this->export($this->ellie, $this->appraisee_path());

        $this->assertEqualsCanonicalizing(['Self review', 'Manager note'], $this->answer_fields($data));
        $this->assertStringContainsString('Needs more delegation', json_encode($data));
        $this->assertSame('Well done overall', $data->overallcomments);
        $this->assertSame('Good', $data->overallgrade);
        $this->assertSame(get_string('statuscompleted', 'local_appraisal'), $data->status);
        $this->assertNotNull($data->timecompleted);
        $this->assertStringNotContainsString('20', (string) $data->overallgrade);
        // The owner moved from Alice to Ben, and the appraisee may see who.
        $this->assertSame('Ben Test', $data->appraiser);
        $this->assertCount(1, $data->transfers);
        $this->assertSame('Alice Test', $data->transfers[0]->from);
        $this->assertSame('Ben Test', $data->transfers[0]->to);
        $this->assertNotEmpty($data->history);
    }

    /**
     * Once Ellie has submitted, the appraiser's answers and comments are in her export, though the cycle is not
     * Completed, and the grade is still absent.
     *
     * @return void
     */
    public function test_appraisee_export_once_submitted_before_sign_off(): void {
        $id = $this->record->get('id');
        $this->answer($this->record, 'Self review', $this->ellie, 'I enjoyed the year');
        $this->answer($this->record, 'Manager note', $this->alice, 'Needs more delegation');
        api::save_comment($id, 'Well done overall', (int) $this->alice->id);
        api::submit_part($id, (int) $this->ellie->id);

        $data = $this->export($this->ellie, $this->appraisee_path());
        $this->assertEqualsCanonicalizing(['Self review', 'Manager note'], $this->answer_fields($data));
        $this->assertSame('Well done overall', $data->overallcomments);
        $this->assertNull($data->overallgrade);
        $this->assertSame(get_string('statussubmitted', 'local_appraisal'), $data->status);
    }

    /**
     * An appraiser's export holds the answers they saved and names the appraisee by user id only.
     *
     * @return void
     */
    public function test_appraiser_export_holds_what_they_saved_and_the_appraisee_by_id(): void {
        $this->complete_main_record();
        $path = [
            get_string('privacyasappraiser', 'local_appraisal'),
            'Review (' . $this->record->get('id') . ')',
        ];

        // Alice saved the Manager note and then handed over ownership.
        $alice = $this->export($this->alice, $path);
        $this->assertSame((int) $this->ellie->id, $alice->appraiseeid);
        $this->assertSame(['Manager note'], $this->answer_fields($alice));
        $this->assertFalse($alice->isowner);
        $this->assertNull($alice->overallcomments);
        $this->assertCount(1, $alice->transfers);
        $this->assertSame((int) $this->alice->id, $alice->transfers[0]->fromid);
        $this->assertStringNotContainsString('Ellie', json_encode($alice));
        $this->assertStringNotContainsString('I enjoyed the year', json_encode($alice));

        // Ben owns it now, saved no answer and wrote no comment, but is the owner and acted on it.
        $ben = $this->export($this->ben, $path);
        $this->assertTrue($ben->isowner);
        $this->assertSame([], $ben->answers);
        $this->assertNotEmpty($ben->history);
    }

    /**
     * Someone's own appraisal is exported in the appraisee folder only, and an appraiser sees none of Ellie's folder.
     *
     * @return void
     */
    public function test_appraisee_has_no_appraiser_folder_for_their_own_record(): void {
        $this->complete_main_record();
        $path = [
            get_string('privacyasappraiser', 'local_appraisal'),
            'Review (' . $this->record->get('id') . ')',
        ];
        $this->assertNull($this->export($this->ellie, $path));
        $this->assertNull($this->export($this->alice, $this->appraisee_path()));
    }

    /**
     * An author exports what they last changed.
     *
     * @return void
     */
    public function test_author_export(): void {
        $data = $this->export($this->tim, [get_string('privacyasauthor', 'local_appraisal')]);
        $this->assertNotNull($data);
        $types = array_unique(array_map(fn(\stdClass $item): string => $item->type, $data->items));
        $this->assertContains('scale', $types);
        $this->assertContains('template', $types);
        $this->assertContains('template_field', $types);
        $names = array_map(fn(\stdClass $item): string => $item->name, $data->items);
        $this->assertContains('Rating', $names);
        $this->assertContains('Review', $names);
        $this->assertNull($this->export($this->uma, [get_string('privacyasauthor', 'local_appraisal')]));
    }

    /**
     * A user with no data exports nothing, and a request for another context exports nothing.
     *
     * @return void
     */
    public function test_export_for_a_user_without_data_writes_nothing(): void {
        $this->complete_main_record();
        writer::reset();
        $contextlist = new approved_contextlist($this->uma, 'local_appraisal', []);
        provider::export_user_data($contextlist);
        $this->assertFalse(writer::with_context(system::instance())->has_any_data());

        $usercontext = \core\context\user::instance($this->ellie->id);
        $contextlist = new approved_contextlist($this->ellie, 'local_appraisal', [$usercontext->id]);
        provider::export_user_data($contextlist);
        $this->assertFalse(writer::with_context(system::instance())->has_any_data());
    }

    /**
     * Deleting an appraisee removes their assignment, cycles, answers, history and transfers and no one else's, and
     * logs no event.
     *
     * @return void
     */
    public function test_delete_appraisee_removes_their_appraisals_only(): void {
        global $DB;

        $this->complete_main_record();
        $fay = $this->create_fay_record();
        $sink = $this->redirectEvents();

        $this->delete_for_user($this->ellie);

        $this->assertFalse($DB->record_exists('local_appraisal_assignment', ['userid' => $this->ellie->id]));
        $this->assertFalse($DB->record_exists('local_appraisal_record', ['id' => $this->record->get('id')]));
        foreach (['record_answer', 'record_history', 'record_transfer'] as $table) {
            $this->assertFalse($DB->record_exists('local_appraisal_' . $table, ['recordid' => $this->record->get('id')]), $table);
        }
        // Fay's cycle and the configuration are untouched.
        $this->assertTrue($DB->record_exists('local_appraisal_record', ['id' => $fay->get('id')]));
        $this->assertTrue($DB->record_exists('local_appraisal_record_answer', ['recordid' => $fay->get('id')]));
        $this->assertTrue($DB->record_exists('local_appraisal_template', ['id' => $this->built->template->get('id')]));
        $this->assertTrue($DB->record_exists('local_appraisal_scale', ['id' => $this->scale->get('id')]));
        $this->assertEmpty(array_filter(
            $sink->get_events(),
            fn(\core\event\base $event): bool => $event instanceof \local_appraisal\event\assignment_deleted
        ));
        $this->assertEmpty(provider::get_contexts_for_userid((int) $this->ellie->id)->get_contextids());
    }

    /**
     * Deleting an appraiser keeps the appraisals and replaces them with user id 0 wherever they were named.
     *
     * @return void
     */
    public function test_delete_appraiser_anonymises(): void {
        global $DB;

        $this->complete_main_record();
        $fay = $this->create_fay_record();
        $alice = (int) $this->alice->id;

        $this->delete_for_user($this->alice);

        $faystored = $DB->get_record('local_appraisal_record', ['id' => $fay->get('id')], '*', MUST_EXIST);
        $this->assertEquals(api::APPRAISER_DELETED, $faystored->appraiserid);
        $this->assertEquals(api::APPRAISER_DELETED, $faystored->usermodified);
        $answers = $DB->get_records('local_appraisal_record_answer', ['recordid' => $fay->get('id')]);
        $this->assertCount(1, $answers);
        $this->assertEquals('Fay is steady', reset($answers)->valuetext);
        $this->assertEquals(0, reset($answers)->userid);
        $this->assertFalse($DB->record_exists('local_appraisal_record_history', ['actorid' => $alice]));
        $this->assertFalse($DB->record_exists('local_appraisal_record_transfer', ['fromappraiserid' => $alice]));
        $this->assertFalse($DB->record_exists('local_appraisal_record_answer', ['userid' => $alice]));
        $this->assertFalse($DB->record_exists('local_appraisal_assignment', ['usermodified' => $alice]));
        // Ellie's completed cycle stays, with Ben as owner, and its history is still there.
        $this->assertTrue($DB->record_exists('local_appraisal_record', ['id' => $this->record->get('id')]));
        $this->assertTrue($DB->record_exists('local_appraisal_assignment', ['userid' => $this->ellie->id]));
        $this->assertEmpty(provider::get_contexts_for_userid($alice)->get_contextids());
        $this->assertNotEmpty(api::get_history($this->record->get('id')));
        $this->assert_no_user_column_holds($alice);
    }

    /**
     * An anonymised appraiser shows as "Deleted user" in the appraisee's export.
     *
     * @return void
     */
    public function test_export_after_the_appraiser_is_deleted(): void {
        $id = $this->record->get('id');
        $this->answer($this->record, 'Self review', $this->ellie, 'Fine');
        $this->delete_for_user($this->alice);

        $data = $this->export($this->ellie, $this->appraisee_path());
        $this->assertSame(get_string('deleteduser', 'local_appraisal'), $data->appraiser);
        $this->assertSame(['Self review'], $this->answer_fields($data));
        $this->assertSame(0, (int) record::get_record(['id' => $id])->get('appraiserid'));
    }

    /**
     * Deleting an author keeps the template, scale and link and clears who changed them.
     *
     * @return void
     */
    public function test_delete_author_anonymises_configuration(): void {
        global $DB;

        $this->delete_for_user($this->tim);

        foreach (
            [
                'local_appraisal_scale', 'local_appraisal_scale_level', 'local_appraisal_template',
                'local_appraisal_template_version', 'local_appraisal_template_field', 'local_appraisal_cohort_link',
            ] as $table
        ) {
            $this->assertTrue($DB->record_exists($table, []), $table);
            $this->assertFalse($DB->record_exists($table, ['usermodified' => $this->tim->id]), $table);
        }
        $this->assertEmpty(provider::get_contexts_for_userid((int) $this->tim->id)->get_contextids());
        $this->assert_no_user_column_holds((int) $this->tim->id);
    }

    /**
     * Assert that no user-id column of the plugin still holds the user, read from the provider's own list.
     *
     * @param int $userid
     * @return void
     */
    private function assert_no_user_column_holds(int $userid): void {
        global $DB;

        $columns = (new \ReflectionClass(provider::class))->getConstant('USER_COLUMNS');
        foreach ($columns as $table => $names) {
            foreach ($names as $name) {
                $this->assertFalse($DB->record_exists($table, [$name => $userid]), "{$table}.{$name}");
            }
        }
    }

    /**
     * Deleting several users at once uses the same rules.
     *
     * @return void
     */
    public function test_delete_data_for_users(): void {
        global $DB;

        $this->complete_main_record();
        $fay = $this->create_fay_record();
        $userlist = new approved_userlist(system::instance(), 'local_appraisal', [$this->ellie->id, $this->alice->id]);
        provider::delete_data_for_users($userlist);

        $this->assertFalse($DB->record_exists('local_appraisal_assignment', ['userid' => $this->ellie->id]));
        $this->assertEquals(0, $DB->get_field('local_appraisal_record', 'appraiserid', ['id' => $fay->get('id')]));
        $this->assertTrue($DB->record_exists('local_appraisal_record', ['id' => $fay->get('id')]));

        // Another context, or no users, changes nothing.
        $before = $DB->count_records('local_appraisal_record');
        $other = new approved_userlist(\core\context\user::instance($this->uma->id), 'local_appraisal', [$this->fay->id]);
        provider::delete_data_for_users($other);
        provider::delete_data_for_users(new approved_userlist(system::instance(), 'local_appraisal', []));
        $this->assertEquals($before, $DB->count_records('local_appraisal_record'));
    }

    /**
     * A site-wide delete removes every appraisal and keeps templates, scales, levels and links, authorship included.
     *
     * @return void
     */
    public function test_delete_all_users_in_context_keeps_configuration(): void {
        global $DB;

        $this->complete_main_record();
        $this->create_fay_record();

        provider::delete_data_for_all_users_in_context(\core\context\user::instance($this->uma->id));
        $this->assertEquals(2, $DB->count_records('local_appraisal_assignment'));

        provider::delete_data_for_all_users_in_context(system::instance());
        foreach (['assignment', 'record', 'record_answer', 'record_history', 'record_transfer'] as $table) {
            $this->assertEquals(0, $DB->count_records('local_appraisal_' . $table), $table);
        }
        foreach (['scale', 'scale_level', 'template', 'template_version', 'template_field', 'cohort_link'] as $table) {
            $this->assertTrue($DB->record_exists('local_appraisal_' . $table, []), $table);
        }
        $this->assertTrue($DB->record_exists('local_appraisal_template', ['usermodified' => $this->tim->id]));
    }

    /**
     * Run the provider's deletion for one user in the system context.
     *
     * @param \stdClass $user
     * @return void
     */
    private function delete_for_user(\stdClass $user): void {
        $contextlist = new approved_contextlist($user, 'local_appraisal', [system::instance()->id]);
        provider::delete_data_for_user($contextlist);
    }
}
