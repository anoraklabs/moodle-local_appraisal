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
 * People I appraise: the appraiser's worklist of appraisees, sorted by urgency, plus Find a user.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\context\system;
use core\output\user_picture;
use local_appraisal\access;
use local_appraisal\api;
use local_appraisal\form\find_user_form;

require(__DIR__ . '/../../config.php');

$page = optional_param('page', 0, PARAM_INT);
$sifirst = optional_param('sifirst', '', PARAM_NOTAGS);
$silast = optional_param('silast', '', PARAM_NOTAGS);
$filter = optional_param('filter', 'all', PARAM_ALPHA);
if (!in_array($filter, ['all', 'attention', 'open'], true)) {
    $filter = 'all';
}

require_login(null, false);
if (!access::is_appraiser((int) $USER->id)) {
    throw new moodle_exception('error:notanappraiser', 'local_appraisal');
}

$context = system::instance();
$url = new moodle_url('/local/appraisal/people.php', array_filter([
    'sifirst' => $sifirst,
    'silast' => $silast,
    'filter' => $filter === 'all' ? '' : $filter,
]));
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('peopleiappraise', 'local_appraisal'));
$PAGE->set_heading(get_string('peopleiappraise', 'local_appraisal'));
$PAGE->navbar->add(get_string('peopleiappraise', 'local_appraisal'));

$findform = null;
if (has_capability('local/appraisal:accessalldashboards', $context)) {
    $findform = new find_user_form($url);
    if ($data = $findform->get_data()) {
        if (!empty($data->userid)) {
            redirect(new moodle_url('/local/appraisal/dashboard.php', ['userid' => $data->userid]));
        }
    }
}

// Everyone the viewer has a relationship with, filtered by initials. The fragment already leaves out the viewer and
// deleted users.
[$memberssql, $params] = access::get_appraisees_sql($USER);
$where = [$memberssql];
if ($sifirst !== '') {
    $where[] = $DB->sql_like('u.firstname', ':sifirst', false, false);
    $params['sifirst'] = $DB->sql_like_escape($sifirst) . '%';
}
if ($silast !== '') {
    $where[] = $DB->sql_like('u.lastname', ':silast', false, false);
    $params['silast'] = $DB->sql_like_escape($silast) . '%';
}
// The picture fields and the user's context id let each row show a picture without a query per person.
$userfields = \core_user\fields::for_name()->with_userpic()->excluding('id')->get_sql('u', false, '', '', true)->selects;
$params['contextlevel'] = CONTEXT_USER;
$users = $DB->get_records_sql("SELECT u.id {$userfields},
                                      ctx.id AS contextid
                                 FROM {user} u
                            LEFT JOIN {context} ctx ON ctx.instanceid = u.id
                                                   AND ctx.contextlevel = :contextlevel
                                WHERE " . implode(' AND ', $where) . "
                             ORDER BY u.lastname, u.firstname", $params);

// The counts come from one query for everyone; the page adds names, filters, sorts and pages.
$people = [];
$now = \core\di::get(\core\clock::class)->time();
foreach (api::summarise_people(array_keys($users), (int) $USER->id, $now) as $userid => $summary) {
    $summary->id = $userid;
    $summary->fullname = fullname($users[$userid]);
    $people[$userid] = $summary;
}

$people = api::filter_and_sort_people($people, $filter);

$perpage = 25;
$total = count($people);
$rows = [];
foreach (array_slice($people, $page * $perpage, $perpage) as $person) {
    // Every row gets a picture, falling back to core's initials, so the names line up.
    $picture = new user_picture($users[$person->id]);
    $picture->link = false;
    $picture->visibletoscreenreaders = false;
    $rows[] = [
        'fullname' => $person->fullname,
        'picture' => $OUTPUT->render($picture),
        'url' => (new moodle_url('/local/appraisal/dashboard.php', ['userid' => $person->id]))->out(false),
        'assignments' => $person->assignments,
        'open' => $person->open,
        'awaiting' => $person->awaiting,
        'overdue' => $person->overdue,
        'nextdue' => $person->nextdue ? userdate($person->nextdue, get_string('strftimedate'), api::site_timezone()) : '',
    ];
}

$filterurl = fn(string $value): string => (new moodle_url($url, ['filter' => $value, 'page' => 0]))->out(false);
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_appraisal/people', [
    'findform' => $findform ? $findform->render() : null,
    'filters' => [
        ['value' => 'all', 'label' => get_string('filterall', 'local_appraisal'), 'url' => $filterurl('all'),
            'active' => $filter === 'all'],
        ['value' => 'attention', 'label' => get_string('needsattention', 'local_appraisal'),
            'url' => $filterurl('attention'), 'active' => $filter === 'attention'],
        ['value' => 'open', 'label' => get_string('filteropen', 'local_appraisal'),
            'url' => $filterurl('open'), 'active' => $filter === 'open'],
    ],
    'initialsbar' => $OUTPUT->initials_bar($sifirst, 'firstinitial', get_string('firstname'), 'sifirst', $url)
        . $OUTPUT->initials_bar($silast, 'lastinitial', get_string('lastname'), 'silast', $url),
    'haspeople' => !empty($rows),
    'people' => $rows,
]);
echo $OUTPUT->paging_bar($total, $page, $perpage, $url);
echo $OUTPUT->footer();
