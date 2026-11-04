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
 * Manage appraisal templates.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\di;
use core\hook\manager;
use local_appraisal\assignment;
use local_appraisal\hook\before_template_actions_exported;
use local_appraisal\output\row_actions;
use local_appraisal\template;
use local_appraisal\template_version;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_appraisal_templates');

// Archived templates after the active ones.
$templates = template::get_records([], 'status, name');

$versionsbytemplate = [];
foreach (template_version::get_records([], 'versionnumber') as $version) {
    $versionsbytemplate[$version->get('templateid')][$version->get('status')] = $version;
}
$assignmentcounts = $DB->get_records_sql_menu(
    "SELECT templateid, COUNT(id) AS assignments
       FROM {" . assignment::TABLE . "}
   GROUP BY templateid"
);

// Same rule as access::can_delete_template(), applied to the counts already loaded rather than a query per row.
$candeleteassigned = has_capability('local/appraisal:deleteappraisals', \core\context\system::instance());
$rows = [];
foreach ($templates as $template) {
    $templateid = $template->get('id');
    $versions = $versionsbytemplate[$templateid] ?? [];
    $published = $versions[template_version::STATUS_PUBLISHED] ?? null;
    $draft = $versions[template_version::STATUS_DRAFT] ?? null;
    $actionurl = fn(string $action): string => (new moodle_url('/local/appraisal/action.php', [
        'action' => $action,
        'id' => $templateid,
        'sesskey' => sesskey(),
    ]))->out(false);

    $hook = new before_template_actions_exported((int) $templateid);
    di::get(manager::class)->dispatch($hook);

    // In a kebab menu, as core's management lists do, with Delete last.
    $actions = [
        $draft
            ? ['label' => get_string('editdraft', 'local_appraisal'), 'icon' => 't/edit',
                'url' => new moodle_url('/local/appraisal/templateversion.php', ['id' => $draft->get('id')])]
            : ['label' => get_string('createdraft', 'local_appraisal'), 'icon' => 't/add', 'url' => $actionurl('createdraft')],
        ['label' => get_string('rename', 'local_appraisal'), 'icon' => 't/editstring',
            'url' => new moodle_url('/local/appraisal/edit.php', ['id' => $templateid])],
        ['label' => get_string('duplicate', 'local_appraisal'), 'icon' => 't/copy',
            'url' => new moodle_url('/local/appraisal/edit.php', ['duplicate' => $templateid])],
        $template->is_active()
            ? ['label' => get_string('archive', 'local_appraisal'), 'icon' => 't/hide', 'url' => $actionurl('archive')]
            : ['label' => get_string('unarchive', 'local_appraisal'), 'icon' => 't/show', 'url' => $actionurl('unarchive')],
        ...$hook->get_actions(),
    ];
    if (empty($assignmentcounts[$templateid]) || $candeleteassigned) {
        $actions[] = ['label' => get_string('delete'), 'icon' => 't/delete', 'danger' => true,
            'url' => $actionurl('deletetemplate')];
    }

    $viewable = $published ?? $draft;
    $rows[] = [
        'name' => format_string($template->get('name')),
        'archived' => !$template->is_active(),
        'url' => $viewable
            ? (new moodle_url('/local/appraisal/templateversion.php', ['id' => $viewable->get('id')]))->out(false)
            : null,
        'publishedversion' => $published ? [
            'number' => $published->get('versionnumber'),
            'url' => (new moodle_url('/local/appraisal/templateversion.php', ['id' => $published->get('id')]))->out(false),
        ] : null,
        'draft' => $draft ? [
            'url' => (new moodle_url('/local/appraisal/templateversion.php', ['id' => $draft->get('id')]))->out(false),
        ] : null,
        'assignments' => (int) ($assignmentcounts[$templateid] ?? 0),
        'actionmenu' => row_actions::render($actions, $PAGE->get_renderer('core')),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managetemplates', 'local_appraisal'));
echo $OUTPUT->render_from_template('local_appraisal/template_list', [
    'newurl' => (new moodle_url('/local/appraisal/edit.php'))->out(false),
    'hastemplates' => !empty($rows),
    'templates' => $rows,
]);
echo $OUTPUT->footer();
