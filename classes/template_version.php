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
 * A content set of a template: its sections, fields and settings.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @property int $id
 * @property int $templateid
 * @property int $versionnumber
 * @property string|null $overview
 * @property int $overviewformat
 * @property int $commentsmode
 * @property int $overallgrademode
 * @property int|null $overallscaleid
 * @property int|null $reminderdays
 * @property int $status
 * @property int $timecreated
 * @property int $timemodified
 * @property int $usermodified
 *
 * @method int get_templateid()
 * @method int get_versionnumber()
 * @method string|null get_overview()
 * @method int get_overviewformat()
 * @method int get_commentsmode()
 * @method int get_overallgrademode()
 * @method int|null get_overallscaleid()
 * @method int|null get_reminderdays()
 * @method int get_status()
 * @method $this set_templateid(int $templateid)
 * @method $this set_versionnumber(int $versionnumber)
 * @method $this set_overview(?string $overview)
 * @method $this set_overviewformat(int $format)
 * @method $this set_commentsmode(int $mode)
 * @method $this set_overallgrademode(int $mode)
 * @method $this set_overallscaleid(?int $scaleid)
 * @method $this set_reminderdays(?int $reminderdays)
 * @method $this set_status(int $status)
 */
class template_version extends persistent {
    /** @var string The database table. */
    const TABLE = 'local_appraisal_template_version';

    /** @var int Stored in database - DO NOT CHANGE. */
    const STATUS_DRAFT = 1;
    /** @var int Stored in database - DO NOT CHANGE. */
    const STATUS_PUBLISHED = 2;
    /** @var int Stored in database - DO NOT CHANGE. */
    const STATUS_ARCHIVED = 3;

    /** @var int The appraiser's overall comments are not offered. Stored in database - DO NOT CHANGE. */
    const COMMENTS_OFF = 1;
    /** @var int The overall comments are offered but may be left empty. Stored in database - DO NOT CHANGE. */
    const COMMENTS_OPTIONAL = 2;
    /** @var int The overall comments must be written before sign-off. Stored in database - DO NOT CHANGE. */
    const COMMENTS_REQUIRED = 3;

    /** @var int There is no overall grade. Stored in database - DO NOT CHANGE. */
    const GRADE_OFF = 1;
    /** @var int An overall grade is offered but may be left empty. Stored in database - DO NOT CHANGE. */
    const GRADE_OPTIONAL = 2;
    /** @var int An overall grade must be chosen before sign-off. Stored in database - DO NOT CHANGE. */
    const GRADE_REQUIRED = 3;

    /**
     * Return the definition of the properties of this model.
     *
     * @return array
     */
    protected static function define_properties(): array {
        return [
            'templateid' => [
                'type' => PARAM_INT,
            ],
            'versionnumber' => [
                'type' => PARAM_INT,
                'default' => 1,
            ],
            'overview' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'overviewformat' => [
                'type' => PARAM_INT,
                'default' => FORMAT_HTML,
            ],
            'commentsmode' => [
                'type' => PARAM_INT,
                'choices' => [self::COMMENTS_OFF, self::COMMENTS_OPTIONAL, self::COMMENTS_REQUIRED],
                'default' => self::COMMENTS_OPTIONAL,
            ],
            'overallgrademode' => [
                'type' => PARAM_INT,
                'choices' => [self::GRADE_OFF, self::GRADE_OPTIONAL, self::GRADE_REQUIRED],
                'default' => self::GRADE_OFF,
            ],
            'overallscaleid' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'reminderdays' => [
                'type' => PARAM_INT,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'status' => [
                'type' => PARAM_INT,
                'choices' => [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED],
                'default' => self::STATUS_DRAFT,
            ],
        ];
    }

    /**
     * Validate the overall grade scale: one is needed whenever an overall grade is offered.
     *
     * @param int|null $value
     * @return true|lang_string
     */
    protected function validate_overallscaleid(?int $value): true|lang_string {
        if ($value === null && $this->get('overallgrademode') !== self::GRADE_OFF) {
            return new lang_string('error:overallscalerequired', 'local_appraisal');
        }
        return true;
    }

    /**
     * Validate the reminder lead time.
     *
     * @param int|null $value
     * @return true|lang_string
     */
    protected function validate_reminderdays(?int $value): true|lang_string {
        if ($value !== null && $value < 1) {
            return new lang_string('error:reminderdayspositive', 'local_appraisal');
        }
        return true;
    }

    /**
     * Whether the version is a draft.
     *
     * @return bool
     */
    public function is_draft(): bool {
        return $this->get('status') === self::STATUS_DRAFT;
    }

    /**
     * Whether the version is published.
     *
     * @return bool
     */
    public function is_published(): bool {
        return $this->get('status') === self::STATUS_PUBLISHED;
    }

    /**
     * The status as a display string.
     *
     * @return string
     */
    public function get_status_name(): string {
        $keys = [
            self::STATUS_DRAFT => 'statusdraft',
            self::STATUS_PUBLISHED => 'statuspublished',
            self::STATUS_ARCHIVED => 'statusarchived',
        ];
        return get_string($keys[$this->get('status')], 'local_appraisal');
    }

    /**
     * The display names of the overall comments modes, keyed by value.
     *
     * @return string[]
     */
    public static function get_comments_mode_options(): array {
        return [
            self::COMMENTS_OFF => get_string('modeoff', 'local_appraisal'),
            self::COMMENTS_OPTIONAL => get_string('modeoptional', 'local_appraisal'),
            self::COMMENTS_REQUIRED => get_string('moderequired', 'local_appraisal'),
        ];
    }

    /**
     * The display names of the overall grade modes, keyed by value.
     *
     * @return string[]
     */
    public static function get_grade_mode_options(): array {
        return [
            self::GRADE_OFF => get_string('modeoff', 'local_appraisal'),
            self::GRADE_OPTIONAL => get_string('modeoptional', 'local_appraisal'),
            self::GRADE_REQUIRED => get_string('moderequired', 'local_appraisal'),
        ];
    }

    /**
     * The overall comments setting as a display string.
     *
     * @return string
     */
    public function get_comments_summary(): string {
        return self::get_comments_mode_options()[$this->get('commentsmode')];
    }

    /**
     * The overall grade setting as a display string, with the scale's name when a grade is offered.
     *
     * @param string $scalename The plain-text name of the overall grade scale, escaped by the template.
     * @return string
     */
    public function get_grade_summary(string $scalename): string {
        $mode = self::get_grade_mode_options()[$this->get('overallgrademode')];
        return $this->get('overallgrademode') === self::GRADE_OFF ? $mode : $mode . ' (' . $scalename . ')';
    }

    /**
     * The due date reminder setting as a display string.
     *
     * @return string
     */
    public function get_reminder_summary(): string {
        if ($this->get('reminderdays') === null) {
            return get_string('noreminder', 'local_appraisal');
        }
        return get_string('remindersummary', 'local_appraisal', $this->get('reminderdays'));
    }
}
