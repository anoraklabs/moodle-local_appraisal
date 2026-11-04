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
 * Appraiser cohorts: which cohort's members can appraise which cohort's members.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_appraisal\api;
use local_appraisal\output\row_actions;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_appraisal_cohortlinks');

$rows = [];
foreach (api::get_cohort_link_rows() as $link) {
    $actions = [[
        'label' => get_string('delete'),
        'icon' => 't/delete',
        'danger' => true,
        'url' => new moodle_url('/local/appraisal/cohortlinkaction.php', [
            'action' => 'delete',
            'id' => $link->id,
            'sesskey' => sesskey(),
        ]),
    ]];
    $rows[] = [
        'appraisercohort' => format_string($link->appraisername),
        'appraisers' => $link->appraisercount,
        'appraiseecohort' => format_string($link->appraiseename),
        'appraisees' => $link->appraiseecount,
        'actionmenu' => row_actions::render($actions, $PAGE->get_renderer('core')),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('appraisercohorts', 'local_appraisal'));
echo html_writer::tag('p', get_string('appraisercohorts_intro', 'local_appraisal'));
echo $OUTPUT->render_from_template('local_appraisal/cohort_link_list', [
    'haslinks' => !empty($rows),
    'links' => $rows,
]);
$PAGE->requires->js_call_amd('local_appraisal/cohort_links', 'init');
echo $OUTPUT->footer();
