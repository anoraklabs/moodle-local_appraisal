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
 * A template version: the draft editor, or a read-only view of a published or archived version.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use local_appraisal\api;
use local_appraisal\form\version_settings_form;
use local_appraisal\record;
use local_appraisal\template_field;
use local_appraisal\template_version;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$id = required_param('id', PARAM_INT);

$url = new moodle_url('/local/appraisal/templateversion.php', ['id' => $id]);
admin_externalpage_setup('local_appraisal_templates', '', null, $url);

$version = api::get_version($id);
$template = api::get_template($version->get('templateid'));
$templateid = $template->get('id');
$listurl = new moodle_url('/local/appraisal/templates.php');
$actionurl = fn(string $action, int $targetid): string => (new moodle_url('/local/appraisal/action.php', [
    'action' => $action,
    'id' => $targetid,
    'sesskey' => sesskey(),
]))->out(false);

$form = null;
if ($version->is_draft()) {
    $form = new version_settings_form($url, ['currentscaleid' => $version->get('overallscaleid')]);
    $form->set_data([
        'id' => $id,
        'sendreminder' => $version->get('reminderdays') !== null ? 1 : 0,
        'reminderdays' => $version->get('reminderdays'),
        'overview_editor' => [
            'text' => $version->get('overview'),
            'format' => $version->get('overviewformat'),
        ],
        'commentsmode' => $version->get('commentsmode'),
        'overallgrademode' => $version->get('overallgrademode'),
        'overallscaleid' => $version->get('overallscaleid'),
    ]);
    if ($data = $form->get_data()) {
        $reminderdays = !empty($data->sendreminder) ? (int) $data->reminderdays : null;
        $gradingmode = (int) $data->overallgrademode;
        api::update_version_settings(
            $id,
            $data->overview_editor['text'],
            (int) $data->overview_editor['format'],
            (int) $data->commentsmode,
            $gradingmode,
            $gradingmode === template_version::GRADE_OFF ? null : (int) $data->overallscaleid,
            $reminderdays
        );
        // Publish in the footer submits this form, so the settings are saved before the publish confirmation.
        $next = optional_param('publishafter', 0, PARAM_BOOL) ? $actionurl('publish', $id) : $url;
        redirect($next, get_string('settingssaved', 'local_appraisal'), null, notification::NOTIFY_SUCCESS);
    } else if ($form->is_submitted() && optional_param('publishafter', 0, PARAM_BOOL)) {
        // Say why nothing was published; the field errors alone read as the page simply reloading.
        \core\notification::error(get_string('publishsettingsinvalid', 'local_appraisal'));
    }
}

$fieldcounts = $DB->get_records_sql_menu(
    "SELECT f.template_versionid, COUNT(f.id) AS fields
       FROM {" . template_field::TABLE . "} f
       JOIN {" . template_version::TABLE . "} v ON v.id = f.template_versionid
      WHERE v.templateid = :templateid
   GROUP BY f.template_versionid",
    ['templateid' => $templateid]
);
$recordcounts = $DB->get_records_sql_menu(
    "SELECT r.template_versionid, COUNT(r.id) AS records
       FROM {" . record::TABLE . "} r
       JOIN {" . template_version::TABLE . "} v ON v.id = r.template_versionid
      WHERE v.templateid = :templateid
   GROUP BY r.template_versionid",
    ['templateid' => $templateid]
);

$history = [];
foreach (api::get_versions($templateid) as $other) {
    $history[] = [
        'number' => $other->get('versionnumber'),
        'statusname' => $other->get_status_name(),
        'fields' => (int) ($fieldcounts[$other->get('id')] ?? 0),
        'records' => (int) ($recordcounts[$other->get('id')] ?? 0),
        'url' => (new moodle_url('/local/appraisal/templateversion.php', ['id' => $other->get('id')]))->out(false),
        'current' => $other->get('id') === $id,
    ];
}

// Scales used by the version's fields, loaded once for the badges and the type summary.
$scalenames = [];
foreach (\local_appraisal\scale::get_records([], 'name') as $scale) {
    $scalenames[$scale->get('id')] = $scale->get('name');
}

$fields = [];
foreach (api::get_fields($id) as $field) {
    $summary = '';
    if ($field->has_options()) {
        $summary = implode(' · ', $field->get_options());
    } else if ($field->get('scaleid') !== null) {
        $summary = $scalenames[$field->get('scaleid')] ?? '';
    } else if ($field->get('type') === template_field::TYPE_NUMBER) {
        $summary = $field->get_number_summary();
    }
    $fields[] = [
        'id' => $field->get('id'),
        'sectionid' => $field->get('sectionid'),
        'sortorder' => $field->get('sortorder'),
        'label' => $field->get('label'),
        'description' => $field->get('description'),
        'required' => $field->get('required'),
        'typename' => $field->get_type_name(),
        'completedbyname' => template_field::get_completedby_options()[$field->get('completedby')],
        'summary' => $summary,
        'deleteurl' => $actionurl('deletefield', $field->get('id')),
    ];
}

// Fields grouped by section. A version without sections is one group with no section details.
$templatesections = api::get_sections($id);
$sections = [];
foreach (api::group_by_section($templatesections, $fields) as $group) {
    $section = $group['section'];
    $sections[] = [
        'id' => $section ? $section->get('id') : 0,
        'name' => $section ? format_string($section->get('name')) : null,
        'description' => $section && $section->get('description') !== null
            ? format_text($section->get('description'), $section->get('descriptionformat')) : null,
        'fields' => $group['items'],
        'hasfields' => !empty($group['items']),
        'deleteurl' => $section ? $actionurl('deletesection', $section->get('id')) : null,
    ];
}

$gradescale = $version->get('overallscaleid');
$context = [
    'versionid' => $id,
    'templatename' => format_string($template->get('name')),
    'versionnumber' => $version->get('versionnumber'),
    'statusname' => $version->get_status_name(),
    'reminder' => $version->get_reminder_summary(),
    'comments' => $version->get_comments_summary(),
    'overallgrade' => $version->get_grade_summary($gradescale !== null ? ($scalenames[$gradescale] ?? '') : ''),
    'overview' => $version->get('overview') !== null
        ? format_text($version->get('overview'), $version->get('overviewformat')) : null,
    'fields' => $fields,
    'hasfields' => !empty($fields),
    'sections' => $sections,
    'hassections' => !empty($templatesections),
    'history' => $history,
    'listurl' => $listurl->out(false),
];

$PAGE->navbar->add(format_string($template->get('name')));
$PAGE->navbar->add(get_string('version', 'local_appraisal', $version->get('versionnumber')));

echo $OUTPUT->header();

if ($version->is_draft()) {
    $context['basedon'] = $version->get('versionnumber') > 1 ? $version->get('versionnumber') - 1 : null;
    $context['settingsform'] = $form->render();
    $context['settingsformid'] = $form->get_html_id();
    $context['discardurl'] = $actionurl('discard', $id);
    $context['removesectionsurl'] = $actionurl('removesections', $id);
    if (!$fields) {
        $context['publishblocked'] = get_string('publishdisabled', 'local_appraisal');
    } else if (api::has_empty_section($id)) {
        $context['publishblocked'] = get_string('publishdisabledemptysection', 'local_appraisal');
    }
    $PAGE->requires->js_call_amd('local_appraisal/field_editor', 'init', [$id]);
    echo $OUTPUT->render_from_template('local_appraisal/version_editor', $context);
} else {
    $context['ispublished'] = $version->is_published();
    $context['createdrafturl'] = api::get_draft($templateid) ? null : $actionurl('createdraft', $templateid);
    if ($version->is_published()) {
        $PAGE->requires->js_call_amd('local_appraisal/field_editor', 'init', [$id]);
    }
    echo $OUTPUT->render_from_template('local_appraisal/version_view', $context);
}

echo $OUTPUT->footer();
