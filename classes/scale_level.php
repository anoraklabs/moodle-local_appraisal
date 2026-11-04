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
 * One level of a grading scale. The numeric value orders the levels and is never shown to appraisal users.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @property int $id
 * @property int $scaleid
 * @property int $sortorder
 * @property string $label
 * @property float $value
 * @property string|null $description
 * @property int $timecreated
 * @property int $timemodified
 * @property int $usermodified
 *
 * @method int get_scaleid()
 * @method int get_sortorder()
 * @method string get_label()
 * @method float get_value()
 * @method string|null get_description()
 * @method $this set_scaleid(int $scaleid)
 * @method $this set_sortorder(int $sortorder)
 * @method $this set_label(string $label)
 * @method $this set_value(float $value)
 * @method $this set_description(?string $description)
 */
class scale_level extends persistent {
    /** @var string The database table. */
    const TABLE = 'local_appraisal_scale_level';

    /**
     * Return the definition of the properties of this model.
     *
     * @return array
     */
    protected static function define_properties(): array {
        return [
            'scaleid' => [
                'type' => PARAM_INT,
            ],
            'sortorder' => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
            'label' => [
                'type' => PARAM_TEXT,
            ],
            'value' => [
                'type' => PARAM_FLOAT,
            ],
            'description' => [
                'type' => PARAM_TEXT,
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
}
