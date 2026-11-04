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

/**
 * One person's saved answer to one field on one record.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @property int $id
 * @property int $recordid
 * @property int $fieldid
 * @property int $role
 * @property int|null $valueint
 * @property float|null $valuenumber
 * @property string|null $valuetext
 * @property int $userid
 * @property int|null $timeanswered
 * @property int $timecreated
 * @property int $timemodified
 * @property int $usermodified
 *
 * @method int get_recordid()
 * @method int get_fieldid()
 * @method int get_role()
 * @method int|null get_valueint()
 * @method float|null get_valuenumber()
 * @method string|null get_valuetext()
 * @method int get_userid()
 * @method int|null get_timeanswered()
 * @method $this set_recordid(int $recordid)
 * @method $this set_fieldid(int $fieldid)
 * @method $this set_role(int $role)
 * @method $this set_valueint(?int $value)
 * @method $this set_valuenumber(?float $value)
 * @method $this set_valuetext(?string $value)
 * @method $this set_userid(int $userid)
 * @method $this set_timeanswered(?int $time)
 */
class record_answer extends persistent {
    /** @var string The database table. */
    const TABLE = 'local_appraisal_record_answer';

    /** @var int Stored in database - DO NOT CHANGE. */
    const ROLE_APPRAISER = 1;
    /** @var int Stored in database - DO NOT CHANGE. */
    const ROLE_APPRAISEE = 2;

    /**
     * Return the definition of the properties of this model.
     *
     * @return array
     */
    protected static function define_properties(): array {
        return [
            'recordid' => [
                'type' => PARAM_INT,
            ],
            'fieldid' => [
                'type' => PARAM_INT,
            ],
            'role' => [
                'type' => PARAM_INT,
                'choices' => [self::ROLE_APPRAISER, self::ROLE_APPRAISEE],
            ],
            'valueint' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'valuenumber' => [
                'type' => PARAM_FLOAT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'valuetext' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'userid' => [
                'type' => PARAM_INT,
            ],
            'timeanswered' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
        ];
    }
}
