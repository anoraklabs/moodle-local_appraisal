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

/**
 * External function definitions for local_appraisal.
 *
 * These are internal AJAX endpoints for the plugin's own pages, not a public API.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_appraisal_reorder_fields' => [
        'classname' => 'local_appraisal\external\reorder_fields',
        'description' => 'Store a new order for the sections and fields of a draft template version.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/appraisal:managetemplates',
    ],
    'local_appraisal_save_answer' => [
        'classname' => 'local_appraisal\external\save_answer',
        'description' => 'Autosave one answer on a record, as the appraiser who owns it or as the appraisee.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/appraisal:appraise, local/appraisal:completeown',
    ],
    'local_appraisal_save_comment' => [
        'classname' => 'local_appraisal\external\save_comment',
        'description' => 'Autosave the overall comments on a record the caller owns as appraiser.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/appraisal:appraise',
    ],
    'local_appraisal_search_users' => [
        'classname' => 'local_appraisal\external\search_users',
        'description' => 'Find a user to appraise by name or visible identity field.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/appraisal:accessalldashboards',
    ],
    'local_appraisal_reorder_levels' => [
        'classname' => 'local_appraisal\external\reorder_levels',
        'description' => 'Store a new order for the levels of an unlocked grading scale.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/appraisal:managetemplates',
    ],
];
