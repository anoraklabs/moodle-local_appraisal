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
 * The grading scale editor: name and description, and the list of levels.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use local_appraisal\api;
use local_appraisal\form\scale_form;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$id = optional_param('id', 0, PARAM_INT);

$url = new moodle_url('/local/appraisal/scale.php', array_filter(['id' => $id]));
admin_externalpage_setup('local_appraisal_scales', '', null, $url);

$listurl = new moodle_url('/local/appraisal/scales.php');
$scale = $id ? api::get_scale($id) : null;

$form = new scale_form($url);
if ($scale) {
    $form->set_data([
        'id' => $id,
        'name' => $scale->get('name'),
        'description' => $scale->get('description'),
    ]);
}

if ($form->is_cancelled()) {
    redirect($listurl);
}

if ($data = $form->get_data()) {
    $description = trim($data->description) === '' ? null : trim($data->description);
    if ($data->id) {
        api::update_scale((int) $data->id, trim($data->name), $description);
        redirect($url, get_string('scalesaved', 'local_appraisal'), null, notification::NOTIFY_SUCCESS);
    }
    $new = api::create_scale(trim($data->name), $description);
    redirect(
        new moodle_url('/local/appraisal/scale.php', ['id' => $new->get('id')]),
        get_string('scalesaved', 'local_appraisal'),
        null,
        notification::NOTIFY_SUCCESS
    );
}

$heading = $scale ? format_string($scale->get('name')) : get_string('newscale', 'local_appraisal');
$PAGE->navbar->add($heading);

echo $OUTPUT->header();
echo $OUTPUT->heading($heading);

if (!$scale) {
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

$locked = api::is_scale_locked($id);
$actionurl = fn(string $action, int $targetid): string => (new moodle_url('/local/appraisal/scaleaction.php', [
    'action' => $action,
    'id' => $targetid,
    'sesskey' => sesskey(),
]))->out(false);

$levels = [];
foreach (api::get_scale_levels($id) as $level) {
    $levels[] = [
        'id' => $level->get('id'),
        'sortorder' => $level->get('sortorder'),
        'label' => format_string($level->get('label')),
        'value' => format_float((float) $level->get('value'), 2),
        'description' => $level->get('description'),
        'deleteurl' => $locked ? null : $actionurl('deletelevel', $level->get('id')),
    ];
}

echo $OUTPUT->render_from_template('local_appraisal/scale_editor', [
    'scaleid' => $id,
    'locked' => $locked,
    'archived' => !$scale->is_active(),
    'notusable' => count($levels) < 2,
    'levels' => $levels,
    'haslevels' => !empty($levels),
    'listurl' => $listurl->out(false),
    'detailsform' => $form->render(),
]);
$PAGE->requires->js_call_amd('local_appraisal/scale_editor', 'init', [$id]);
echo $OUTPUT->footer();
