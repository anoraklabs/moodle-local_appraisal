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
 * One appraisal cycle, used by both people: each sees their own answers, and the other's once the appraisee has
 * submitted.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\context\system;
use core\di;
use core\hook\manager;
use local_appraisal\api;
use local_appraisal\hook\before_record_actions_exported;
use local_appraisal\output\answer_input;
use local_appraisal\output\person_heading;
use local_appraisal\output\row_actions;
use local_appraisal\record;
use local_appraisal\record_answer;
use local_appraisal\template_version;

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

require_login(null, false);
$userid = (int) $USER->id;
$ctx = api::require_record_access($id, $userid);
$record = $ctx->record;
$viewerrole = $ctx->role;
$appraisee = core_user::get_user($ctx->assignment->get('userid'), '*', MUST_EXIST);
$isowner = $viewerrole === record_answer::ROLE_APPRAISER && $record->get('appraiserid') === $userid;
$now = di::get(\core\clock::class)->time();
$dateformat = get_string('strftimedate');

$context = system::instance();
$url = new moodle_url('/local/appraisal/record.php', ['id' => $id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
// Full width, with the content capped by .local-appraisal-record-page: answers can be long, and Boost's standard layout
// (830px) leaves two columns too narrow to read them.
$PAGE->set_pagelayout('report');
$PAGE->set_title(format_string($ctx->template->get('name')));
// The page prints its own heading, with the appraisee's picture; see person_heading.
$PAGE->set_heading('');
$dashboardurl = new moodle_url('/local/appraisal/dashboard.php', ['userid' => $appraisee->id]);
if ($viewerrole === record_answer::ROLE_APPRAISEE) {
    $PAGE->navbar->add(get_string('myappraisals', 'local_appraisal'), $dashboardurl);
} else {
    $PAGE->navbar->add(get_string('peopleiappraise', 'local_appraisal'), new moodle_url('/local/appraisal/people.php'));
    $PAGE->navbar->add(fullname($appraisee), $dashboardurl);
}
$PAGE->navbar->add(format_string($ctx->template->get('name')));

$rows = api::get_record_fields($ctx, $viewerrole, $userid);
$levels = api::get_levels_for_fields(array_map(fn(stdClass $row): \local_appraisal\template_field => $row->field, $rows));

// Who saved each visible answer, in one query.
$saverids = [];
foreach ($rows as $row) {
    foreach ([$row->own, $row->other] as $answer) {
        if ($answer) {
            $saverids[] = $answer->get('userid');
        }
    }
}
$names = api::get_user_names($saverids);
$rolename = [
    record_answer::ROLE_APPRAISER => get_string('completedbyappraiser', 'local_appraisal'),
    record_answer::ROLE_APPRAISEE => get_string('completedbyappraisee', 'local_appraisal'),
];
$otherrole = $viewerrole === record_answer::ROLE_APPRAISER ? record_answer::ROLE_APPRAISEE : record_answer::ROLE_APPRAISER;

// An answer prepared for the template: its text, level description, and who saved it and when. Null when there is
// no visible answer.
$answerview = function (\local_appraisal\template_field $field, ?record_answer $answer, array $names) use ($levels): ?array {
    if (!$answer) {
        return null;
    }
    $formatted = api::format_answer($field, $answer, $levels);
    $saver = $names[$answer->get('userid')] ?? null;
    return [
        'text' => $formatted->text,
        'description' => $formatted->description,
        'saved' => get_string('savedby', 'local_appraisal', (object) [
            'name' => $saver ? fullname($saver) : get_string('deleteduser', 'local_appraisal'),
            'date' => userdate($answer->get('timeanswered'), get_string('strftimedatetimeshort')),
        ]),
    ];
};

$viewerkey = $viewerrole === record_answer::ROLE_APPRAISEE ? 'appraisee' : 'appraiser';
$items = [];
foreach ($rows as $row) {
    $field = $row->field;
    $own = $answerview($field, $row->own, $names);
    $other = $answerview($field, $row->other, $names);
    // Both sides complete the field and the other side's answer is visible: two columns, appraisee first.
    $twocolumns = $row->mine && $row->theirs && !$row->otherhidden;
    // The input, when the viewer can edit the field: in the one answer area, or in the viewer's own column.
    $input = $row->editable ? answer_input::export($field, $row->own, $levels) : null;
    $columns = [];
    if ($twocolumns) {
        $owncolumn = ['answer' => $own, 'own' => true, 'input' => $input];
        $othercolumn = ['answer' => $other, 'own' => false, 'input' => null];
        $columns = $viewerrole === record_answer::ROLE_APPRAISEE
            ? [['role' => $rolename[record_answer::ROLE_APPRAISEE]] + $owncolumn,
                ['role' => $rolename[record_answer::ROLE_APPRAISER]] + $othercolumn]
            : [['role' => $rolename[record_answer::ROLE_APPRAISEE]] + $othercolumn,
                ['role' => $rolename[record_answer::ROLE_APPRAISER]] + $owncolumn];
    }
    $items[] = [
        'id' => $field->get('id'),
        'sectionid' => $field->get('sectionid'),
        'label' => $field->get('label'),
        'description' => $field->get('description'),
        'required' => (bool) $field->get('required'),
        'completedby' => \local_appraisal\template_field::get_completedby_options()[$field->get('completedby')],
        'complete' => $row->mine && api::is_answer_complete($field, $row->own),
        'mine' => $row->mine,
        'twocolumns' => $twocolumns,
        // Long text stacks the two answers, one under the other, so neither is squeezed into half a page.
        'stacked' => (int) $field->get('type') === \local_appraisal\template_field::TYPE_LONGTEXT,
        'columns' => $columns,
        'single' => !$twocolumns && ($row->mine || !$row->otherhidden),
        'answer' => $row->mine ? $own : $other,
        'input' => $input,
        'answerrole' => $row->mine ? $rolename[$viewerrole] : $rolename[$otherrole],
        'hidden' => $row->otherhidden && !$row->mine,
        'hiddennote' => $row->otherhidden
            ? get_string(($row->mine ? 'otheranswerlater_' : 'otherfieldlater_') . $viewerkey, 'local_appraisal')
            : '',
    ];
}

// Fields grouped by section; a version without sections is one group with no heading.
$sections = [];
foreach (api::group_by_section(api::get_sections($record->get('template_versionid')), $items) as $group) {
    $section = $group['section'];
    $groupprogress = api::get_group_progress($group['items']);
    $sections[] = [
        'name' => $section ? format_string($section->get('name')) : null,
        'description' => $section && $section->get('description') !== null
            ? format_text($section->get('description'), $section->get('descriptionformat')) : null,
        'progress' => $groupprogress['required']
            ? get_string('sectionprogress', 'local_appraisal', (object) $groupprogress)
            : '',
        'fields' => $group['items'],
    ];
}

$progress = api::get_progress($ctx, $viewerrole);
$owner = core_user::get_user($record->get('appraiserid'));
$ownername = $owner ? fullname($owner) : get_string('deleteduser', 'local_appraisal');
$previous = $record->is_open() ? api::get_previous_record($record) : null;
$previousappraiser = $previous ? core_user::get_user($previous->get('appraiserid')) : null;
$overdue = $record->is_open() && $record->get('duedate') !== null && $record->get('duedate') < $now;
$duedate = $record->get('duedate');

// The overall comments and grade: the appraiser's side, decided by the API.
$overall = api::get_record_overall($ctx, $viewerrole);
$caneditcomments = $viewerrole === record_answer::ROLE_APPRAISER && api::can_write_role($ctx, $userid, $viewerrole);
$comments = null;
if ($overall->mode !== template_version::COMMENTS_OFF) {
    $text = (string) $overall->comment;
    $comments = [
        'required' => $overall->required,
        'visible' => $overall->visible,
        'has' => $text !== '',
        'html' => $text !== '' ? format_text($text, FORMAT_PLAIN, ['para' => false]) : '',
        'hiddennote' => !$overall->visible ? get_string('commentslater', 'local_appraisal') : '',
        'editable' => $caneditcomments,
        'value' => $text,
        'textlength' => api::LONGTEXT_MAXLENGTH,
    ];
}
$overallgrade = $overall->level
    ? ['label' => $overall->level->get('label'), 'description' => (string) $overall->level->get('description')]
    : null;

// The footer: the lifecycle actions this viewer may take now. Submit and Sign off show what is outstanding and are
// disabled until it is done; the page script keeps them in step with the answers as they are saved.
$actions = api::get_record_actions($ctx, $userid);
$actionurl = fn(string $action): moodle_url => new moodle_url('/local/appraisal/recordaction.php', [
    'action' => $action,
    'id' => $id,
    'sesskey' => sesskey(),
]);
$hook = new before_record_actions_exported($id, (int) $appraisee->id);
di::get(manager::class)->dispatch($hook);
$menu = [];
if ($actions->editdetails) {
    $menu[] = ['label' => get_string('changedetails', 'local_appraisal'), 'icon' => 't/edit',
        'url' => $actionurl('editdetails')->out(false)];
}
foreach ($hook->get_actions() as $extra) {
    $menu[] = ['label' => $extra['label'], 'url' => $extra['url']];
}
if ($actions->reset) {
    $menu[] = ['label' => get_string('reset', 'local_appraisal'), 'icon' => 'i/reload',
        'url' => $actionurl('reset')->out(false), 'danger' => true];
}
if ($actions->cancel) {
    $menu[] = ['label' => get_string('cancelappraisal', 'local_appraisal'), 'icon' => 't/delete',
        'url' => $actionurl('cancel')->out(false), 'danger' => true];
}
$hasappraisee = api::version_has_appraisee_fields($record->get('template_versionid'));
$outstanding = array_merge($actions->submitoutstanding, $actions->signoffoutstanding);
$footer = null;
if ($actions->submit || $actions->signoff || $actions->handback || $actions->takeownership || $menu) {
    $footer = [
        'actionurl' => (new moodle_url('/local/appraisal/recordaction.php'))->out(false),
        'sesskey' => sesskey(),
        'submit' => $actions->submit ? ['ready' => !$actions->submitoutstanding] : null,
        'signoff' => $actions->signoff ? [
            'ready' => !$actions->signoffoutstanding,
            'blocked' => $hasappraisee ? (string) api::get_signoff_blocker($ctx) : '',
            'needsstart' => !$hasappraisee && $record->get('status') === record::STATUS_NOT_STARTED,
            'needscomment' => $ctx->version->get('commentsmode') === template_version::COMMENTS_REQUIRED,
        ] : null,
        'handback' => $actions->handback,
        'takeownership' => $actions->takeownership,
        'outstanding' => implode(' ', $outstanding),
        'menu' => row_actions::render($menu, $PAGE->get_renderer('core')),
    ];
}

$templatecontext = [
    'recordid' => $id,
    'template' => format_string($ctx->template->get('name')),
    'version' => $ctx->version->get('versionnumber'),
    'label' => $record->get('label'),
    'overview' => $ctx->version->get('overview') !== null
        ? format_text($ctx->version->get('overview'), $ctx->version->get('overviewformat')) : null,
    'status' => $record->get_status_name(),
    'appraiser' => $ownername,
    'due' => $duedate !== null ? userdate($duedate, $dateformat, api::site_timezone()) : '',
    'overdue' => $overdue,
    'completed' => $record->get('timecompleted') ? userdate($record->get('timecompleted'), $dateformat) : '',
    'progress' => $progress['required'] && ($isowner || $viewerrole === record_answer::ROLE_APPRAISEE)
        ? get_string('progress', 'local_appraisal', (object) $progress)
        : '',
    'ownerbanner' => $record->is_open() && $viewerrole === record_answer::ROLE_APPRAISER && !$isowner
        ? get_string('recordowner', 'local_appraisal', $ownername)
        : null,
    'previousrecord' => $previous ? [
        'text' => get_string('previousrecord', 'local_appraisal', (object) [
            'date' => userdate($previous->get('timecompleted'), $dateformat),
            'appraiser' => $previousappraiser ? fullname($previousappraiser) : get_string('deleteduser', 'local_appraisal'),
        ]),
        'url' => (new moodle_url('/local/appraisal/record.php', ['id' => $previous->get('id')]))->out(false),
    ] : null,
    'sections' => $sections,
    'hassections' => !empty($sections),
    'comments' => $comments,
    'overallgrade' => $overallgrade,
    'footer' => $footer,
    'dashboardurl' => $dashboardurl->out(false),
];

if (array_filter($rows, fn(stdClass $row): bool => $row->editable) || $caneditcomments) {
    $PAGE->requires->js_call_amd('local_appraisal/record_form', 'init', [$id, $viewerrole, record::STATUS_NOT_STARTED]);
}

echo $OUTPUT->header();
echo html_writer::start_div('local-appraisal-record-page');
echo person_heading::render($appraisee, $OUTPUT);
echo $OUTPUT->render_from_template('local_appraisal/record', $templatecontext);
echo html_writer::end_div();
echo $OUTPUT->footer();
