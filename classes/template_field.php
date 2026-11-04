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

use core\persistent;
use lang_string;

/**
 * One item on the form of a template version.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @property int $id
 * @property int $template_versionid
 * @property int|null $sectionid
 * @property int $sortorder
 * @property int $type
 * @property string $label
 * @property string|null $description
 * @property int $completedby
 * @property bool $required
 * @property int|null $scaleid
 * @property string|null $configdata
 * @property int $timecreated
 * @property int $timemodified
 * @property int $usermodified
 *
 * @method int get_template_versionid()
 * @method int|null get_sectionid()
 * @method int get_sortorder()
 * @method int get_type()
 * @method string get_label()
 * @method string|null get_description()
 * @method int get_completedby()
 * @method bool get_required()
 * @method int|null get_scaleid()
 * @method string|null get_configdata()
 * @method $this set_template_versionid(int $versionid)
 * @method $this set_sectionid(?int $sectionid)
 * @method $this set_sortorder(int $sortorder)
 * @method $this set_type(int $type)
 * @method $this set_label(string $label)
 * @method $this set_description(?string $description)
 * @method $this set_completedby(int $completedby)
 * @method $this set_required(bool $required)
 * @method $this set_scaleid(?int $scaleid)
 * @method $this set_configdata(?string $configdata)
 */
class template_field extends persistent {
    /** @var string The database table. */
    const TABLE = 'local_appraisal_template_field';

    /** @var int Stored in database - DO NOT CHANGE. */
    const TYPE_SHORTTEXT = 1;
    /** @var int Stored in database - DO NOT CHANGE. */
    const TYPE_LONGTEXT = 2;
    /** @var int Stored in database - DO NOT CHANGE. */
    const TYPE_SINGLECHOICE = 3;
    /** @var int Stored in database - DO NOT CHANGE. */
    const TYPE_MULTICHOICE = 4;
    /** @var int Stored in database - DO NOT CHANGE. */
    const TYPE_SCALE = 5;
    /** @var int Stored in database - DO NOT CHANGE. */
    const TYPE_DATE = 6;
    /** @var int Stored in database - DO NOT CHANGE. */
    const TYPE_NUMBER = 7;

    /** @var string[] The language string key of each field type, keyed by type value. */
    const TYPES = [
        self::TYPE_SHORTTEXT => 'typeshorttext',
        self::TYPE_LONGTEXT => 'typelongtext',
        self::TYPE_SINGLECHOICE => 'typesinglechoice',
        self::TYPE_MULTICHOICE => 'typemultichoice',
        self::TYPE_SCALE => 'typescale',
        self::TYPE_DATE => 'typedate',
        self::TYPE_NUMBER => 'typenumber',
    ];

    /** @var int Stored in database - DO NOT CHANGE. */
    const COMPLETEDBY_APPRAISER = 1;
    /** @var int Stored in database - DO NOT CHANGE. */
    const COMPLETEDBY_APPRAISEE = 2;
    /** @var int Stored in database - DO NOT CHANGE. */
    const COMPLETEDBY_BOTH = 3;

    /**
     * Return the definition of the properties of this model.
     *
     * @return array
     */
    protected static function define_properties(): array {
        return [
            'template_versionid' => [
                'type' => PARAM_INT,
            ],
            'sectionid' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'sortorder' => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
            'type' => [
                'type' => PARAM_INT,
                'choices' => array_keys(self::TYPES),
                'default' => self::TYPE_SHORTTEXT,
            ],
            'label' => [
                'type' => PARAM_TEXT,
            ],
            'description' => [
                'type' => PARAM_TEXT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'completedby' => [
                'type' => PARAM_INT,
                'choices' => [self::COMPLETEDBY_APPRAISER, self::COMPLETEDBY_APPRAISEE, self::COMPLETEDBY_BOTH],
                'default' => self::COMPLETEDBY_APPRAISER,
            ],
            'required' => [
                'type' => PARAM_BOOL,
                'default' => true,
            ],
            'scaleid' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'configdata' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
        ];
    }

    /**
     * Validate the label.
     *
     * @param string $value
     * @return true|lang_string
     */
    protected function validate_label(string $value): true|lang_string {
        if (trim($value) === '') {
            return new lang_string('required');
        }
        return true;
    }

    /**
     * Validate the sort order.
     *
     * @param int $value
     * @return true|lang_string
     */
    protected function validate_sortorder(int $value): true|lang_string {
        if ($value < 0) {
            return new lang_string('error:sortordernegative', 'local_appraisal');
        }
        return true;
    }

    /**
     * Validate the scale: a graded rating needs one and no other type takes one.
     *
     * @param int|null $value
     * @return true|lang_string
     */
    protected function validate_scaleid(?int $value): true|lang_string {
        $isscale = $this->get('type') === self::TYPE_SCALE;
        if ($isscale && $value === null) {
            return new lang_string('error:scalerequired', 'local_appraisal');
        }
        if (!$isscale && $value !== null) {
            return new lang_string('error:scalenotallowed', 'local_appraisal');
        }
        return true;
    }

    /**
     * Validate the type settings: empty, or a JSON object that suits the field type.
     *
     * @param string|null $value
     * @return true|lang_string
     */
    protected function validate_configdata(?string $value): true|lang_string {
        $config = null;
        if ($value !== null) {
            $config = json_decode($value, true);
            if (!is_array($config) || ($config !== [] && array_is_list($config))) {
                return new lang_string('error:configdatainvalid', 'local_appraisal');
            }
        }

        $type = $this->get('type');
        if ($type === self::TYPE_SINGLECHOICE || $type === self::TYPE_MULTICHOICE) {
            $options = $config['options'] ?? null;
            if (!is_array($options) || count($options) < 2) {
                return new lang_string('error:optionsatleasttwo', 'local_appraisal');
            }
            if ($type === self::TYPE_SINGLECHOICE && !in_array($config['display'] ?? 'radio', ['radio', 'select'], true)) {
                return new lang_string('error:configdatainvalid', 'local_appraisal');
            }
        } else if ($type === self::TYPE_NUMBER && $config) {
            $min = $config['min'] ?? null;
            $max = $config['max'] ?? null;
            $decimals = $config['decimals'] ?? null;
            $badlimit = ($min !== null && !is_numeric($min)) || ($max !== null && !is_numeric($max));
            if ($badlimit || ($min !== null && $max !== null && $min > $max)) {
                return new lang_string('error:numberlimits', 'local_appraisal');
            }
            if ($decimals !== null && (!is_int($decimals) || $decimals < 0 || $decimals > 5)) {
                return new lang_string('error:numberdecimals', 'local_appraisal');
            }
        }
        return true;
    }

    /**
     * Whether this is a single or multiple choice field, which stores a list of options.
     *
     * @return bool
     */
    public function has_options(): bool {
        return in_array($this->get('type'), [self::TYPE_SINGLECHOICE, self::TYPE_MULTICHOICE], true);
    }

    /**
     * The field's options as option key => label, in the stored order.
     *
     * @return string[]
     */
    public function get_options(): array {
        $options = [];
        foreach ($this->get_config()['options'] ?? [] as $option) {
            $options[(int) $option['key']] = (string) $option['label'];
        }
        return $options;
    }

    /**
     * The type settings to store for a field, built from what the field form collects.
     *
     * Choice options come as text, one label per line. A line that matches an existing option's label keeps that
     * option's key; any other line gets the next key above every key already used, so keys are never renumbered
     * and stored answers keep their meaning. Within one save a new line never takes a key already present.
     *
     * @param int $type One of the TYPE_* constants.
     * @param array $settings Any of 'options' (string), 'display' ('radio' or 'select'), 'min' (float|null),
     *     'max' (float|null) and 'decimals' (int|null).
     * @param string|null $existing The field's current configdata, or null for a new field.
     * @return string|null JSON, or null for types with no settings.
     * @throws \moodle_exception If the options or number limits are not valid.
     */
    public static function build_configdata(int $type, array $settings, ?string $existing = null): ?string {
        if ($type === self::TYPE_SINGLECHOICE || $type === self::TYPE_MULTICHOICE) {
            $labels = self::parse_option_labels((string) ($settings['options'] ?? ''));
            $previous = json_decode($existing ?? '', true)['options'] ?? [];
            $keybylabel = [];
            $nextkey = 1;
            foreach ($previous as $option) {
                $keybylabel[\core_text::strtolower($option['label'])] = (int) $option['key'];
                $nextkey = max($nextkey, (int) $option['key'] + 1);
            }
            $options = [];
            foreach ($labels as $label) {
                $lower = \core_text::strtolower($label);
                $options[] = ['key' => $keybylabel[$lower] ?? $nextkey++, 'label' => $label];
            }
            $config = ['options' => $options];
            if ($type === self::TYPE_SINGLECHOICE) {
                $config['display'] = ($settings['display'] ?? 'radio') === 'select' ? 'select' : 'radio';
            }
            return json_encode($config);
        }

        if ($type === self::TYPE_NUMBER) {
            $config = [];
            foreach (['min', 'max', 'decimals'] as $name) {
                if (isset($settings[$name]) && $settings[$name] !== '') {
                    $config[$name] = $name === 'decimals' ? (int) $settings[$name] : (float) $settings[$name];
                }
            }
            if (isset($config['min'], $config['max']) && $config['min'] > $config['max']) {
                throw new \moodle_exception('error:numberlimits', 'local_appraisal');
            }
            return $config ? json_encode($config) : null;
        }

        return null;
    }

    /**
     * Split option text into trimmed, non-empty labels, one per line.
     *
     * @param string $text
     * @return string[]
     * @throws \moodle_exception If there are fewer than two labels, or two are the same apart from case.
     */
    public static function parse_option_labels(string $text): array {
        $labels = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $labels[] = $line;
            }
        }
        if (count($labels) < 2) {
            throw new \moodle_exception('error:optionsatleasttwo', 'local_appraisal');
        }
        if (count(array_unique(array_map([\core_text::class, 'strtolower'], $labels))) !== count($labels)) {
            throw new \moodle_exception('error:optionsduplicate', 'local_appraisal');
        }
        return $labels;
    }

    /**
     * Replace the labels of a choice field's options in place, keeping every key and the order.
     *
     * @param string|null $configdata The field's current configdata.
     * @param string[] $labels The new label of every option, in the stored order.
     * @return string The new configdata JSON.
     * @throws \moodle_exception If the number of labels differs, or the labels are blank or repeated.
     */
    public static function relabel_options(?string $configdata, array $labels): string {
        $config = json_decode($configdata ?? '', true);
        $options = $config['options'] ?? [];
        $labels = self::parse_option_labels(implode("\n", $labels));
        if (count($labels) !== count($options)) {
            throw new \moodle_exception('error:optioncountfixed', 'local_appraisal');
        }
        foreach ($options as $index => $option) {
            $options[$index]['label'] = $labels[$index];
        }
        $config['options'] = $options;
        return json_encode($config);
    }

    /**
     * The type settings as an array, empty when there are none.
     *
     * @return array
     */
    public function get_config(): array {
        $config = json_decode($this->get('configdata') ?? '', true);
        return is_array($config) ? $config : [];
    }

    /**
     * Whether the appraiser completes this field (alone or with the appraisee).
     *
     * @return bool
     */
    public function is_completed_by_appraiser(): bool {
        return in_array($this->get('completedby'), [self::COMPLETEDBY_APPRAISER, self::COMPLETEDBY_BOTH], true);
    }

    /**
     * Whether the appraisee completes this field (alone or with the appraiser).
     *
     * @return bool
     */
    public function is_completed_by_appraisee(): bool {
        return in_array($this->get('completedby'), [self::COMPLETEDBY_APPRAISEE, self::COMPLETEDBY_BOTH], true);
    }

    /**
     * The display name of a field type.
     *
     * @param int $type One of the TYPE_* constants.
     * @return string
     * @throws \coding_exception If the value is not one of the TYPE_* constants.
     */
    public static function type_name(int $type): string {
        if (!isset(self::TYPES[$type])) {
            throw new \coding_exception('Unknown field type: ' . $type);
        }
        return get_string(self::TYPES[$type], 'local_appraisal');
    }

    /**
     * The display name of this field's type.
     *
     * @return string
     */
    public function get_type_name(): string {
        return self::type_name($this->get('type'));
    }

    /**
     * The number limits as a one-line summary, such as "0 to 10, 1 decimal place", or an empty string.
     *
     * @return string
     */
    public function get_number_summary(): string {
        $config = $this->get_config();
        $parts = [];
        if (isset($config['min'], $config['max'])) {
            $range = (object) ['min' => $config['min'], 'max' => $config['max']];
            $parts[] = get_string('numberrange', 'local_appraisal', $range);
        } else if (isset($config['min'])) {
            $parts[] = get_string('numberminimum', 'local_appraisal', $config['min']);
        } else if (isset($config['max'])) {
            $parts[] = get_string('numbermaximum', 'local_appraisal', $config['max']);
        }
        if (isset($config['decimals'])) {
            $parts[] = get_string('numberdecimalsummary', 'local_appraisal', $config['decimals']);
        }
        return implode(', ', $parts);
    }

    /**
     * The display names of who completes a field, keyed by value.
     *
     * @return array
     */
    public static function get_completedby_options(): array {
        return [
            self::COMPLETEDBY_APPRAISER => get_string('completedbyappraiser', 'local_appraisal'),
            self::COMPLETEDBY_APPRAISEE => get_string('completedbyappraisee', 'local_appraisal'),
            self::COMPLETEDBY_BOTH => get_string('completedbyboth', 'local_appraisal'),
        ];
    }
}
