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
 * A pairing of two cohorts: members of the appraiser cohort can appraise members of the appraisee cohort.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @property int $id
 * @property int $appraisercohortid
 * @property int $appraiseecohortid
 * @property int $timecreated
 * @property int $timemodified
 * @property int $usermodified
 *
 * @method int get_appraisercohortid()
 * @method int get_appraiseecohortid()
 * @method $this set_appraisercohortid(int $cohortid)
 * @method $this set_appraiseecohortid(int $cohortid)
 */
class cohort_link extends persistent {
    /** @var string The database table. */
    const TABLE = 'local_appraisal_cohort_link';

    /**
     * Return the definition of the properties of this model.
     *
     * @return array
     */
    protected static function define_properties(): array {
        return [
            'appraisercohortid' => [
                'type' => PARAM_INT,
            ],
            'appraiseecohortid' => [
                'type' => PARAM_INT,
            ],
        ];
    }

    /**
     * Validate the appraisee cohort: a cohort can't be linked to itself.
     *
     * @param int $value
     * @return true|lang_string
     */
    protected function validate_appraiseecohortid(int $value): true|lang_string {
        if ($value === $this->get('appraisercohortid')) {
            return new lang_string('error:selflink', 'local_appraisal');
        }
        return true;
    }
}
