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

namespace local_appraisal\output;

use local_appraisal\api;
use local_appraisal\record_answer;
use local_appraisal\scale_level;
use local_appraisal\template_field;

/**
 * Describes the input for one field to the record_input template: the control for its type, its options, its limits
 * and the saved value.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class answer_input {
    /**
     * The template context for a field's input.
     *
     * A scale level's value is never included, only its label and description.
     *
     * @param template_field $field
     * @param record_answer|null $answer The viewer's saved answer, or null.
     * @param scale_level[]|null $levels The scale levels by id, from api::get_levels_for_fields(), or null to look up.
     * @return array
     */
    public static function export(template_field $field, ?record_answer $answer, ?array $levels = null): array {
        $type = $field->get('type');
        $config = $field->get_config();
        $context = [
            'fieldid' => $field->get('id'),
            'type' => $type,
            'labelid' => 'local-appraisal-label-' . $field->get('id'),
            'label' => $field->get('label'),
            'required' => (bool) $field->get('required'),
            'isshorttext' => $type === template_field::TYPE_SHORTTEXT,
            'islongtext' => $type === template_field::TYPE_LONGTEXT,
            'isradio' => $type === template_field::TYPE_SINGLECHOICE && ($config['display'] ?? 'radio') === 'radio',
            'isselect' => $type === template_field::TYPE_SINGLECHOICE && ($config['display'] ?? 'radio') === 'select',
            'ismulti' => $type === template_field::TYPE_MULTICHOICE,
            'isscale' => $type === template_field::TYPE_SCALE,
            'isdate' => $type === template_field::TYPE_DATE,
            'isnumber' => $type === template_field::TYPE_NUMBER,
            'value' => '',
            'maxlength' => api::SHORTTEXT_MAXLENGTH,
            'textlength' => api::LONGTEXT_MAXLENGTH,
            'options' => [],
            'hasoptions' => false,
            'canclear' => false,
            'hasmin' => isset($config['min']),
            'min' => $config['min'] ?? null,
            'hasmax' => isset($config['max']),
            'max' => $config['max'] ?? null,
            'step' => 'any',
        ];

        switch ($type) {
            case template_field::TYPE_SHORTTEXT:
            case template_field::TYPE_LONGTEXT:
                $context['value'] = (string) $answer?->get('valuetext');
                break;
            case template_field::TYPE_SINGLECHOICE:
                $selected = $answer?->get('valueint');
                foreach ($field->get_options() as $key => $label) {
                    $context['options'][] = self::option($field, $key, $label, '', $selected === $key);
                }
                $context['canclear'] = $context['isradio'] && !$field->get('required');
                break;
            case template_field::TYPE_MULTICHOICE:
                $selected = array_map('intval', (array) json_decode((string) $answer?->get('valuetext'), true));
                foreach ($field->get_options() as $key => $label) {
                    $context['options'][] = self::option($field, $key, $label, '', in_array($key, $selected, true));
                }
                break;
            case template_field::TYPE_SCALE:
                $selected = $answer?->get('valueint');
                $levels = $levels ?? api::get_levels_for_fields([$field]);
                foreach ($levels as $level) {
                    if ($level->get('scaleid') === $field->get('scaleid')) {
                        $option = self::option(
                            $field,
                            $level->get('id'),
                            $level->get('label'),
                            (string) $level->get('description'),
                            $selected === $level->get('id')
                        );
                        $option['sortorder'] = $level->get('sortorder');
                        $context['options'][] = $option;
                    }
                }
                usort($context['options'], fn(array $a, array $b): int => $a['sortorder'] <=> $b['sortorder']);
                $context['canclear'] = !$field->get('required');
                break;
            case template_field::TYPE_DATE:
                if ($answer?->get('valueint') !== null) {
                    // Without day fixing, so a single-digit day keeps its leading zero, as a date input needs.
                    $context['value'] = userdate($answer->get('valueint'), '%Y-%m-%d', 99, false);
                }
                break;
            case template_field::TYPE_NUMBER:
                $decimals = $config['decimals'] ?? null;
                if ($answer?->get('valuenumber') !== null) {
                    $context['value'] = format_float((float) $answer->get('valuenumber'), $decimals ?? -1, false);
                }
                if ($decimals !== null) {
                    $context['step'] = $decimals === 0 ? '1' : '0.' . str_repeat('0', $decimals - 1) . '1';
                }
                break;
        }
        $context['hasoptions'] = !empty($context['options']);
        return $context;
    }

    /**
     * One option of a choice or scale input.
     *
     * @param template_field $field
     * @param int $key The option key, or the scale level id.
     * @param string $label
     * @param string $description
     * @param bool $checked
     * @return array
     */
    private static function option(template_field $field, int $key, string $label, string $description, bool $checked): array {
        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'checked' => $checked,
            'id' => 'local-appraisal-opt-' . $field->get('id') . '-' . $key,
            'sortorder' => 0,
        ];
    }
}
