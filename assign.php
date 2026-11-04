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
 * Assign a template to a user, with an optional cycle label and due date, and start the first cycle.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\context\system;
use core\output\notification;
use local_appraisal\access;
use local_appraisal\api;
use local_appraisal\form\assign_form;
use local_appraisal\output\person_heading;

require(__DIR__ . '/../../config.php');

$userid = required_param('userid', PARAM_INT);

require_login(null, false);
$user = core_user::get_user($userid, '*', MUST_EXIST);
if ($userid === (int) $USER->id) {
    throw new moodle_exception('error:selfappraisal', 'local_appraisal');
}
if ($user->deleted || !access::can_appraise((int) $USER->id, $userid)) {
    throw new moodle_exception('error:nodashboardaccess', 'local_appraisal');
}

$context = system::instance();
$dashboardurl = new moodle_url('/local/appraisal/dashboard.php', ['userid' => $userid]);
$PAGE->set_url(new moodle_url('/local/appraisal/assign.php', ['userid' => $userid]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('assigntemplate', 'local_appraisal'));
// The page prints its own heading, with the user's picture; see person_heading.
$PAGE->set_heading('');
$PAGE->navbar->add(get_string('peopleiappraise', 'local_appraisal'), new moodle_url('/local/appraisal/people.php'));
$PAGE->navbar->add(fullname($user), $dashboardurl);
$PAGE->navbar->add(get_string('assigntemplate', 'local_appraisal'));

$templates = [];
foreach (api::get_assignable_templates($userid) as $template) {
    $templates[$template->get('id')] = format_string($template->get('name'));
}
if (!$templates) {
    redirect($dashboardurl, get_string('notemplatestoassign', 'local_appraisal'), null, notification::NOTIFY_INFO);
}

$form = new assign_form(null, ['userid' => $userid, 'templates' => $templates]);
if ($form->is_cancelled()) {
    redirect($dashboardurl);
}
if ($data = $form->get_data()) {
    $duedate = !empty($data->duedate) ? api::due_date_from_day((int) $data->duedate) : null;
    api::assign_template((int) $data->templateid, $userid, (int) $USER->id, $data->label, $duedate);
    // Back to the dashboard, not into the new cycle: the appraisee usually acts first, and the row shows what was assigned.
    redirect(
        $dashboardurl,
        get_string('templateassigned', 'local_appraisal', fullname($user)),
        null,
        notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo person_heading::render($user, $OUTPUT);
echo $OUTPUT->heading(get_string('assigntemplateto', 'local_appraisal', fullname($user)));
$form->display();
echo $OUTPUT->footer();
