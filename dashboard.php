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
 * One user's appraisal dashboard: their own rows for the user themselves, with Assign template for an appraiser who
 * can reach them.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\context\system;
use core\di;
use core\hook\manager;
use local_appraisal\access;
use local_appraisal\api;
use local_appraisal\hook\before_dashboard_actions_exported;
use local_appraisal\output\person_heading;
use local_appraisal\output\row_actions;
use local_appraisal\template;

require(__DIR__ . '/../../config.php');

$userid = optional_param('userid', 0, PARAM_INT) ?: (int) $USER->id;
$showhistory = optional_param('history', 0, PARAM_BOOL);
$page = optional_param('page', 0, PARAM_INT);
$templatefilter = optional_param('templateid', 0, PARAM_INT);

require_login(null, false);
$user = core_user::get_user($userid, '*', MUST_EXIST);
if ($user->deleted) {
    throw new moodle_exception('invaliduser', 'error');
}
access::require_dashboard_access((int) $USER->id, $userid);

$isself = $userid === (int) $USER->id;
$canact = access::can_appraise((int) $USER->id, $userid);
$context = system::instance();
$url = new moodle_url('/local/appraisal/dashboard.php', ['userid' => $userid]);
$now = di::get(\core\clock::class)->time();
$dateformat = get_string('strftimedate');

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('dashboard', 'local_appraisal'));
// Someone else's dashboard prints its own heading, with their picture; see person_heading.
$PAGE->set_heading($isself ? get_string('myappraisals', 'local_appraisal') : '');
if (!$isself) {
    $PAGE->navbar->add(get_string('peopleiappraise', 'local_appraisal'), new moodle_url('/local/appraisal/people.php'));
    $PAGE->navbar->add(fullname($user));
} else {
    $PAGE->navbar->add(get_string('myappraisals', 'local_appraisal'));
}

$recordurl = fn(int $recordid): string => (new moodle_url('/local/appraisal/record.php', ['id' => $recordid]))->out(false);
$actionurl = fn(string $action, int $targetid): string => (new moodle_url('/local/appraisal/recordaction.php', [
    'action' => $action,
    'id' => $targetid,
    'sesskey' => sesskey(),
    // Record actions come back here rather than to the record; the assignment actions ignore it.
    'returnto' => 'dashboard',
]))->out(false);
$candelete = access::can_delete_appraisal((int) $USER->id, $userid);
$current = [];
foreach (api::get_dashboard_rows($userid, $now) as $row) {
    $open = $row->open;
    $cycle = '';
    if ($open) {
        $label = $open->get('label');
        $cycle = ($label !== null ? $label . ' · ' : '') . $open->get_status_name();
    }
    $last = '';
    if ($row->last) {
        $last = s(userdate($row->last->get('timecompleted'), $dateformat));
        if ($row->lastgrade !== null) {
            $last .= ' · ' . format_string($row->lastgrade);
        }
    }
    // The actions in order of use: the first is shown as a button and the rest go in a kebab menu, as core's lists do,
    // with the destructive ones last and in red.
    // The same rules as the record page's footer, so the two never offer different actions.
    $recordactions = $open ? api::get_record_actions(api::get_record_context($open->get('id')), (int) $USER->id) : null;
    $actions = [];
    if ($open) {
        $actions[] = ['label' => get_string('openappraisal', 'local_appraisal'), 'icon' => 't/edit',
            'url' => $recordurl($open->get('id'))];
    }
    if ($row->last) {
        $actions[] = ['label' => get_string('viewlastcompleted', 'local_appraisal'), 'icon' => 't/preview',
            'url' => $recordurl($row->last->get('id'))];
    }
    if ($recordactions && $recordactions->editdetails) {
        $actions[] = ['label' => get_string('changedetails', 'local_appraisal'), 'icon' => 't/edit',
            'url' => $actionurl('editdetails', $open->get('id'))];
    }
    if ($canact && !$open && (int) $row->templatestatus === template::STATUS_ACTIVE) {
        $actions[] = ['label' => get_string('startnewappraisal', 'local_appraisal'), 'icon' => 't/add',
            'url' => $actionurl('startcycle', (int) $row->assignmentid)];
    }
    if ($recordactions && $recordactions->reset) {
        $actions[] = ['label' => get_string('reset', 'local_appraisal'), 'icon' => 'i/reload', 'danger' => true,
            'url' => $actionurl('reset', $open->get('id'))];
    }
    if ($recordactions && $recordactions->cancel) {
        $actions[] = ['label' => get_string('cancelappraisal', 'local_appraisal'), 'icon' => 't/delete', 'danger' => true,
            'url' => $actionurl('cancel', $open->get('id'))];
    }
    if ($canact && !api::get_remove_blocker((int) $row->assignmentid)) {
        $actions[] = ['label' => get_string('removeassignment', 'local_appraisal'), 'icon' => 't/delete', 'danger' => true,
            'url' => $actionurl('removeassignment', (int) $row->assignmentid)];
    }
    if ($candelete) {
        $actions[] = ['label' => get_string('deleteappraisal', 'local_appraisal'), 'icon' => 't/delete', 'danger' => true,
            'url' => $actionurl('deleteassignment', (int) $row->assignmentid)];
    }
    $primary = $actions && empty($actions[0]['danger']) ? array_shift($actions) : null;
    $current[] = [
        'primaryaction' => $primary ? ['label' => $primary['label'], 'url' => $primary['url']] : null,
        'actionmenu' => row_actions::render($actions, $PAGE->get_renderer('core')),
        'template' => format_string($row->templatename),
        'archived' => (int) $row->templatestatus === template::STATUS_ARCHIVED,
        'cycle' => $cycle,
        'due' => $open && $open->get('duedate') !== null
            ? userdate($open->get('duedate'), $dateformat, api::site_timezone())
            : '',
        'overdue' => $row->overdue,
        'last' => $last,
    ];
}

$history = ['rows' => [], 'total' => 0];
$historyfilter = null;
$historyurl = new moodle_url($url, ['history' => 1]);
$perpage = 20;
if ($showhistory) {
    // Filter by template, offered once there is more than one template to choose from.
    $historytemplates = api::get_completed_templates($userid);
    if (!isset($historytemplates[$templatefilter])) {
        $templatefilter = 0;
    }
    if (count($historytemplates) > 1) {
        // All templates is a real option, not single_select's $nothing, which its auto-submit ignores when chosen.
        $options = [0 => get_string('alltemplates', 'local_appraisal')]
            + array_map(fn(string $name): string => format_string($name), $historytemplates);
        $select = new single_select($historyurl, 'templateid', $options, $templatefilter, null, 'local-appraisal-history-filter');
        $select->set_label(get_string('template', 'local_appraisal'), ['class' => 'me-2']);
        $historyfilter = $OUTPUT->render($select);
    }
    if ($templatefilter) {
        $historyurl->param('templateid', $templatefilter);
    }
    $history = api::get_completed_cycles($userid, $page, $perpage, $templatefilter);
}
$historyrows = [];
foreach ($history['rows'] as $row) {
    $appraiser = '';
    if ((int) $row->appraiserid === api::APPRAISER_DELETED) {
        // An appraiser whose account was anonymised away is shown as a deleted user.
        $appraiser = get_string('deleteduser', 'local_appraisal');
    } else if ($row->appraiser_firstname !== null) {
        $appraiser = fullname((object) [
            'firstname' => $row->appraiser_firstname,
            'lastname' => $row->appraiser_lastname,
            'firstnamephonetic' => $row->appraiser_firstnamephonetic,
            'lastnamephonetic' => $row->appraiser_lastnamephonetic,
            'middlename' => $row->appraiser_middlename,
            'alternatename' => $row->appraiser_alternatename,
        ]);
    }
    $historyrows[] = [
        'template' => format_string($row->templatename),
        'cycle' => $row->label ?? '',
        'completed' => userdate($row->timecompleted, $dateformat),
        'grade' => $row->gradelabel !== null ? format_string($row->gradelabel) : '',
        'appraiser' => $appraiser,
        'url' => $recordurl((int) $row->id),
    ];
}

$assignable = $canact ? api::get_assignable_templates($userid) : [];
$hook = new before_dashboard_actions_exported($userid);
di::get(manager::class)->dispatch($hook);

echo $OUTPUT->header();
if (!$isself) {
    echo person_heading::render($user, $OUTPUT);
}
echo $OUTPUT->render_from_template('local_appraisal/dashboard', [
    'hascurrent' => !empty($current),
    'current' => $current,
    'assignurl' => $assignable ? (new moodle_url('/local/appraisal/assign.php', ['userid' => $userid]))->out(false) : null,
    'extraactions' => $hook->get_actions(),
    'showhistory' => $showhistory,
    'historyurl' => $historyurl->out(false),
    'hidehistoryurl' => $url->out(false),
    'historyfilter' => $historyfilter,
    'hashistory' => !empty($historyrows),
    'historyrows' => $historyrows,
]);
if ($showhistory) {
    echo $OUTPUT->paging_bar($history['total'], $page, $perpage, $historyurl);
}
echo $OUTPUT->footer();
