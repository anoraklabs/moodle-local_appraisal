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
use moodle_exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the value rules of each field type: what an answer may be (api::parse_answer_value()), when an answer
 * counts as complete, how it is shown, and what a field's own settings may be (template_field).
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(api::class)]
#[CoversClass(template_field::class)]
final class field_values_test extends \advanced_testcase {
    /** @var local_appraisal_generator The plugin's data generator. */
    private local_appraisal_generator $gen;

    /** @var \stdClass The generated template, with one field of each type. */
    private \stdClass $built;

    /** @var scale The scale of the graded rating field. */
    private scale $scale;

    /**
     * Build a template with one field of each type, in the shapes the tests need.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->gen = $this->getDataGenerator()->get_plugin_generator('local_appraisal');
        $this->scale = $this->gen->create_scale(['levels' => ['Low' => 1, 'Medium' => 5, 'High' => 9]]);
        $this->built = $this->gen->create_template([
            'fields' => [
                ['type' => template_field::TYPE_SHORTTEXT, 'label' => 'Short'],
                ['type' => template_field::TYPE_LONGTEXT, 'label' => 'Long'],
                ['type' => template_field::TYPE_SINGLECHOICE, 'label' => 'One', 'options' => "Red\nGreen\nBlue"],
                ['type' => template_field::TYPE_MULTICHOICE, 'label' => 'Many', 'options' => "Red\nGreen\nBlue"],
                ['type' => template_field::TYPE_SCALE, 'label' => 'Rate', 'scaleid' => $this->scale->get('id')],
                ['type' => template_field::TYPE_DATE, 'label' => 'When'],
                ['type' => template_field::TYPE_NUMBER, 'label' => 'Bounded', 'min' => 0, 'max' => 10, 'decimals' => 1],
                ['type' => template_field::TYPE_NUMBER, 'label' => 'Whole', 'decimals' => 0],
                ['type' => template_field::TYPE_NUMBER, 'label' => 'Free'],
                ['type' => template_field::TYPE_NUMBER, 'label' => 'Minimum only', 'min' => -5],
            ],
        ]);
    }

    /**
     * A field of the generated template.
     *
     * @param string $label
     * @return template_field
     */
    private function field(string $label): template_field {
        return $this->built->fields[$label];
    }

    /**
     * Parse a value for a field.
     *
     * @param string $label
     * @param string|null $value
     * @return array|null
     */
    private function parse(string $label, ?string $value): ?array {
        return api::parse_answer_value($this->field($label), $value);
    }

    /**
     * Assert that a value is refused with the given error code.
     *
     * @param string $label
     * @param string|null $value
     * @param string $errorcode
     * @return void
     */
    private function assert_refused(string $label, ?string $value, string $errorcode): void {
        try {
            $this->parse($label, $value);
            $this->fail("Expected '{$value}' to be refused with {$errorcode} for {$label}.");
        } catch (moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode, "'{$value}' for {$label}");
        }
    }

    /**
     * An answer is cleared by an empty value, for every type, and an empty list clears a multiple choice.
     *
     * @param string $label
     * @param string|null $value
     * @return void
     */
    #[DataProvider('empty_provider')]
    public function test_empty_values_clear(string $label, ?string $value): void {
        $this->assertNull($this->parse($label, $value));
    }

    /**
     * Values that clear an answer.
     *
     * @return array
     */
    public static function empty_provider(): array {
        $cases = [];
        foreach (['Short', 'Long', 'One', 'Many', 'Rate', 'When', 'Bounded', 'Whole', 'Free', 'Minimum only'] as $label) {
            $cases[$label . ' null'] = [$label, null];
            $cases[$label . ' empty'] = [$label, ''];
            $cases[$label . ' spaces'] = [$label, "  \n "];
        }
        $cases['Many empty list'] = ['Many', '[]'];
        return $cases;
    }

    /**
     * Text is trimmed and kept as typed, in the text column only.
     *
     * @return void
     */
    public function test_text_is_trimmed_and_stored_as_text(): void {
        $this->assertSame(
            ['valuetext' => 'Hello there', 'valueint' => null, 'valuenumber' => null],
            $this->parse('Short', '  Hello there ')
        );
        $long = $this->parse('Long', "Line one\n\nLine three  ");
        $this->assertSame("Line one\n\nLine three", $long['valuetext']);
        $this->assertSame('<b>x</b>', $this->parse('Short', '<b>x</b>')['valuetext'], 'Stored raw; escaped when shown.');
    }

    /**
     * A short text is limited to 255 characters and a long text to 5,000, counted in characters not bytes.
     *
     * @return void
     */
    public function test_text_length_limits(): void {
        $this->assertNotNull($this->parse('Short', str_repeat('x', 255)));
        $this->assert_refused('Short', str_repeat('x', 256), 'error:answertoolong');
        $this->assertNotNull($this->parse('Short', str_repeat('é', 255)));
        $this->assert_refused('Short', str_repeat('é', 256), 'error:answertoolong');

        $this->assertNotNull($this->parse('Long', str_repeat('y', 5000)));
        $this->assert_refused('Long', str_repeat('y', 5001), 'error:answertoolong');
        $this->assertNotNull($this->parse('Long', str_repeat('y', 256)), 'A long text may exceed the short limit.');

        try {
            $this->parse('Short', str_repeat('x', 256));
            $this->fail('A short text over 255 characters should be refused.');
        } catch (moodle_exception $e) {
            $this->assertSame(255, $e->a);
        }
    }

    /**
     * A single choice takes the key of one of its options and nothing else.
     *
     * @return void
     */
    public function test_single_choice(): void {
        $this->assertSame(2, $this->parse('One', '2')['valueint']);
        $this->assertSame(3, $this->parse('One', ' 3 ')['valueint']);
        foreach (['0', '4', '99', '-1', '1.0', 'Red', 'a', '1,2', '[1]', '1e0'] as $bad) {
            $this->assert_refused('One', $bad, 'error:invalidanswer');
        }
    }

    /**
     * A multiple choice takes a JSON list of option keys, stored sorted and without repeats.
     *
     * @return void
     */
    public function test_multiple_choice(): void {
        $this->assertSame('[1]', $this->parse('Many', '[1]')['valuetext']);
        $this->assertSame('[1,3]', $this->parse('Many', '[3,1]')['valuetext']);
        $this->assertSame('[1,2,3]', $this->parse('Many', '[2,3,2,1,1]')['valuetext']);
        $this->assertNull($this->parse('Many', '[3,1]')['valueint']);

        $badvalues = [
            '[4]', '[0]', '[1,4]', '["1"]', '[1.5]', '[true]', '[null]', '{"a":1}', '{"1":1}', '1', '"1"', 'Red', '[1', '[[1]]',
        ];
        foreach ($badvalues as $bad) {
            $this->assert_refused('Many', $bad, 'error:invalidanswer');
        }
    }

    /**
     * A graded rating takes the id of a level of the field's own scale; the level's number is never the input.
     *
     * @return void
     */
    public function test_graded_rating(): void {
        $levels = array_values(api::get_scale_levels($this->scale->get('id')));
        $other = $this->gen->create_scale(['levels' => ['A' => 1, 'B' => 2]]);
        $foreign = array_values(api::get_scale_levels($other->get('id')))[0];

        $this->assertSame($levels[1]->get('id'), $this->parse('Rate', (string) $levels[1]->get('id'))['valueint']);
        $this->assert_refused('Rate', (string) $foreign->get('id'), 'error:invalidanswer');
        $this->assert_refused('Rate', (string) ($foreign->get('id') + 1000), 'error:invalidanswer');
        $this->assert_refused('Rate', 'Medium', 'error:invalidanswer');
        $this->assert_refused('Rate', '-' . $levels[0]->get('id'), 'error:invalidanswer');
        $this->assert_refused('Rate', $levels[0]->get('id') . '.0', 'error:invalidanswer');
    }

    /**
     * A date is a real calendar day as YYYY-MM-DD and is stored as midday of that day.
     *
     * @return void
     */
    public function test_date(): void {
        $answer = $this->parse('When', '2028-02-29');
        $this->assertSame('2028-02-29 12', userdate($answer['valueint'], '%Y-%m-%d %H', 99, false));
        $this->assertNull($answer['valuetext']);

        $badvalues = [
            '2027-02-29', '2026-13-01', '2026-00-10', '2026-04-31', '29/02/2028', '2026-1-5', '20260105', 'today',
            '2026-01-05 10:00', '+2026-01-05',
        ];
        foreach ($badvalues as $bad) {
            $this->assert_refused('When', $bad, 'error:invalidanswer');
        }
    }

    /**
     * Midday is in the saver's timezone, so the chosen day shows the same to that person.
     *
     * @return void
     */
    public function test_date_is_midday_in_the_savers_timezone(): void {
        $user = $this->getDataGenerator()->create_user(['timezone' => 'Pacific/Auckland']);
        $this->setUser($user);

        $answer = $this->parse('When', '2026-03-05');

        $this->assertSame('2026-03-05 12', userdate($answer['valueint'], '%Y-%m-%d %H', 99, false));
        $this->assertSame(
            (new \DateTime('2026-03-05 12:00:00', new \DateTimeZone('Pacific/Auckland')))->getTimestamp(),
            $answer['valueint']
        );
    }

    /**
     * A number inside its limits and decimals is stored in the number column.
     *
     * @return void
     */
    public function test_number_within_limits(): void {
        $this->assertSame(['valuenumber' => 7.5, 'valueint' => null, 'valuetext' => null], $this->parse('Bounded', '7.5'));
        $this->assertSame(0.0, $this->parse('Bounded', '0')['valuenumber'], 'Zero is an answer, not an empty one.');
        $this->assertSame(0.0, $this->parse('Bounded', '0.0')['valuenumber']);
        $this->assertSame(10.0, $this->parse('Bounded', '10')['valuenumber']);
        $this->assertSame(3.0, $this->parse('Bounded', ' 3 ')['valuenumber']);
        $this->assertSame(-5.0, $this->parse('Minimum only', '-5')['valuenumber']);
        $this->assertSame(123456.0, $this->parse('Minimum only', '123456')['valuenumber'], 'No maximum is set.');
    }

    /**
     * A number outside its minimum or maximum is refused, at the edges and beyond.
     *
     * @return void
     */
    public function test_number_out_of_range(): void {
        $this->assert_refused('Bounded', '10.1', 'error:answeroutofrange');
        $this->assert_refused('Bounded', '11', 'error:answeroutofrange');
        $this->assert_refused('Bounded', '-0.1', 'error:answeroutofrange');
        $this->assert_refused('Bounded', '-1', 'error:answeroutofrange');
        $this->assert_refused('Minimum only', '-5.5', 'error:answeroutofrange');
        $this->assert_refused('Minimum only', '-6', 'error:answeroutofrange');
    }

    /**
     * A number with more decimals than the field allows is refused, not rounded.
     *
     * @return void
     */
    public function test_number_decimals(): void {
        $this->assert_refused('Bounded', '2.25', 'error:answerdecimals');
        $this->assert_refused('Bounded', '0.05', 'error:answerdecimals');
        $this->assertSame(2.2, $this->parse('Bounded', '2.20')['valuenumber']);

        $this->assert_refused('Whole', '2.5', 'error:answerwhole');
        $this->assert_refused('Whole', '0.1', 'error:answerwhole');
        $this->assertSame(-4.0, $this->parse('Whole', '-4')['valuenumber']);
        $this->assertSame(4.0, $this->parse('Whole', '4.0')['valuenumber']);

        $this->assertSame(1.23456, $this->parse('Free', '1.23456')['valuenumber'], 'Five decimals is the most any field takes.');
        $this->assert_refused('Free', '1.234567', 'error:answerdecimals');

        try {
            $this->parse('Bounded', '2.25');
            $this->fail('Too many decimals should be refused.');
        } catch (moodle_exception $e) {
            $this->assertSame(1, $e->a);
        }
    }

    /**
     * Anything that is not a plain finite number is refused for every number field.
     *
     * @return void
     */
    public function test_number_must_be_a_number(): void {
        $badvalues = ['abc', '1,5,6', 'NaN', 'INF', '-INF', '1/2', '0x10', '10%', '--3', '1000000000000000', '-1000000000000000'];
        foreach ($badvalues as $bad) {
            $this->assert_refused('Free', $bad, 'error:invalidanswer');
        }
        $this->assertSame(999999999999999.0, $this->parse('Free', '999999999999999')['valuenumber']);
    }

    /**
     * Whether an answer counts as complete depends on the type.
     *
     * @return void
     */
    public function test_answer_completeness(): void {
        $answer = fn(array $values): record_answer => new record_answer(0, (object) $values);

        $this->assertFalse(api::is_answer_complete($this->field('Short'), null));
        $this->assertFalse(api::is_answer_complete($this->field('Short'), $answer(['valuetext' => '   '])));
        $this->assertTrue(api::is_answer_complete($this->field('Short'), $answer(['valuetext' => 'x'])));
        $this->assertFalse(api::is_answer_complete($this->field('Long'), $answer(['valuetext' => ''])));
        $this->assertTrue(api::is_answer_complete($this->field('Long'), $answer(['valuetext' => 'x'])));

        $this->assertFalse(api::is_answer_complete($this->field('Many'), $answer(['valuetext' => '[]'])));
        $this->assertFalse(api::is_answer_complete($this->field('Many'), $answer(['valuetext' => 'junk'])));
        $this->assertTrue(api::is_answer_complete($this->field('Many'), $answer(['valuetext' => '[1,2]'])));

        $this->assertFalse(api::is_answer_complete($this->field('One'), $answer(['valueint' => null])));
        $this->assertTrue(api::is_answer_complete($this->field('One'), $answer(['valueint' => 2])));
        $this->assertFalse(api::is_answer_complete($this->field('Rate'), $answer(['valueint' => null])));
        $this->assertTrue(api::is_answer_complete($this->field('Rate'), $answer(['valueint' => 7])));
        $this->assertTrue(api::is_answer_complete($this->field('When'), $answer(['valueint' => 1700000000])));
    }

    /**
     * A number counts as complete only while it is within the field's limits, which matters if a limit changed.
     *
     * @return void
     */
    public function test_number_completeness_follows_the_limits(): void {
        $answer = fn(float $number): record_answer => new record_answer(0, (object) ['valuenumber' => $number]);
        $bounded = $this->field('Bounded');

        $this->assertTrue(api::is_answer_complete($bounded, $answer(0.0)), 'Zero is complete.');
        $this->assertTrue(api::is_answer_complete($bounded, $answer(10.0)));
        $this->assertFalse(api::is_answer_complete($bounded, $answer(10.5)));
        $this->assertFalse(api::is_answer_complete($bounded, $answer(-1.0)));
        $this->assertFalse(api::is_answer_complete($bounded, new record_answer(0, (object) ['valuenumber' => null])));
        $this->assertTrue(api::is_answer_complete($this->field('Free'), $answer(-1.0E+9)));
    }

    /**
     * Answers are shown as text: option labels, the level's label and description (never its value), a date, a number.
     *
     * @return void
     */
    public function test_answers_are_shown_as_text(): void {
        $answer = fn(array $values): record_answer => new record_answer(0, (object) $values);
        $levels = array_values(api::get_scale_levels($this->scale->get('id')));
        api::update_scale_level($levels[2]->get('id'), 'High', 9, 'Well above');
        $levels = array_values(api::get_scale_levels($this->scale->get('id')));

        $this->assertSame('Green', api::format_answer($this->field('One'), $answer(['valueint' => 2]))->text);
        $this->assertSame('', api::format_answer($this->field('One'), $answer(['valueint' => 77]))->text);
        $this->assertSame(
            'Red, Blue',
            api::format_answer($this->field('Many'), $answer(['valuetext' => '[1,3]']))->text
        );
        $this->assertSame('Red', api::format_answer($this->field('Many'), $answer(['valuetext' => '[1,9]']))->text);

        $rated = api::format_answer($this->field('Rate'), $answer(['valueint' => $levels[2]->get('id')]));
        $this->assertSame('High', $rated->text);
        $this->assertSame('Well above', $rated->description);
        $this->assertStringNotContainsString('9', $rated->text . $rated->description);
        $preloaded = api::format_answer(
            $this->field('Rate'),
            $answer(['valueint' => $levels[0]->get('id')]),
            api::get_levels_for_fields([$this->field('Rate')])
        );
        $this->assertSame('Low', $preloaded->text);
        $this->assertSame('', api::format_answer($this->field('Rate'), $answer(['valueint' => 999999]))->text);

        $day = make_timestamp(2026, 3, 5, 12);
        $shown = api::format_answer($this->field('When'), $answer(['valueint' => $day]));
        $this->assertSame(userdate($day, get_string('strftimedate')), $shown->text);
        $this->assertSame('7.5', api::format_answer($this->field('Bounded'), $answer(['valuenumber' => 7.5]))->text);
        $this->assertSame('3.0', api::format_answer($this->field('Bounded'), $answer(['valuenumber' => 3.0]))->text);
        $this->assertSame('4', api::format_answer($this->field('Whole'), $answer(['valuenumber' => 4.0]))->text);
        $this->assertSame('plain', api::format_answer($this->field('Short'), $answer(['valuetext' => 'plain']))->text);
    }

    /**
     * Whether a field takes options follows its type.
     *
     * @return void
     */
    public function test_has_options(): void {
        $this->assertTrue($this->field('One')->has_options());
        $this->assertTrue($this->field('Many')->has_options());
        foreach (['Short', 'Long', 'Rate', 'When', 'Bounded'] as $label) {
            $this->assertFalse($this->field($label)->has_options(), $label);
            $this->assertSame([], $this->field($label)->get_options(), $label);
        }
    }

    /**
     * Who completes a field is read from its setting.
     *
     * @return void
     */
    public function test_completed_by(): void {
        $template = api::create_template('T');
        $versionid = api::get_draft($template->get('id'))->get('id');
        $appraiser = api::add_field($versionid, 1, 'A', template_field::COMPLETEDBY_APPRAISER);
        $appraisee = api::add_field($versionid, 1, 'B', template_field::COMPLETEDBY_APPRAISEE);
        $both = api::add_field($versionid, 1, 'C', template_field::COMPLETEDBY_BOTH);

        $this->assertSame([true, false], [$appraiser->is_completed_by_appraiser(), $appraiser->is_completed_by_appraisee()]);
        $this->assertSame([false, true], [$appraisee->is_completed_by_appraiser(), $appraisee->is_completed_by_appraisee()]);
        $this->assertSame([true, true], [$both->is_completed_by_appraiser(), $both->is_completed_by_appraisee()]);
    }

    /**
     * Field settings reaching the persistent are checked for their type: a choice needs two options.
     *
     * @return void
     */
    public function test_choice_settings_are_validated(): void {
        $versionid = $this->built->version->get('id');
        $draft = api::create_draft($this->built->template->get('id'));
        $this->assertNotSame($versionid, $draft->get('id'));
        $one = template_field::TYPE_SINGLECHOICE;

        $good = json_encode(['options' => [['key' => 1, 'label' => 'A'], ['key' => 2, 'label' => 'B']], 'display' => 'select']);
        $this->assertNotNull(api::add_field($draft->get('id'), $one, 'Ok', 1, true, null, null, $good));
        $multi = template_field::TYPE_MULTICHOICE;
        $this->assertNotNull(api::add_field($draft->get('id'), $multi, 'Ok too', 1, true, null, null, $good));

        $bad = [
            'no config' => null,
            'one option' => json_encode(['options' => [['key' => 1, 'label' => 'A']]]),
            'no options' => json_encode(['display' => 'radio']),
            'options not a list' => json_encode(['options' => 'A,B']),
        ];
        foreach ($bad as $name => $configdata) {
            $this->assert_field_refused(
                'error:optionsatleasttwo',
                fn(): mixed => api::add_field($draft->get('id'), $one, 'Bad', 1, true, null, null, $configdata),
                $name
            );
        }
        $twooptions = [['key' => 1, 'label' => 'A'], ['key' => 2, 'label' => 'B']];
        $display = json_encode(['options' => $twooptions, 'display' => 'checkbox']);
        $this->assert_field_refused(
            'error:configdatainvalid',
            fn(): mixed => api::add_field($draft->get('id'), $one, 'Bad', 1, true, null, null, $display),
            'display'
        );
        $this->assertNotNull(
            api::add_field($draft->get('id'), template_field::TYPE_MULTICHOICE, 'No display needed', 1, true, null, null, $display),
            'Display only matters for a single choice.'
        );
    }

    /**
     * Settings must be a JSON object, whatever the type.
     *
     * @return void
     */
    public function test_settings_must_be_a_json_object(): void {
        $draft = api::create_draft($this->built->template->get('id'));
        $versionid = $draft->get('id');

        foreach (['not json', '[1,2]', '"text"', '5', 'true'] as $configdata) {
            $this->assert_field_refused(
                'error:configdatainvalid',
                fn(): mixed => api::add_field($versionid, template_field::TYPE_SHORTTEXT, 'Bad', 1, true, null, null, $configdata),
                $configdata
            );
        }
        $this->assertNotNull(api::add_field($versionid, template_field::TYPE_SHORTTEXT, 'Empty object', 1, true, null, null, '{}'));
        $this->assertNotNull(api::add_field($versionid, template_field::TYPE_SHORTTEXT, 'None'));
    }

    /**
     * Number settings: limits must be numbers with the minimum no greater than the maximum, decimals 0 to 5.
     *
     * @return void
     */
    public function test_number_settings_are_validated(): void {
        $draft = api::create_draft($this->built->template->get('id'));
        $versionid = $draft->get('id');
        $number = template_field::TYPE_NUMBER;
        $add = fn(array $config): mixed => api::add_field($versionid, $number, 'N', 1, true, null, null, json_encode($config));

        $this->assertNotNull($add(['min' => 5, 'max' => 5]));
        $this->assertNotNull($add(['decimals' => 5]));
        $this->assertNotNull($add(['decimals' => 0]));
        $this->assertNotNull($add(['min' => -3.5]));

        $this->assert_field_refused('error:numberlimits', fn(): mixed => $add(['min' => 6, 'max' => 5]), 'min above max');
        $this->assert_field_refused('error:numberlimits', fn(): mixed => $add(['min' => 'low']), 'text min');
        $this->assert_field_refused('error:numberlimits', fn(): mixed => $add(['max' => 'high']), 'text max');
        foreach ([6, -1, 1.5, '2'] as $decimals) {
            $this->assert_field_refused(
                'error:numberdecimals',
                fn(): mixed => $add(['decimals' => $decimals]),
                'decimals ' . $decimals
            );
        }
    }

    /**
     * Assert that a field is refused by the persistent with the given message.
     *
     * @param string $errorkey The error string key in the plugin's language file.
     * @param callable $work
     * @param string $case Which case this is, for the failure message.
     * @return void
     */
    private function assert_field_refused(string $errorkey, callable $work, string $case): void {
        global $DB;
        try {
            $work();
            $this->fail("Expected {$errorkey} for {$case}.");
        } catch (\core\invalid_persistent_exception $e) {
            $this->assertStringContainsString(get_string($errorkey, 'local_appraisal'), (string) $e->a, $case);
        } finally {
            if ($DB->is_transaction_started()) {
                $DB->force_transaction_rollback();
            }
        }
    }

    /**
     * Building choice settings: one option per line, trimmed, blank lines skipped, keys 1 to N, display defaulting
     * to radio for a single choice and absent for a multiple choice.
     *
     * @return void
     */
    public function test_build_choice_configdata(): void {
        $single = json_decode(template_field::build_configdata(
            template_field::TYPE_SINGLECHOICE,
            ['options' => "  Red \r\n\r\nGreen\n\n  Blue  "]
        ), true);

        $expected = [['key' => 1, 'label' => 'Red'], ['key' => 2, 'label' => 'Green'], ['key' => 3, 'label' => 'Blue']];
        $this->assertSame($expected, $single['options']);
        $this->assertSame('radio', $single['display']);

        $one = template_field::TYPE_SINGLECHOICE;
        $select = json_decode(template_field::build_configdata($one, ['options' => "A\nB", 'display' => 'select']), true);
        $this->assertSame('select', $select['display']);
        $odd = json_decode(template_field::build_configdata($one, ['options' => "A\nB", 'display' => 'carousel']), true);
        $this->assertSame('radio', $odd['display']);

        $many = template_field::TYPE_MULTICHOICE;
        $multi = json_decode(template_field::build_configdata($many, ['options' => "A\nB", 'display' => 'select']), true);
        $this->assertArrayNotHasKey('display', $multi);
    }

    /**
     * Rebuilding keeps the key of an option whose label is unchanged (case aside) and gives a new one the next key.
     *
     * @return void
     */
    public function test_rebuilding_choice_settings_keeps_keys(): void {
        $first = template_field::build_configdata(template_field::TYPE_MULTICHOICE, ['options' => "Red\nGreen\nBlue"]);

        $second = json_decode(template_field::build_configdata(
            template_field::TYPE_MULTICHOICE,
            ['options' => "Blue\nred\nYellow\nPurple"],
            $first
        ), true);

        $this->assertSame(
            [
                ['key' => 3, 'label' => 'Blue'],
                ['key' => 1, 'label' => 'red'],
                ['key' => 4, 'label' => 'Yellow'],
                ['key' => 5, 'label' => 'Purple'],
            ],
            $second['options']
        );

        // A removed option's key is never handed to a new one in the same save: the next key is above every old key.
        $third = json_decode(template_field::build_configdata(
            template_field::TYPE_MULTICHOICE,
            ['options' => "Red\nOrange"],
            $first
        ), true);
        $this->assertSame([['key' => 1, 'label' => 'Red'], ['key' => 4, 'label' => 'Orange']], $third['options']);
    }

    /**
     * A new option takes a key above the highest ever used, even when a gap means the highest is not the count.
     *
     * @return void
     */
    public function test_new_option_key_is_above_the_highest_key_after_a_gap(): void {
        $multi = template_field::TYPE_MULTICHOICE;
        $first = template_field::build_configdata($multi, ['options' => "Red\nGreen\nBlue"]);
        $gap = template_field::build_configdata($multi, ['options' => "Red\nBlue"], $first);
        $this->assertSame([1, 3], array_column(json_decode($gap, true)['options'], 'key'));

        $next = json_decode(template_field::build_configdata($multi, ['options' => "Red\nBlue\nPink"], $gap), true);

        $keys = array_column($next['options'], 'key');
        $this->assertSame([1, 3, 4], $keys);
        $this->assertCount(count($keys), array_unique($keys));
    }

    /**
     * Relabelling keeps the stored keys, also when they do not run 1 to N.
     *
     * @return void
     */
    public function test_relabel_keeps_keys_that_have_a_gap(): void {
        $multi = template_field::TYPE_MULTICHOICE;
        $first = template_field::build_configdata($multi, ['options' => "Red\nGreen\nBlue"]);
        $gap = template_field::build_configdata($multi, ['options' => "Red\nBlue"], $first);

        $config = json_decode(template_field::relabel_options($gap, ['Rouge', 'Bleu']), true);

        $this->assertSame([['key' => 1, 'label' => 'Rouge'], ['key' => 3, 'label' => 'Bleu']], $config['options']);
    }

    /**
     * A negative position is refused by the field itself.
     *
     * @return void
     */
    public function test_field_sort_order_cannot_be_negative(): void {
        $versionid = $this->built->version->get('id');
        $field = new template_field(0, (object) [
            'template_versionid' => $versionid,
            'sortorder' => -1,
            'type' => template_field::TYPE_SHORTTEXT,
            'label' => 'Odd',
        ]);

        $errors = $field->validate();

        $this->assertIsArray($errors);
        $this->assertArrayHasKey('sortorder', $errors);
    }

    /**
     * Option text needs two different labels, case aside, and nothing else.
     *
     * @return void
     */
    public function test_option_labels_are_validated(): void {
        $this->assertSame(['A', 'B'], template_field::parse_option_labels("A\nB"));
        $this->assertSame(['A', 'B'], template_field::parse_option_labels("\n A \n\n B \n"));
        foreach (['', "A", "A\n\n", "  \n  ", "\n\nOnly\n"] as $text) {
            try {
                template_field::parse_option_labels($text);
                $this->fail('Fewer than two labels should be refused.');
            } catch (moodle_exception $e) {
                $this->assertSame('error:optionsatleasttwo', $e->errorcode);
            }
        }
        foreach ([("A\nA"), "Yes\nyes", "Ünder\nünder"] as $text) {
            try {
                template_field::parse_option_labels($text);
                $this->fail('A repeated label should be refused.');
            } catch (moodle_exception $e) {
                $this->assertSame('error:optionsduplicate', $e->errorcode);
            }
        }
    }

    /**
     * Building number settings keeps only the limits that were given, with decimals a whole number.
     *
     * @return void
     */
    public function test_build_number_configdata(): void {
        $number = template_field::TYPE_NUMBER;

        $this->assertNull(template_field::build_configdata($number, []));
        $this->assertNull(template_field::build_configdata($number, ['min' => '', 'max' => '', 'decimals' => '']));
        $this->assertEquals(
            ['min' => 0.0, 'max' => 10.5, 'decimals' => 2],
            json_decode(template_field::build_configdata($number, ['min' => '0', 'max' => '10.5', 'decimals' => '2']), true)
        );
        $this->assertEquals(['max' => 3.0], json_decode(template_field::build_configdata($number, ['max' => 3]), true));
        $this->assertSame(['decimals' => 0], json_decode(template_field::build_configdata($number, ['decimals' => 0]), true));

        try {
            template_field::build_configdata($number, ['min' => 5, 'max' => 4]);
            $this->fail('A minimum above the maximum should be refused.');
        } catch (moodle_exception $e) {
            $this->assertSame('error:numberlimits', $e->errorcode);
        }
    }

    /**
     * The other types have no settings.
     *
     * @return void
     */
    public function test_other_types_have_no_settings(): void {
        $types = [
            template_field::TYPE_SHORTTEXT, template_field::TYPE_LONGTEXT, template_field::TYPE_SCALE, template_field::TYPE_DATE,
        ];
        foreach ($types as $type) {
            $this->assertNull(template_field::build_configdata($type, ['options' => "A\nB", 'min' => 1]), (string) $type);
        }
    }

    /**
     * Relabelling keeps every key and the order, and refuses a different count, blanks and repeats.
     *
     * @return void
     */
    public function test_relabel_options(): void {
        $configdata = template_field::build_configdata(
            template_field::TYPE_SINGLECHOICE,
            ['options' => "A\nB\nC", 'display' => 'select']
        );

        $config = json_decode(template_field::relabel_options($configdata, ['One', 'Two', 'Three']), true);

        $expected = [['key' => 1, 'label' => 'One'], ['key' => 2, 'label' => 'Two'], ['key' => 3, 'label' => 'Three']];
        $this->assertSame($expected, $config['options']);
        $this->assertSame('select', $config['display']);
        foreach ([['One', 'Two'], ['A', 'B', 'C', 'D']] as $labels) {
            try {
                template_field::relabel_options($configdata, $labels);
                $this->fail('A different number of options should be refused.');
            } catch (moodle_exception $e) {
                $this->assertSame('error:optioncountfixed', $e->errorcode);
            }
        }
        try {
            template_field::relabel_options($configdata, ['X', 'x', 'Y']);
            $this->fail('A repeat should be refused.');
        } catch (moodle_exception $e) {
            $this->assertSame('error:optionsduplicate', $e->errorcode);
        }
    }

    /**
     * Field types each have a display name, and an unknown type is a coding error.
     *
     * @return void
     */
    public function test_type_names(): void {
        foreach (array_keys(template_field::TYPES) as $type) {
            $this->assertNotSame('', template_field::type_name($type));
            $this->assertStringNotContainsString('[[', template_field::type_name($type));
        }
        $this->assertSame(template_field::type_name(template_field::TYPE_DATE), $this->field('When')->get_type_name());
        $this->assertCount(7, template_field::TYPES);

        $this->expectException(\coding_exception::class);
        template_field::type_name(99);
    }

    /**
     * The number summary lists the limits and decimals that are set, and is empty when none are.
     *
     * @return void
     */
    public function test_number_summary(): void {
        $this->assertSame('', $this->field('Free')->get_number_summary());
        $bounded = $this->field('Bounded')->get_number_summary();
        $this->assertStringContainsString('10', $bounded);
        $this->assertStringContainsString(',', $bounded);
        $this->assertStringContainsString('-5', $this->field('Minimum only')->get_number_summary());
        $this->assertNotSame('', $this->field('Whole')->get_number_summary());
    }

    /**
     * The stored values of the enumerations are fixed: changing one would break existing data.
     *
     * @return void
     */
    public function test_stored_constants_are_pinned(): void {
        $this->assertSame(
            [1, 2, 3, 4, 5, 6, 7],
            [
                template_field::TYPE_SHORTTEXT, template_field::TYPE_LONGTEXT, template_field::TYPE_SINGLECHOICE,
                template_field::TYPE_MULTICHOICE, template_field::TYPE_SCALE, template_field::TYPE_DATE,
                template_field::TYPE_NUMBER,
            ]
        );
        $this->assertSame(
            [1, 2, 3],
            [template_field::COMPLETEDBY_APPRAISER, template_field::COMPLETEDBY_APPRAISEE, template_field::COMPLETEDBY_BOTH]
        );
        $this->assertSame(
            [1, 2, 3],
            [template_version::STATUS_DRAFT, template_version::STATUS_PUBLISHED, template_version::STATUS_ARCHIVED]
        );
        $this->assertSame(
            [1, 2, 3, 1, 2, 3],
            [
                template_version::COMMENTS_OFF, template_version::COMMENTS_OPTIONAL, template_version::COMMENTS_REQUIRED,
                template_version::GRADE_OFF, template_version::GRADE_OPTIONAL, template_version::GRADE_REQUIRED,
            ]
        );
        $this->assertSame([1, 2], [scale::STATUS_ACTIVE, scale::STATUS_ARCHIVED]);
        $this->assertSame([1, 2], [template::STATUS_ACTIVE, template::STATUS_ARCHIVED]);
        $this->assertSame([1, 2], [record_answer::ROLE_APPRAISER, record_answer::ROLE_APPRAISEE]);
    }
}
