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
 * Create, rename or duplicate a template.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_appraisal\api;
use local_appraisal\form\template_form;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$id = optional_param('id', 0, PARAM_INT);
$duplicateid = optional_param('duplicate', 0, PARAM_INT);

$url = new moodle_url('/local/appraisal/edit.php', array_filter(['id' => $id, 'duplicate' => $duplicateid]));
admin_externalpage_setup('local_appraisal_templates', '', null, $url);

$listurl = new moodle_url('/local/appraisal/templates.php');

$template = $id ? api::get_template($id) : null;
$form = new template_form();

if ($duplicateid) {
    $source = api::get_template($duplicateid);
    $heading = get_string('duplicate', 'local_appraisal');
    $form->set_data([
        'duplicate' => $duplicateid,
        'name' => get_string('duplicatename', 'local_appraisal', $source->get('name')),
    ]);
} else if ($template) {
    $heading = get_string('rename', 'local_appraisal');
    $form->set_data(['id' => $id, 'name' => $template->get('name')]);
} else {
    $heading = get_string('newtemplate', 'local_appraisal');
}

if ($form->is_cancelled()) {
    redirect($listurl);
}

if ($data = $form->get_data()) {
    $name = trim($data->name);
    if (!empty($data->duplicate)) {
        $copy = api::duplicate_template((int) $data->duplicate, $name);
        $draft = api::get_draft($copy->get('id'));
        redirect(new moodle_url('/local/appraisal/templateversion.php', ['id' => $draft->get('id')]));
    }
    if ($data->id) {
        api::update_template((int) $data->id, $name);
        redirect($listurl);
    }
    $new = api::create_template($name);
    $draft = api::get_draft($new->get('id'));
    redirect(new moodle_url('/local/appraisal/templateversion.php', ['id' => $draft->get('id')]));
}

$PAGE->navbar->add($heading);
echo $OUTPUT->header();
echo $OUTPUT->heading($heading);
$form->display();
echo $OUTPUT->footer();
