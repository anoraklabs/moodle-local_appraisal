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
 * An optional heading, with a description, grouping the fields of a template version.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @property int $id
 * @property int $template_versionid
 * @property int $sortorder
 * @property string $name
 * @property string|null $description
 * @property int $descriptionformat
 * @property int $timecreated
 * @property int $timemodified
 * @property int $usermodified
 *
 * @method int get_template_versionid()
 * @method int get_sortorder()
 * @method string get_name()
 * @method string|null get_description()
 * @method int get_descriptionformat()
 * @method $this set_template_versionid(int $versionid)
 * @method $this set_sortorder(int $sortorder)
 * @method $this set_name(string $name)
 * @method $this set_description(?string $description)
 * @method $this set_descriptionformat(int $format)
 */
class template_section extends persistent {
    /** @var string The database table. */
    const TABLE = 'local_appraisal_template_section';

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
            'sortorder' => [
                'type' => PARAM_INT,
                'default' => 0,
            ],
            'name' => [
                'type' => PARAM_TEXT,
            ],
            'description' => [
                'type' => PARAM_RAW,
                'null' => NULL_ALLOWED,
                'default' => null,
            ],
            'descriptionformat' => [
                'type' => PARAM_INT,
                'default' => FORMAT_HTML,
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
