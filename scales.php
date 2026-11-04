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
 * Manage grading scales.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_appraisal\api;
use local_appraisal\output\row_actions;
use local_appraisal\scale;
use local_appraisal\scale_level;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_appraisal_scales');

$levelcounts = $DB->get_records_sql_menu(
    "SELECT scaleid, COUNT(id) AS levels
       FROM {" . scale_level::TABLE . "}
   GROUP BY scaleid"
);
$usage = api::get_scale_usage();

$rows = [];
// Archived scales after the active ones.
foreach (scale::get_records([], 'status, name') as $scale) {
    $scaleid = $scale->get('id');
    $used = (int) ($usage[$scaleid]->templates ?? 0);
    $actionurl = fn(string $action): string => (new moodle_url('/local/appraisal/scaleaction.php', [
        'action' => $action,
        'id' => $scaleid,
        'sesskey' => sesskey(),
    ]))->out(false);

    // In a kebab menu, as core's management lists do, with Delete last and only when nothing uses the scale.
    $actions = [
        ['label' => get_string('edit'), 'icon' => 't/edit',
            'url' => new moodle_url('/local/appraisal/scale.php', ['id' => $scaleid])],
        ['label' => get_string('duplicate', 'local_appraisal'), 'icon' => 't/copy', 'url' => $actionurl('duplicate')],
        $scale->is_active()
            ? ['label' => get_string('archive', 'local_appraisal'), 'icon' => 't/hide', 'url' => $actionurl('archive')]
            : ['label' => get_string('unarchive', 'local_appraisal'), 'icon' => 't/show', 'url' => $actionurl('unarchive')],
    ];
    if (!$used) {
        $actions[] = ['label' => get_string('delete'), 'icon' => 't/delete', 'danger' => true,
            'url' => $actionurl('delete')];
    }

    $rows[] = [
        'name' => format_string($scale->get('name')),
        'url' => (new moodle_url('/local/appraisal/scale.php', ['id' => $scaleid]))->out(false),
        'levels' => (int) ($levelcounts[$scaleid] ?? 0),
        'archived' => !$scale->is_active(),
        'usedby' => $used,
        'actionmenu' => row_actions::render($actions, $PAGE->get_renderer('core')),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('gradingscales', 'local_appraisal'));
echo $OUTPUT->render_from_template('local_appraisal/scale_list', [
    'newurl' => (new moodle_url('/local/appraisal/scale.php'))->out(false),
    'hasscales' => !empty($rows),
    'scales' => $rows,
]);
echo $OUTPUT->footer();
