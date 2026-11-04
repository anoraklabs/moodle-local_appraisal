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
use local_appraisal\scale_level;
use local_appraisal\template_field;
use local_appraisal_generator;
use moodle_exception;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the web service that stores a new order for a grading scale's levels.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(reorder_levels::class)]
final class reorder_levels_test extends \advanced_testcase {
    /** @var local_appraisal_generator The plugin's data generator. */
    private local_appraisal_generator $gen;

    /** @var \local_appraisal\scale The scale under test, with levels Low 1, Medium 2 and High 3. */
    private \local_appraisal\scale $scale;

    /** @var int[] The scale's level ids, lowest first. */
    private array $levelids;

    /**
     * Build a scale and sign in as a template manager.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->gen = $this->getDataGenerator()->get_plugin_generator('local_appraisal');
        $this->scale = $this->gen->create_scale();
        $this->levelids = array_keys(api::get_scale_levels($this->scale->get('id')));

        $manager = $this->getDataGenerator()->create_user();
        $this->gen->set_user_capability((int) $manager->id, 'local/appraisal:managetemplates');
        $this->setUser($manager);
    }

    /**
     * The level labels in their stored order.
     *
     * @return string[]
     */
    private function labels(): array {
        return array_values(array_map(
            fn(scale_level $level): string => $level->get('label'),
            api::get_scale_levels($this->scale->get('id'))
        ));
    }

    /**
     * The function is registered for the plugin's own AJAX calls only, as a write, needing managetemplates.
     *
     * @return void
     */
    public function test_registered_as_an_ajax_only_write(): void {
        $info = external_api::external_function_info('local_appraisal_reorder_levels');

        $this->assertTrue($info->allowed_from_ajax);
        $this->assertSame('write', $info->type);
        $this->assertSame(reorder_levels::class, $info->classname);
        $this->assertSame('local/appraisal:managetemplates', $info->capabilities);
    }

    /**
     * A template manager stores a new order; the values are dealt again and nothing is returned.
     *
     * @return void
     */
    public function test_manager_reorders_levels(): void {
        [$low, $medium, $high] = $this->levelids;

        $result = reorder_levels::execute($this->scale->get('id'), [$high, $low, $medium]);

        $this->assertNull($result);
        $this->assertNull(reorder_levels::execute_returns(), 'The service returns nothing.');
        $this->assertSame(['High', 'Low', 'Medium'], $this->labels());
        $this->assertSame(
            [1.0, 2.0, 3.0],
            array_values(array_map(
                fn(scale_level $level): float => (float) $level->get('value'),
                api::get_scale_levels($this->scale->get('id'))
            ))
        );
    }

    /**
     * Without the capability, nothing changes.
     *
     * @return void
     */
    public function test_user_without_the_capability_is_refused(): void {
        [$low, $medium, $high] = $this->levelids;
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            reorder_levels::execute($this->scale->get('id'), [$high, $medium, $low]);
            $this->fail('A user without managetemplates should be refused.');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $this->assertSame(['Low', 'Medium', 'High'], $this->labels());
    }

    /**
     * An appraiser who is not a template manager cannot reorder either.
     *
     * @return void
     */
    public function test_an_appraiser_is_refused(): void {
        [$low, $medium, $high] = $this->levelids;
        $appraiser = $this->getDataGenerator()->create_user();
        $this->gen->set_user_capability((int) $appraiser->id, 'local/appraisal:appraise');
        $this->setUser($appraiser);

        try {
            reorder_levels::execute($this->scale->get('id'), [$high, $medium, $low]);
            $this->fail('An appraiser without managetemplates should be refused.');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $this->assertSame(['Low', 'Medium', 'High'], $this->labels());
    }

    /**
     * Nobody signed in, and the guest, are refused.
     *
     * @return void
     */
    public function test_not_signed_in_and_guest_are_refused(): void {
        [$low, $medium, $high] = $this->levelids;

        $this->setUser(0);
        try {
            reorder_levels::execute($this->scale->get('id'), [$high, $medium, $low]);
            $this->fail('Nobody signed in should be refused.');
        } catch (\Exception $e) {
            $this->assertInstanceOf(\require_login_exception::class, $e);
        }

        $this->setGuestUser();
        try {
            reorder_levels::execute($this->scale->get('id'), [$high, $medium, $low]);
            $this->fail('The guest should be refused.');
        } catch (\Exception $e) {
            $this->assertTrue($e instanceof \require_login_exception || $e instanceof \required_capability_exception);
        }
        $this->assertSame(['Low', 'Medium', 'High'], $this->labels());
    }

    /**
     * Ids that are not exactly the scale's levels are refused, and nothing changes.
     *
     * @return void
     */
    public function test_ids_must_be_the_scales_levels(): void {
        [$low, $medium, $high] = $this->levelids;
        $other = $this->gen->create_scale();
        $foreign = array_keys(api::get_scale_levels($other->get('id')))[0];

        foreach ([[$low, $medium], [$low, $low, $medium], [$low, $medium, $foreign], []] as $ids) {
            try {
                reorder_levels::execute($this->scale->get('id'), $ids);
                $this->fail('A wrong list should be refused: ' . json_encode($ids));
            } catch (moodle_exception $e) {
                $this->assertSame('error:levelmismatch', $e->errorcode);
            }
        }
        $this->assertSame(['Low', 'Medium', 'High'], $this->labels());
    }

    /**
     * Parameters are validated before anything else: text ids are refused.
     *
     * @return void
     */
    public function test_parameters_are_validated(): void {
        $this->expectException(\invalid_parameter_exception::class);
        reorder_levels::execute($this->scale->get('id'), ['first', 'second', 'third']);
    }

    /**
     * A scale in use by a published template is locked, so the service refuses and nothing moves.
     *
     * @return void
     */
    public function test_a_locked_scale_is_refused(): void {
        [$low, $medium, $high] = $this->levelids;
        $this->gen->create_template([
            'fields' => [['type' => template_field::TYPE_SCALE, 'label' => 'Rate', 'scaleid' => $this->scale->get('id')]],
        ]);

        try {
            reorder_levels::execute($this->scale->get('id'), [$high, $medium, $low]);
            $this->fail('A locked scale should be refused.');
        } catch (moodle_exception $e) {
            $this->assertSame('error:scalelocked', $e->errorcode);
        }
        $this->assertSame(['Low', 'Medium', 'High'], $this->labels());
    }

    /**
     * A scale that does not exist is refused.
     *
     * @return void
     */
    public function test_unknown_scale_is_refused(): void {
        $this->expectException(\dml_missing_record_exception::class);
        reorder_levels::execute($this->scale->get('id') + 1000, $this->levelids);
    }
}
