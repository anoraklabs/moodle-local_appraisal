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
 * Sesskey-protected record and assignment actions: submit, hand back, sign off, reset, take ownership, cancel, change
 * details, start a new cycle, remove an assignment and delete an appraisal.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\context\system;
use core\output\notification;
use local_appraisal\access;
use local_appraisal\api;
use local_appraisal\form\details_form;
use local_appraisal\form\handback_form;
use local_appraisal\form\signoff_form;
use local_appraisal\form\start_form;
use local_appraisal\template_version;

require(__DIR__ . '/../../config.php');

$action = required_param('action', PARAM_ALPHA);
$id = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
// Where Reset, Cancel and Change due date and label go back to: the record, or the dashboard they were offered on.
$returnto = optional_param('returnto', 'record', PARAM_ALPHA) === 'dashboard' ? 'dashboard' : 'record';

require_login(null, false);
require_sesskey();

$context = system::instance();
$userid = (int) $USER->id;
$url = new moodle_url('/local/appraisal/recordaction.php', ['action' => $action, 'id' => $id]);
if ($returnto === 'dashboard') {
    $url->param('returnto', $returnto);
}
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('pluginname', 'local_appraisal'));
$PAGE->set_heading(get_string('pluginname', 'local_appraisal'));

$recordurl = fn(int $recordid): moodle_url => new moodle_url('/local/appraisal/record.php', ['id' => $recordid]);
$dashboardurl = fn(int $appraiseeid): moodle_url => new moodle_url('/local/appraisal/dashboard.php', ['userid' => $appraiseeid]);

/**
 * Show a confirmation page unless the action has already been confirmed.
 *
 * @param bool $confirm Whether the user has confirmed.
 * @param string $message The question to ask.
 * @param moodle_url $continueurl Where the confirming request goes.
 * @param moodle_url $cancelurl Where cancel goes.
 * @return void
 */
function local_appraisal_confirm_record_action(
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

/**
 * Go back to a page with a warning, for an action that no longer applies (the page it was offered on was stale).
 *
 * @param moodle_url $to
 * @param string $message
 * @return void
 */
function local_appraisal_refuse_record_action(moodle_url $to, string $message): void {
    redirect($to, $message, null, notification::NOTIFY_WARNING);
}

$assignmentactions = ['startcycle', 'removeassignment', 'deleteassignment'];
if (in_array($action, $assignmentactions, true)) {
    $assignment = api::get_assignment($id);
    $appraiseeid = $assignment->get('userid');
    $template = api::get_template($assignment->get('templateid'));
} else {
    $ctx = api::require_record_access($id, $userid);
    $record = $ctx->record;
    $assignment = $ctx->assignment;
    $appraiseeid = $assignment->get('userid');
    $template = $ctx->template;
    $actions = api::get_record_actions($ctx, $userid);
}
$appraisee = core_user::get_user($appraiseeid, '*', MUST_EXIST);
$templatename = format_string($template->get('name'));

if ($action === 'deleteassignment') {
    if (!has_capability('local/appraisal:deleteappraisals', $context)) {
        throw new required_capability_exception($context, 'local/appraisal:deleteappraisals', 'nopermissions', '');
    }
    if (!access::can_delete_appraisal($userid, $appraiseeid)) {
        throw new moodle_exception('error:nodashboardaccess', 'local_appraisal');
    }
} else if (in_array($action, $assignmentactions, true) && !access::can_appraise($userid, $appraiseeid)) {
    throw new moodle_exception('error:nodashboardaccess', 'local_appraisal');
}

// Breadcrumbs: the appraisee's dashboard, then the record when there is one.
if ($userid === $appraiseeid) {
    $PAGE->navbar->add(get_string('myappraisals', 'local_appraisal'), $dashboardurl($appraiseeid));
} else {
    $PAGE->navbar->add(get_string('peopleiappraise', 'local_appraisal'), new moodle_url('/local/appraisal/people.php'));
    $PAGE->navbar->add(fullname($appraisee), $dashboardurl($appraiseeid));
}
if (isset($ctx)) {
    $PAGE->navbar->add($templatename, $recordurl($id));
}

// Names go into messages that are printed as HTML, so each is escaped here; the template name already is.
$names = (object) ['name' => s(fullname($appraisee)), 'template' => $templatename];
$backurl = $returnto === 'dashboard' ? $dashboardurl($appraiseeid) : $recordurl($id);

switch ($action) {
    case 'submit':
        if (!$actions->submit) {
            local_appraisal_refuse_record_action($recordurl($id), get_string('error:cannotsubmit', 'local_appraisal'));
        }
        if ($actions->submitoutstanding) {
            local_appraisal_refuse_record_action($recordurl($id), s(implode(' ', $actions->submitoutstanding)));
        }
        $owner = core_user::get_user($record->get('appraiserid'));
        $message = get_string('confirmsubmit', 'local_appraisal', (object) [
            'template' => $templatename,
            'appraiser' => $owner ? s(fullname($owner)) : get_string('deleteduser', 'local_appraisal'),
        ]);
        local_appraisal_confirm_record_action($confirm, $message, $url, $recordurl($id));
        api::submit_part($id, $userid);
        redirect($recordurl($id), get_string('partsubmitted', 'local_appraisal'), null, notification::NOTIFY_SUCCESS);
        break;

    case 'handback':
        if (!$actions->handback) {
            local_appraisal_refuse_record_action($recordurl($id), get_string('error:cannothandback', 'local_appraisal'));
        }
        $message = get_string('confirmhandback', 'local_appraisal', $names);
        $form = new handback_form(new moodle_url($url, ['sesskey' => sesskey()]), ['message' => $message]);
        if ($form->is_cancelled()) {
            redirect($recordurl($id));
        }
        if ($data = $form->get_data()) {
            api::hand_back($id, $userid, $data->comment);
            redirect(
                $recordurl($id),
                get_string('handedback', 'local_appraisal', $names->name),
                null,
                notification::NOTIFY_SUCCESS
            );
        }
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('handback', 'local_appraisal'));
        $form->display();
        echo $OUTPUT->footer();
        break;

    case 'signoff':
        if (!$actions->signoff) {
            local_appraisal_refuse_record_action($recordurl($id), get_string('error:cannotsignoff', 'local_appraisal'));
        }
        if ($actions->signoffoutstanding) {
            local_appraisal_refuse_record_action($recordurl($id), s(implode(' ', $actions->signoffoutstanding)));
        }
        $message = get_string('confirmsignoff', 'local_appraisal', $names);
        $levels = [];
        if ($ctx->version->get('overallgrademode') !== template_version::GRADE_OFF) {
            $levels = api::get_scale_levels($ctx->version->get('overallscaleid'));
        }
        $form = new signoff_form(
            new moodle_url($url, ['sesskey' => sesskey()]),
            ['record' => $record, 'version' => $ctx->version, 'levels' => $levels, 'message' => $message]
        );
        if ($form->is_cancelled()) {
            redirect($recordurl($id));
        }
        if ($data = $form->get_data()) {
            $levelid = empty($data->levelid) ? null : (int) $data->levelid;
            api::sign_off($id, $userid, $levelid, api::completion_date_from_day((int) $data->completiondate, time()));
            redirect($recordurl($id), get_string('signedoff', 'local_appraisal'), null, notification::NOTIFY_SUCCESS);
        }
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('signoff', 'local_appraisal'));
        $form->display();
        echo $OUTPUT->footer();
        break;

    case 'reset':
        if (!$actions->reset) {
            local_appraisal_refuse_record_action($backurl, get_string('error:cannotreset', 'local_appraisal'));
        }
        $message = get_string('confirmreset', 'local_appraisal', $names->name);
        local_appraisal_confirm_record_action($confirm, $message, $url, $backurl);
        api::reset_record($id, $userid);
        redirect($backurl, get_string('recordreset', 'local_appraisal'), null, notification::NOTIFY_SUCCESS);
        break;

    case 'takeownership':
        if (!$actions->takeownership) {
            local_appraisal_refuse_record_action($recordurl($id), get_string('error:cannottakeownership', 'local_appraisal'));
        }
        $owner = core_user::get_user($record->get('appraiserid'));
        $names->owner = $owner ? s(fullname($owner)) : get_string('deleteduser', 'local_appraisal');
        $message = get_string('confirmtakeownership', 'local_appraisal', $names);
        local_appraisal_confirm_record_action($confirm, $message, $url, $recordurl($id));
        api::take_ownership($id, $userid);
        redirect($recordurl($id), get_string('ownershiptaken', 'local_appraisal'), null, notification::NOTIFY_SUCCESS);
        break;

    case 'cancel':
        // The page may be stale: an answer can have been saved after it loaded, or in another tab.
        if (!$actions->cancel) {
            local_appraisal_refuse_record_action($backurl, get_string('error:cannotcancel', 'local_appraisal'));
        }
        $message = get_string('confirmcancelrecord', 'local_appraisal', $names);
        local_appraisal_confirm_record_action($confirm, $message, $url, $backurl);
        api::cancel_record($id, $userid);
        redirect(
            $dashboardurl($appraiseeid),
            get_string('recordcancelled', 'local_appraisal'),
            null,
            notification::NOTIFY_SUCCESS
        );
        break;

    case 'editdetails':
        if (!$actions->editdetails) {
            local_appraisal_refuse_record_action($backurl, get_string('error:cannotact', 'local_appraisal'));
        }
        $form = new details_form(new moodle_url($url, ['sesskey' => sesskey()]));
        if ($form->is_cancelled()) {
            redirect($backurl);
        }
        if ($data = $form->get_data()) {
            $duedate = !empty($data->duedate) ? api::due_date_from_day((int) $data->duedate) : null;
            api::update_record_details($id, $userid, $data->label, $duedate);
            redirect($backurl, get_string('detailssaved', 'local_appraisal'), null, notification::NOTIFY_SUCCESS);
        }
        if (!$form->is_submitted()) {
            // The stored due date is the end of the day in the site timezone; the selector wants that same calendar day
            // in the user's own timezone.
            $due = $record->get('duedate');
            $day = null;
            if ($due !== null) {
                $parts = usergetdate($due, api::site_timezone());
                $day = make_timestamp($parts['year'], $parts['mon'], $parts['mday'], 12);
            }
            $form->set_data(['label' => $record->get('label'), 'duedate' => $day]);
        }
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('changedetails', 'local_appraisal'));
        $form->display();
        echo $OUTPUT->footer();
        break;

    case 'startcycle':
        if ($blocker = api::get_start_blocker($assignment)) {
            local_appraisal_refuse_record_action($dashboardurl($appraiseeid), get_string($blocker, 'local_appraisal'));
        }
        $form = new start_form(new moodle_url($url, ['sesskey' => sesskey()]));
        if ($form->is_cancelled()) {
            redirect($dashboardurl($appraiseeid));
        }
        if ($data = $form->get_data()) {
            $duedate = !empty($data->duedate) ? api::due_date_from_day((int) $data->duedate) : null;
            api::start_cycle($id, $userid, $data->label, $duedate);
            // Back to the dashboard, as after Assign template: the appraisee usually acts first.
            redirect(
                $dashboardurl($appraiseeid),
                get_string('cyclestarted', 'local_appraisal', $names->name),
                null,
                notification::NOTIFY_SUCCESS
            );
        }
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('startnewappraisalfor', 'local_appraisal', $names));
        $form->display();
        echo $OUTPUT->footer();
        break;

    case 'removeassignment':
        if ($blocker = api::get_remove_blocker($id)) {
            local_appraisal_refuse_record_action($dashboardurl($appraiseeid), get_string($blocker, 'local_appraisal'));
        }
        $message = get_string('confirmremoveassignment', 'local_appraisal', $names);
        local_appraisal_confirm_record_action($confirm, $message, $url, $dashboardurl($appraiseeid));
        api::remove_assignment($id, $userid);
        redirect(
            $dashboardurl($appraiseeid),
            get_string('assignmentremoved', 'local_appraisal'),
            null,
            notification::NOTIFY_SUCCESS
        );
        break;

    case 'deleteassignment':
        $summary = api::get_assignment_deletion_summary($id);
        $names->records = $summary->records;
        $names->completed = $summary->completed;
        $key = $summary->records ? 'confirmdeleteassignment' : 'confirmdeleteassignmentempty';
        $message = get_string($key, 'local_appraisal', $names);
        local_appraisal_confirm_record_action($confirm, $message, $url, $dashboardurl($appraiseeid));
        api::delete_assignment($id);
        redirect(
            $dashboardurl($appraiseeid),
            get_string('assignmentdeleted', 'local_appraisal'),
            null,
            notification::NOTIFY_SUCCESS
        );
        break;

    default:
        throw new moodle_exception('invalidaction', 'error');
}
