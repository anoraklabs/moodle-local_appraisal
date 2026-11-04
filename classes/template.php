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
 * An appraisal form. Its content lives in template versions.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @property int $id
 * @property string $name
 * @property int $status
 * @property int $timecreated
 * @property int $timemodified
 * @property int $usermodified
 *
 * @method string get_name()
 * @method int get_status()
 * @method $this set_name(string $name)
 * @method $this set_status(int $status)
 */
class template extends persistent {
    /** @var string The database table. */
    const TABLE = 'local_appraisal_template';

    /** @var int Stored in database - DO NOT CHANGE. */
    const STATUS_ACTIVE = 1;
    /** @var int Stored in database - DO NOT CHANGE. */
    const STATUS_ARCHIVED = 2;

    /**
     * Return the definition of the properties of this model.
     *
     * @return array
     */
    protected static function define_properties(): array {
        return [
            'name' => [
                'type' => PARAM_TEXT,
            ],
            'status' => [
                'type' => PARAM_INT,
                'choices' => [self::STATUS_ACTIVE, self::STATUS_ARCHIVED],
                'default' => self::STATUS_ACTIVE,
            ],
        ];
    }

    /**
     * Validate the name.
     *
     * @param string $value
     * @return true|lang_string
     */
    protected function validate_name(string $value): true|lang_string {
        if (trim($value) === '') {
            return new lang_string('required');
        }
        return true;
    }

    /**
     * Whether the template is active (not archived).
     *
     * @return bool
     */
    public function is_active(): bool {
        return $this->get('status') === self::STATUS_ACTIVE;
    }

    /**
     * The status as a display string.
     *
     * @return string
     */
    public function get_status_name(): string {
        $keys = [self::STATUS_ACTIVE => 'statusactive', self::STATUS_ARCHIVED => 'statusarchived'];
        return get_string($keys[$this->get('status')], 'local_appraisal');
    }
}
