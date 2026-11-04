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
use local_appraisal\template_field;
use local_appraisal_generator;
use moodle_exception;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the web service that stores a new layout for a draft template version's sections and fields.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(reorder_fields::class)]
final class reorder_fields_test extends \advanced_testcase {
    /** @var local_appraisal_generator The plugin's data generator. */
    private local_appraisal_generator $gen;

    /** @var \stdClass The generated draft template: fields A, B and C, with no sections. */
    private \stdClass $built;

    /** @var int The draft version's id. */
    private int $versionid;

    /**
     * Build a draft template and sign in as a template manager.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->gen = $this->getDataGenerator()->get_plugin_generator('local_appraisal');
        $this->built = $this->gen->create_template([
            'publish' => false,
            'fields' => [['label' => 'A'], ['label' => 'B'], ['label' => 'C']],
        ]);
        $this->versionid = $this->built->version->get('id');

        $manager = $this->getDataGenerator()->create_user();
        $this->gen->set_user_capability((int) $manager->id, 'local/appraisal:managetemplates');
        $this->setUser($manager);
    }

    /**
     * The id of a generated field.
     *
     * @param string $label
     * @return int
     */
    private function fieldid(string $label): int {
        return $this->built->fields[$label]->get('id');
    }

    /**
     * The field labels in their stored order.
     *
     * @return string[]
     */
    private function labels(): array {
        return array_values(array_map(
            fn(template_field $field): string => $field->get('label'),
            api::get_fields($this->versionid)
        ));
    }

    /**
     * The function is registered for the plugin's own AJAX calls only, as a write, needing managetemplates.
     *
     * @return void
     */
    public function test_registered_as_an_ajax_only_write(): void {
        $info = external_api::external_function_info('local_appraisal_reorder_fields');

        $this->assertTrue($info->allowed_from_ajax);
        $this->assertSame('write', $info->type);
        $this->assertSame(reorder_fields::class, $info->classname);
        $this->assertSame('local/appraisal:managetemplates', $info->capabilities);
    }

    /**
     * A manager reorders a version that has no sections, with a single entry whose id is 0.
     *
     * @return void
     */
    public function test_manager_reorders_fields(): void {
        $sections = [['id' => 0, 'fieldids' => [$this->fieldid('C'), $this->fieldid('A'), $this->fieldid('B')]]];

        $result = reorder_fields::execute($this->versionid, $sections);

        $this->assertNull($result);
        $this->assertNull(reorder_fields::execute_returns(), 'The service returns nothing.');
        $this->assertSame(['C', 'A', 'B'], $this->labels());
        foreach (api::get_fields($this->versionid) as $field) {
            $this->assertNull($field->get('sectionid'), 'Without sections the field has no section, not section 0.');
        }
    }

    /**
     * A manager moves a field between sections and reorders the sections themselves.
     *
     * @return void
     */
    public function test_manager_reorders_sections_and_moves_fields(): void {
        $one = api::add_section($this->versionid, 'One');
        $two = api::add_section($this->versionid, 'Two');
        api::update_field($this->fieldid('C'), 1, 'C', 1, true, $two->get('id'));

        reorder_fields::execute($this->versionid, [
            ['id' => $two->get('id'), 'fieldids' => [$this->fieldid('C'), $this->fieldid('A')]],
            ['id' => $one->get('id'), 'fieldids' => [$this->fieldid('B')]],
        ]);

        $this->assertSame(['C', 'A', 'B'], $this->labels());
        $this->assertSame(['Two', 'One'], array_values(array_map(
            fn(\local_appraisal\template_section $section): string => $section->get('name'),
            api::get_sections($this->versionid)
        )));
        $this->assertSame(
            $two->get('id'),
            template_field::get_record(['id' => $this->fieldid('A')])->get('sectionid')
        );
    }

    /**
     * Without the capability, nothing changes.
     *
     * @return void
     */
    public function test_user_without_the_capability_is_refused(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            reorder_fields::execute($this->versionid, [
                ['id' => 0, 'fieldids' => [$this->fieldid('C'), $this->fieldid('B'), $this->fieldid('A')]],
            ]);
            $this->fail('A user without managetemplates should be refused.');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $this->assertSame(['A', 'B', 'C'], $this->labels());
    }

    /**
     * Nobody signed in is refused.
     *
     * @return void
     */
    public function test_not_signed_in_is_refused(): void {
        $this->setUser(0);

        $this->expectException(\require_login_exception::class);
        reorder_fields::execute($this->versionid, [
            ['id' => 0, 'fieldids' => [$this->fieldid('C'), $this->fieldid('B'), $this->fieldid('A')]],
        ]);
    }

    /**
     * The guest is refused and nothing changes.
     *
     * @return void
     */
    public function test_guest_is_refused(): void {
        $this->setGuestUser();

        try {
            reorder_fields::execute($this->versionid, [
                ['id' => 0, 'fieldids' => [$this->fieldid('C'), $this->fieldid('B'), $this->fieldid('A')]],
            ]);
            $this->fail('The guest should be refused.');
        } catch (\Exception $e) {
            $this->assertTrue($e instanceof \require_login_exception || $e instanceof \required_capability_exception);
        }
        $this->assertSame(['A', 'B', 'C'], $this->labels());
    }

    /**
     * Only a draft can be reordered: a published version is refused and keeps its order.
     *
     * @return void
     */
    public function test_a_published_version_is_refused(): void {
        api::publish_version($this->versionid);

        try {
            reorder_fields::execute($this->versionid, [
                ['id' => 0, 'fieldids' => [$this->fieldid('C'), $this->fieldid('B'), $this->fieldid('A')]],
            ]);
            $this->fail('A published version should be refused.');
        } catch (moodle_exception $e) {
            $this->assertSame('error:notdraft', $e->errorcode);
        }
        $this->assertSame(['A', 'B', 'C'], $this->labels());
    }

    /**
     * An archived version is refused too.
     *
     * @return void
     */
    public function test_an_archived_version_is_refused(): void {
        api::publish_version($this->versionid);
        $draft = api::create_draft($this->built->template->get('id'));
        api::publish_version($draft->get('id'));

        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('error:notdraft', 'local_appraisal'));
        reorder_fields::execute($this->versionid, [
            ['id' => 0, 'fieldids' => [$this->fieldid('C'), $this->fieldid('B'), $this->fieldid('A')]],
        ]);
    }

    /**
     * A layout that is not exactly the version's sections and fields is refused, and nothing changes.
     *
     * @return void
     */
    public function test_layout_must_match_the_version(): void {
        $other = $this->gen->create_template(['publish' => false, 'fields' => [['label' => 'X']]]);
        $foreign = $other->fields['X']->get('id');
        $a = $this->fieldid('A');
        $b = $this->fieldid('B');
        $c = $this->fieldid('C');

        $cases = [
            'error:sectionmismatch' => [['id' => 7, 'fieldids' => [$a, $b, $c]]],
            'error:fieldmismatch' => [['id' => 0, 'fieldids' => [$a, $b]]],
        ];
        foreach ($cases as $errorcode => $layout) {
            try {
                reorder_fields::execute($this->versionid, $layout);
                $this->fail("Expected {$errorcode}.");
            } catch (moodle_exception $e) {
                $this->assertSame($errorcode, $e->errorcode);
            }
        }
        try {
            reorder_fields::execute($this->versionid, [['id' => 0, 'fieldids' => [$a, $b, $foreign]]]);
            $this->fail('A field of another version should be refused.');
        } catch (moodle_exception $e) {
            $this->assertSame('error:fieldmismatch', $e->errorcode);
        }
        $this->assertSame(['A', 'B', 'C'], $this->labels());
        $this->assertSame(['X'], array_values(array_map(
            fn(template_field $field): string => $field->get('label'),
            api::get_fields($other->version->get('id'))
        )));
    }

    /**
     * Parameters are validated first: a text id is refused.
     *
     * @return void
     */
    public function test_parameters_are_validated(): void {
        $this->expectException(\invalid_parameter_exception::class);
        reorder_fields::execute($this->versionid, [['id' => 'zero', 'fieldids' => [1]]]);
    }

    /**
     * A version that does not exist is refused.
     *
     * @return void
     */
    public function test_unknown_version_is_refused(): void {
        $this->expectException(\dml_missing_record_exception::class);
        reorder_fields::execute($this->versionid + 1000, [['id' => 0, 'fieldids' => []]]);
    }
}
