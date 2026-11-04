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
 * One appraisal cycle for an assignment, pinned to a template version.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @property int $id
 * @property int $assignmentid
 * @property int $template_versionid
 * @property int $appraiserid
 * @property int $status
 * @property string|null $label
 * @property int|null $duedate
 * @property int|null $timereminded
 * @property int|null $timestarted
 * @property int|null $timesubmitted
 * @property int|null $timecompleted
 * @property int|null $overalllevelid
 * @property string|null $comment
 * @property int $commentformat
 * @property int $timecreated
 * @property int $timemodified
 * @property int $usermodified
 *
 * @method int get_assignmentid()
 * @method int get_template_versionid()
 * @method int get_appraiserid()
 * @method int get_status()
 * @method string|null get_label()
 * @method int|null get_duedate()
 * @method int|null get_timereminded()
 * @method int|null get_timestarted()
 * @method int|null get_timesubmitted()
 * @method int|null get_timecompleted()
 * @method int|null get_overalllevelid()
 * @method string|null get_comment()
 * @method int get_commentformat()
 * @method $this set_assignmentid(int $assignmentid)
 * @method $this set_template_versionid(int $versionid)
 * @method $this set_appraiserid(int $appraiserid)
 * @method $this set_status(int $status)
 * @method $this set_label(?string $label)
 * @method $this set_duedate(?int $time)
 * @method $this set_timereminded(?int $time)
 * @method $this set_timestarted(?int $time)
 * @method $this set_timesubmitted(?int $time)
 * @method $this set_timecompleted(?int $time)
 * @method $this set_overalllevelid(?int $levelid)
 * @method $this set_comment(?string $comment)
 * @method $this set_commentformat(int $format)
 */
class record extends persistent {
    /** @var string The database table. */
    const TABLE = 'local_appraisal_record';

    /** @var int Stored in database - DO NOT CHANGE. */
    const STATUS_NOT_STARTED = 1;
    /** @var int Stored in database - DO NOT CHANGE. */
    const STATUS_IN_PROGRESS = 2;
    /** @var int Stored in database - DO NOT CHANGE. */
    const STATUS_SUBMITTED = 3;
    /** @var int Stored in database - DO NOT CHANGE. */
    const STATUS_COMPLETED = 4;

    /**
     * Return the definition of the properties of this model.
     *
     * @return array
     */
    protected static function define_properties(): array {
        $nullableint = [
            'type' => PARAM_INT,
            'null' => NULL_ALLOWED,
            'default' => null,
        ];
        return [
            'assignmentid' => [
                'type' => PARAM_INT,
            ],
            'template_versionid' => [
                'type' => PARAM_INT,
            ],
            'appraiserid' => [
                'type' => PARAM_INT,
            ],
            'status' => [
                'type' => PARAM_INT,
                'choices' => [
                    self::STATUS_NOT_STARTED,
                    self::STATUS_IN_PROGRESS,
                    self::STATUS_SUBMITTED,
                    self::STATUS_COMPLETED,
                ],
                'default' => self::STATUS_NOT_STARTED,
            ],
            'label' => [
                'type' => PARAM_TEXT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'duedate' => $nullableint,
            'timereminded' => $nullableint,
            'timestarted' => $nullableint,
            'timesubmitted' => $nullableint,
            'timecompleted' => $nullableint,
            'overalllevelid' => $nullableint,
            'comment' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'commentformat' => [
                'type' => PARAM_INT,
                'default' => FORMAT_PLAIN,
            ],
        ];
    }

    /**
     * Whether the record is still open (Not started, In progress or Submitted).
     *
     * @return bool
     */
    public function is_open(): bool {
        return $this->get('status') !== self::STATUS_COMPLETED;
    }

    /**
     * The display name of a status value.
     *
     * @param int $status One of the STATUS_* constants.
     * @return string
     * @throws \coding_exception If the value is not one of the STATUS_* constants.
     */
    public static function status_name(int $status): string {
        $keys = [
            self::STATUS_NOT_STARTED => 'statusnotstarted',
            self::STATUS_IN_PROGRESS => 'statusinprogress',
            self::STATUS_SUBMITTED => 'statussubmitted',
            self::STATUS_COMPLETED => 'statuscompleted',
        ];
        if (!isset($keys[$status])) {
            throw new \coding_exception('Unknown record status: ' . $status);
        }
        return get_string($keys[$status], 'local_appraisal');
    }

    /**
     * The status as a display string.
     *
     * @return string
     */
    public function get_status_name(): string {
        return self::status_name($this->get('status'));
    }
}
