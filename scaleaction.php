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
 * Sesskey-protected grading scale actions, with confirmation where the action cannot be undone.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use local_appraisal\api;
use local_appraisal\scale_level;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$action = required_param('action', PARAM_ALPHA);
$id = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$url = new moodle_url('/local/appraisal/scaleaction.php', ['action' => $action, 'id' => $id]);
admin_externalpage_setup('local_appraisal_scales', '', null, $url);
require_sesskey();

$listurl = new moodle_url('/local/appraisal/scales.php');
$editorurl = fn(int $scaleid): moodle_url => new moodle_url('/local/appraisal/scale.php', ['id' => $scaleid]);

/**
 * Show a confirmation page for an action that cannot be undone, unless it has already been confirmed.
 *
 * @param bool $confirm Whether the user has confirmed.
 * @param string $message The question to ask.
 * @param moodle_url $continueurl Where the confirming request goes.
 * @param moodle_url $cancelurl Where cancel goes.
 * @return void
 */
function local_appraisal_confirm_scale_action(
    bool $confirm,
    string $message,
    moodle_url $continueurl,
    moodle_url $cancelurl
): void {
    global $OUTPUT;

    if ($confirm) {
        return;
    }
    $continueurl->params(['confirm' => 1, 'sesskey' => sesskey()]);
    echo $OUTPUT->header();
    echo $OUTPUT->confirm($message, new single_button($continueurl, get_string('continue'), 'post'), $cancelurl);
    echo $OUTPUT->footer();
    exit;
}

switch ($action) {
    case 'duplicate':
        $source = api::get_scale($id);
        $name = get_string('duplicatename', 'local_appraisal', $source->get('name'));
        $copy = api::duplicate_scale($id, $name);
        redirect(
            $editorurl($copy->get('id')),
            get_string('scaleduplicated', 'local_appraisal'),
            null,
            notification::NOTIFY_SUCCESS
        );
        break;

    case 'archive':
        api::archive_scale($id);
        redirect($listurl, get_string('scalearchived', 'local_appraisal'), null, notification::NOTIFY_SUCCESS);
        break;

    case 'unarchive':
        api::unarchive_scale($id);
        redirect($listurl, get_string('scaleunarchived', 'local_appraisal'), null, notification::NOTIFY_SUCCESS);
        break;

    case 'delete':
        $scale = api::get_scale($id);
        $message = get_string('confirmdeletescale', 'local_appraisal', format_string($scale->get('name')));
        local_appraisal_confirm_scale_action($confirm, $message, $url, $listurl);
        api::delete_scale($id);
        redirect($listurl, get_string('scaledeleted', 'local_appraisal'), null, notification::NOTIFY_SUCCESS);
        break;

    case 'deletelevel':
        $level = scale_level::get_record(['id' => $id], MUST_EXIST);
        $returnurl = $editorurl($level->get('scaleid'));
        $message = get_string('confirmdeletelevel', 'local_appraisal', format_string($level->get('label')));
        local_appraisal_confirm_scale_action($confirm, $message, $url, $returnurl);
        api::delete_scale_level($id);
        redirect($returnurl, get_string('leveldeleted', 'local_appraisal'), null, notification::NOTIFY_SUCCESS);
        break;

    default:
        throw new moodle_exception('invalidaction', 'error');
}
