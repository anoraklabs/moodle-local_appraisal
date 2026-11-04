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
 * Sesskey-protected template and version actions, with confirmation where the action cannot be undone.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use local_appraisal\access;
use local_appraisal\api;
use local_appraisal\template_field;
use local_appraisal\template_section;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$action = required_param('action', PARAM_ALPHA);
$id = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$url = new moodle_url('/local/appraisal/action.php', ['action' => $action, 'id' => $id]);
admin_externalpage_setup('local_appraisal_templates', '', null, $url);
require_sesskey();

$listurl = new moodle_url('/local/appraisal/templates.php');
$versionurl = fn(int $versionid): moodle_url => new moodle_url('/local/appraisal/templateversion.php', ['id' => $versionid]);

/**
 * Show a confirmation page for an action that cannot be undone, unless it has already been confirmed.
 *
 * @param bool $confirm Whether the user has confirmed.
 * @param string $message The question to ask.
 * @param moodle_url $continueurl Where the confirming request goes.
 * @param moodle_url $cancelurl Where cancel goes.
 * @return void
 */
function local_appraisal_confirm_action(
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
    case 'createdraft':
        $draft = api::create_draft($id);
        redirect($versionurl($draft->get('id')));
        break;

    case 'archive':
        $template = api::get_template($id);
        $message = get_string('confirmarchive', 'local_appraisal', format_string($template->get('name')));
        local_appraisal_confirm_action($confirm, $message, $url, $listurl);
        api::archive_template($id);
        redirect($listurl, get_string('templatearchived', 'local_appraisal'), null, notification::NOTIFY_SUCCESS);
        break;

    case 'deletetemplate':
        // Refused here as well as hidden in the list, since the list may be stale or the URL hand-made.
        if (!access::can_delete_template($id)) {
            throw new required_capability_exception($PAGE->context, 'local/appraisal:deleteappraisals', 'nopermissions', '');
        }
        $template = api::get_template($id);
        $summary = api::get_template_deletion_summary($id);
        $summary->name = format_string($template->get('name'));
        $key = $summary->assignments ? 'confirmdeletetemplate' : 'confirmdeletetemplateempty';
        local_appraisal_confirm_action($confirm, get_string($key, 'local_appraisal', $summary), $url, $listurl);
        api::delete_template($id);
        redirect($listurl, get_string('templatedeleted', 'local_appraisal'), null, notification::NOTIFY_SUCCESS);
        break;

    case 'unarchive':
        api::unarchive_template($id);
        redirect($listurl, get_string('templateunarchived', 'local_appraisal'), null, notification::NOTIFY_SUCCESS);
        break;

    case 'publish':
        $version = api::get_version($id);
        $template = api::get_template($version->get('templateid'));
        $previous = api::get_published_version($template->get('id'));
        $message = get_string('confirmpublish', 'local_appraisal', (object) [
            'name' => format_string($template->get('name')),
            'version' => $version->get('versionnumber'),
        ]);
        if ($previous) {
            $message .= ' ' . get_string('confirmpublishprevious', 'local_appraisal', $previous->get('versionnumber'));
        }
        local_appraisal_confirm_action($confirm, $message, $url, $versionurl($id));
        api::publish_version($id);
        $message = get_string('versionpublished', 'local_appraisal', $version->get('versionnumber'));
        redirect($versionurl($id), $message, null, notification::NOTIFY_SUCCESS);
        break;

    case 'discard':
        $message = get_string('confirmdiscarddraft', 'local_appraisal');
        local_appraisal_confirm_action($confirm, $message, $url, $versionurl($id));
        api::discard_draft($id);
        redirect($listurl, get_string('draftdiscarded', 'local_appraisal'), null, notification::NOTIFY_SUCCESS);
        break;

    case 'deletefield':
        $field = template_field::get_record(['id' => $id], MUST_EXIST);
        $returnurl = $versionurl($field->get('template_versionid'));
        $message = get_string('confirmdeletefield', 'local_appraisal');
        local_appraisal_confirm_action($confirm, $message, $url, $returnurl);
        api::delete_field($id);
        redirect($returnurl, get_string('fielddeleted', 'local_appraisal'), null, notification::NOTIFY_SUCCESS);
        break;

    case 'deletesection':
        $section = template_section::get_record(['id' => $id], MUST_EXIST);
        $returnurl = $versionurl($section->get('template_versionid'));
        $message = get_string('confirmdeletesection', 'local_appraisal', (object) [
            'name' => format_string($section->get('name')),
            'fields' => template_field::count_records(['sectionid' => $id]),
        ]);
        local_appraisal_confirm_action($confirm, $message, $url, $returnurl);
        api::delete_section($id);
        redirect($returnurl, get_string('sectiondeleted', 'local_appraisal'), null, notification::NOTIFY_SUCCESS);
        break;

    case 'removesections':
        $message = get_string('confirmremovesections', 'local_appraisal');
        local_appraisal_confirm_action($confirm, $message, $url, $versionurl($id));
        api::remove_sections($id);
        redirect($versionurl($id), get_string('sectionsremoved', 'local_appraisal'), null, notification::NOTIFY_SUCCESS);
        break;

    default:
        throw new moodle_exception('invalidaction', 'error');
}
