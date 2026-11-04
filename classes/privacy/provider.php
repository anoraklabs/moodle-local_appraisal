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

namespace local_appraisal\privacy;

use core\context\system;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_appraisal\api;
use local_appraisal\assignment;
use local_appraisal\record;
use local_appraisal\record_answer;
use local_appraisal\scale_level;
use local_appraisal\template;
use local_appraisal\template_field;
use local_appraisal\template_version;

/**
 * Privacy provider: what the plugin stores about people, how it is exported, and how it is erased.
 *
 * Everything is stored at system context. An appraisee's data is exported and deleted with their assignments; an
 * appraiser, an actor or an author is anonymised to user id 0 (api::APPRAISER_DELETED), which every page shows as
 * "Deleted user".
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Every column that holds a user id other than the appraisee, by table. These are anonymised, not deleted.
     */
    private const USER_COLUMNS = [
        'local_appraisal_scale' => ['usermodified'],
        'local_appraisal_scale_level' => ['usermodified'],
        'local_appraisal_template' => ['usermodified'],
        'local_appraisal_template_version' => ['usermodified'],
        'local_appraisal_template_section' => ['usermodified'],
        'local_appraisal_template_field' => ['usermodified'],
        'local_appraisal_assignment' => ['usermodified'],
        'local_appraisal_record' => ['appraiserid', 'usermodified'],
        'local_appraisal_record_answer' => ['userid', 'usermodified'],
        'local_appraisal_record_transfer' => ['fromappraiserid', 'toappraiserid', 'actorid'],
        'local_appraisal_record_history' => ['actorid'],
        'local_appraisal_cohort_link' => ['usermodified'],
    ];

    /**
     * The configuration tables, which hold a user id only as the author of the last change.
     */
    private const CONFIG_TABLES = [
        'local_appraisal_scale',
        'local_appraisal_scale_level',
        'local_appraisal_template',
        'local_appraisal_template_version',
        'local_appraisal_template_section',
        'local_appraisal_template_field',
        'local_appraisal_cohort_link',
    ];

    /**
     * The tables whose rows an author is exported for, with the column that names each row.
     */
    private const AUTHORED_TABLES = [
        'local_appraisal_scale' => 'name',
        'local_appraisal_scale_level' => 'label',
        'local_appraisal_template' => 'name',
        'local_appraisal_template_version' => 'versionnumber',
        'local_appraisal_template_section' => 'name',
        'local_appraisal_template_field' => 'label',
    ];

    /**
     * Describe the data the plugin stores.
     *
     * @param collection $collection The collection to add to.
     * @return collection The collection with the plugin's data described.
     */
    public static function get_metadata(collection $collection): collection {
        $audit = self::audit_fields();
        $prefix = 'privacy:metadata:';

        foreach (self::CONFIG_TABLES as $table) {
            $collection->add_database_table($table, $audit, $prefix . $table);
        }

        $assignment = [
            'templateid' => $prefix . 'assignment:templateid',
            'userid' => $prefix . 'assignment:userid',
        ];
        $collection->add_database_table('local_appraisal_assignment', $assignment + $audit, $prefix . 'local_appraisal_assignment');

        $record = [
            'assignmentid' => $prefix . 'record:assignmentid',
            'template_versionid' => $prefix . 'record:template_versionid',
            'appraiserid' => $prefix . 'record:appraiserid',
            'status' => $prefix . 'record:status',
            'label' => $prefix . 'record:label',
            'duedate' => $prefix . 'record:duedate',
            'timereminded' => $prefix . 'record:timereminded',
            'timestarted' => $prefix . 'record:timestarted',
            'timesubmitted' => $prefix . 'record:timesubmitted',
            'timecompleted' => $prefix . 'record:timecompleted',
            'overalllevelid' => $prefix . 'record:overalllevelid',
            'comment' => $prefix . 'record:comment',
        ];
        $collection->add_database_table('local_appraisal_record', $record + $audit, $prefix . 'local_appraisal_record');

        $answer = [
            'recordid' => $prefix . 'answer:recordid',
            'fieldid' => $prefix . 'answer:fieldid',
            'role' => $prefix . 'answer:role',
            'valueint' => $prefix . 'answer:valueint',
            'valuenumber' => $prefix . 'answer:valuenumber',
            'valuetext' => $prefix . 'answer:valuetext',
            'userid' => $prefix . 'answer:userid',
            'timeanswered' => $prefix . 'answer:timeanswered',
        ];
        $collection->add_database_table(
            'local_appraisal_record_answer',
            $answer + $audit,
            $prefix . 'local_appraisal_record_answer'
        );

        $transfer = [
            'recordid' => $prefix . 'transfer:recordid',
            'fromappraiserid' => $prefix . 'transfer:fromappraiserid',
            'toappraiserid' => $prefix . 'transfer:toappraiserid',
            'actorid' => $prefix . 'transfer:actorid',
            'timecreated' => $prefix . 'timecreated',
        ];
        $collection->add_database_table('local_appraisal_record_transfer', $transfer, $prefix . 'local_appraisal_record_transfer');

        $history = [
            'recordid' => $prefix . 'history:recordid',
            'fromstatus' => $prefix . 'history:fromstatus',
            'tostatus' => $prefix . 'history:tostatus',
            'actorid' => $prefix . 'history:actorid',
            'comment' => $prefix . 'history:comment',
            'timecreated' => $prefix . 'timecreated',
        ];
        $collection->add_database_table('local_appraisal_record_history', $history, $prefix . 'local_appraisal_record_history');

        $collection->add_subsystem_link('core_message', [], $prefix . 'core_message');

        return $collection;
    }

    /**
     * The audit fields every entity table carries.
     *
     * @return array Field name => language string key.
     */
    private static function audit_fields(): array {
        return [
            'usermodified' => 'privacy:metadata:usermodified',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'privacy:metadata:timemodified',
        ];
    }

    /**
     * Whether the plugin holds anything that names the user.
     *
     * @param int $userid
     * @return bool
     */
    private static function has_data(int $userid): bool {
        global $DB;

        if ($DB->record_exists('local_appraisal_assignment', ['userid' => $userid])) {
            return true;
        }
        foreach (self::USER_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if ($DB->record_exists($table, [$column => $userid])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Get the contexts that hold data about the user: the system context, when the plugin names them anywhere.
     *
     * @param int $userid The user to search for.
     * @return contextlist The contexts with data about the user.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        if ($userid > 0 && self::has_data($userid)) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Get the users who have data in a context.
     *
     * @param userlist $userlist The userlist to add the users to.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof system) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_appraisal_assignment}', []);
        foreach (self::USER_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $sql = 'SELECT ' . $column . ' AS userid
                          FROM {' . $table . '}
                         WHERE ' . $column . ' IS NOT NULL
                           AND ' . $column . ' <> 0';
                $userlist->add_from_sql('userid', $sql, []);
            }
        }
    }

    /**
     * Export the user's data: as an appraisee, as an appraiser or actor, and as an author of templates and scales.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        $context = system::instance();
        if (!in_array($context->id, $contextlist->get_contextids())) {
            return;
        }
        $userid = $contextlist->get_user()->id;
        $base = [get_string('pluginname', 'local_appraisal')];

        self::export_as_appraisee($userid, $context, $base);
        self::export_as_appraiser($userid, $context, $base);
        self::export_as_author($userid, $context, $base);
    }

    /**
     * Export the appraisee's own appraisals: one folder per assignment, one sub-folder per cycle.
     *
     * Nothing is exported that the plugin hides from the appraisee: the appraiser's answers, overall comments and
     * grade appear only once api::answers_visible() lets the appraisee see them.
     *
     * @param int $userid The appraisee.
     * @param system $context
     * @param string[] $base The subcontext the plugin's data goes under.
     * @return void
     */
    private static function export_as_appraisee(int $userid, system $context, array $base): void {
        $folder = get_string('privacyasappraisee', 'local_appraisal');
        foreach (assignment::get_records(['userid' => $userid], 'id') as $assignment) {
            $template = template::get_record(['id' => $assignment->get('templateid')]);
            $assignmentpath = array_merge($base, [
                $folder,
                self::folder_name($template ? $template->get('name') : '', $assignment->get('id')),
            ]);
            foreach (record::get_records(['assignmentid' => $assignment->get('id')], 'id') as $record) {
                $data = self::build_record_data($record, $template, record_answer::ROLE_APPRAISEE);
                writer::with_context($context)->export_data(
                    array_merge($assignmentpath, [self::folder_name($record->get('label') ?? '', $record->get('id'))]),
                    $data
                );
            }
        }
    }

    /**
     * Build the exported view of one record for its appraisee.
     *
     * @param record $record
     * @param template|null $template
     * @param int $viewerrole The side the data is exported to (record_answer::ROLE_APPRAISEE).
     * @return \stdClass
     */
    private static function build_record_data(record $record, ?template $template, int $viewerrole): \stdClass {
        $version = template_version::get_record(['id' => $record->get('template_versionid')]);
        $fields = $version ? api::get_fields($version->get('id')) : [];
        $levels = api::get_levels_for_fields($fields);
        $answers = [];
        foreach (api::get_answers($record->get('id')) as $answer) {
            if (!isset($fields[$answer->get('fieldid')])) {
                continue;
            }
            if (!api::answers_visible($record, $viewerrole, $answer->get('role'))) {
                continue;
            }
            $answers[] = self::build_answer_data($fields[$answer->get('fieldid')], $answer, $levels);
        }

        $data = (object) [
            'template' => $template ? $template->get('name') : '',
            'version' => $version ? $version->get('versionnumber') : null,
            'label' => $record->get('label'),
            'status' => $record->get_status_name(),
            'appraiser' => self::user_name($record->get('appraiserid')),
            'duedate' => self::date_or_null($record->get('duedate')),
            'timestarted' => self::date_or_null($record->get('timestarted')),
            'timesubmitted' => self::date_or_null($record->get('timesubmitted')),
            'timecompleted' => self::date_or_null($record->get('timecompleted')),
            'timecreated' => transform::datetime($record->get('timecreated')),
            'answers' => $answers,
        ];

        if ($version) {
            $overall = api::get_record_overall((object) ['record' => $record, 'version' => $version], $viewerrole);
            $data->overallcomments = $overall->comment;
            $data->overallgrade = $overall->level ? $overall->level->get('label') : null;
        }

        $data->history = [];
        foreach (api::get_history($record->get('id')) as $row) {
            $data->history[] = (object) [
                'fromstatus' => $row->fromstatus === null ? null : record::status_name((int) $row->fromstatus),
                'tostatus' => record::status_name((int) $row->tostatus),
                'by' => $row->actorid === null ? null : self::user_name((int) $row->actorid),
                'note' => $row->comment,
                'time' => transform::datetime($row->timecreated),
            ];
        }
        $data->transfers = [];
        foreach (api::get_transfers($record->get('id')) as $row) {
            $data->transfers[] = (object) [
                'from' => self::user_name((int) $row->fromappraiserid),
                'to' => self::user_name((int) $row->toappraiserid),
                'by' => self::user_name((int) $row->actorid),
                'time' => transform::datetime($row->timecreated),
            ];
        }
        return $data;
    }

    /**
     * An answer as words: the field's label, whose answer it is and the value as the screen shows it.
     *
     * @param template_field $field
     * @param record_answer $answer
     * @param scale_level[] $levels The scale levels of the version's fields, keyed by id.
     * @return \stdClass
     */
    private static function build_answer_data(template_field $field, record_answer $answer, array $levels): \stdClass {
        $formatted = api::format_answer($field, $answer, $levels);
        $isappraiser = $answer->get('role') === record_answer::ROLE_APPRAISER;
        return (object) [
            'field' => $field->get('label'),
            'answeredby' => get_string($isappraiser ? 'privacyroleappraiser' : 'privacyroleappraisee', 'local_appraisal'),
            'answer' => $formatted->text,
            'timeanswered' => self::date_or_null($answer->get('timeanswered')),
        ];
    }

    /**
     * Export what the user did as an appraiser or actor: records they own or acted on, and the answers they saved.
     *
     * The appraisee is identified by user id only. Records of the user's own assignments are left to the appraisee
     * export.
     *
     * @param int $userid
     * @param system $context
     * @param string[] $base The subcontext the plugin's data goes under.
     * @return void
     */
    private static function export_as_appraiser(int $userid, system $context, array $base): void {
        global $DB;

        $sql = "SELECT r.id
                  FROM {local_appraisal_record} r
                  JOIN {local_appraisal_assignment} a ON a.id = r.assignmentid
                 WHERE a.userid <> :own
                   AND (r.appraiserid = :owner
                        OR r.usermodified = :modifier
                        OR EXISTS (SELECT 1
                                     FROM {local_appraisal_record_answer} ra
                                    WHERE ra.recordid = r.id AND ra.userid = :saver)
                        OR EXISTS (SELECT 1
                                     FROM {local_appraisal_record_history} h
                                    WHERE h.recordid = r.id AND h.actorid = :actor)
                        OR EXISTS (SELECT 1
                                     FROM {local_appraisal_record_transfer} t
                                    WHERE t.recordid = r.id
                                      AND (t.fromappraiserid = :fromid OR t.toappraiserid = :toid OR t.actorid = :transferactor)))
              ORDER BY r.id";
        $params = [
            'own' => $userid, 'owner' => $userid, 'modifier' => $userid, 'saver' => $userid, 'actor' => $userid,
            'fromid' => $userid, 'toid' => $userid, 'transferactor' => $userid,
        ];
        $folder = get_string('privacyasappraiser', 'local_appraisal');
        foreach ($DB->get_fieldset_sql($sql, $params) as $recordid) {
            $record = record::get_record(['id' => $recordid]);
            $assignment = assignment::get_record(['id' => $record->get('assignmentid')]);
            $template = template::get_record(['id' => $assignment->get('templateid')]);
            $version = template_version::get_record(['id' => $record->get('template_versionid')]);
            $fields = $version ? api::get_fields($version->get('id')) : [];
            $levels = api::get_levels_for_fields($fields);

            $answers = [];
            foreach (record_answer::get_records(['recordid' => $recordid, 'userid' => $userid], 'id') as $answer) {
                if (isset($fields[$answer->get('fieldid')])) {
                    $answers[] = self::build_answer_data($fields[$answer->get('fieldid')], $answer, $levels);
                }
            }
            $isowner = $record->get('appraiserid') === $userid;
            $data = (object) [
                'template' => $template ? $template->get('name') : '',
                'appraiseeid' => $assignment->get('userid'),
                'label' => $record->get('label'),
                'status' => $record->get_status_name(),
                'isowner' => $isowner,
                'duedate' => self::date_or_null($record->get('duedate')),
                'timestarted' => self::date_or_null($record->get('timestarted')),
                'timesubmitted' => self::date_or_null($record->get('timesubmitted')),
                'timecompleted' => self::date_or_null($record->get('timecompleted')),
                'overallcomments' => $isowner ? $record->get('comment') : null,
                'answers' => $answers,
                'history' => [],
                'transfers' => [],
            ];
            foreach (api::get_history($recordid) as $row) {
                if ((int) $row->actorid === $userid) {
                    $data->history[] = (object) [
                        'fromstatus' => $row->fromstatus === null ? null : record::status_name((int) $row->fromstatus),
                        'tostatus' => record::status_name((int) $row->tostatus),
                        'note' => $row->comment,
                        'time' => transform::datetime($row->timecreated),
                    ];
                }
            }
            foreach (api::get_transfers($recordid) as $row) {
                $involved = [(int) $row->fromappraiserid, (int) $row->toappraiserid, (int) $row->actorid];
                if (in_array($userid, $involved, true)) {
                    $data->transfers[] = (object) [
                        'fromid' => (int) $row->fromappraiserid,
                        'toid' => (int) $row->toappraiserid,
                        'byid' => (int) $row->actorid,
                        'time' => transform::datetime($row->timecreated),
                    ];
                }
            }
            writer::with_context($context)->export_data(
                array_merge($base, [$folder, self::folder_name($template ? $template->get('name') : '', $recordid)]),
                $data
            );
        }
    }

    /**
     * Export the templates, versions, sections, fields, scales, levels, assignments and links the user last changed.
     *
     * @param int $userid
     * @param system $context
     * @param string[] $base The subcontext the plugin's data goes under.
     * @return void
     */
    private static function export_as_author(int $userid, system $context, array $base): void {
        global $DB;

        $items = [];
        foreach (self::AUTHORED_TABLES as $table => $namecolumn) {
            $rows = $DB->get_records(
                $table,
                ['usermodified' => $userid],
                'id',
                'id, ' . $namecolumn . ' AS itemname, timecreated, timemodified'
            );
            foreach ($rows as $row) {
                $items[] = (object) [
                    'type' => substr($table, strlen('local_appraisal_')),
                    'id' => (int) $row->id,
                    'name' => (string) $row->itemname,
                    'timecreated' => transform::datetime($row->timecreated),
                    'timemodified' => transform::datetime($row->timemodified),
                ];
            }
        }
        if ($items) {
            writer::with_context($context)->export_data(
                array_merge($base, [get_string('privacyasauthor', 'local_appraisal')]),
                (object) ['items' => $items]
            );
        }
    }

    /**
     * Delete all data for all users in the context: every appraisal, leaving templates, scales and links in place.
     *
     * @param \context $context The context to delete in.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof system) {
            return;
        }
        foreach ($DB->get_fieldset_select('local_appraisal_assignment', 'id', '1 = 1') as $assignmentid) {
            api::delete_assignment((int) $assignmentid, false);
        }
    }

    /**
     * Delete all data for the user in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete for.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        if (!in_array(system::instance()->id, $contextlist->get_contextids())) {
            return;
        }
        self::erase_users([$contextlist->get_user()->id]);
    }

    /**
     * Delete the data of several users in a context.
     *
     * @param approved_userlist $userlist The approved context and users to delete for.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        if (!$userlist->get_context() instanceof system) {
            return;
        }
        self::erase_users($userlist->get_userids());
    }

    /**
     * Erase users: delete their own assignments, then replace them with user id 0 everywhere else.
     *
     * @param int[] $userids
     * @return void
     */
    private static function erase_users(array $userids): void {
        global $DB;

        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        foreach ($DB->get_fieldset_select('local_appraisal_assignment', 'id', 'userid ' . $insql, $params) as $assignmentid) {
            api::delete_assignment((int) $assignmentid, false);
        }
        $transaction = $DB->start_delegated_transaction();
        foreach (self::USER_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
                $DB->set_field_select($table, $column, api::APPRAISER_DELETED, $column . ' ' . $insql, $params);
            }
        }
        $transaction->allow_commit();
    }

    /**
     * A name for a folder in the export: the item's name and its id, safe to use as a path.
     *
     * @param string $name
     * @param int $id
     * @return string
     */
    private static function folder_name(string $name, int $id): string {
        $name = trim(clean_filename(shorten_text($name, 60, true, '')));
        return $name === '' ? (string) $id : $name . ' (' . $id . ')';
    }

    /**
     * A user's full name, or "Deleted user" for the anonymised id 0.
     *
     * @param int $userid
     * @return string
     */
    private static function user_name(int $userid): string {
        $users = api::get_user_names([$userid]);
        return isset($users[$userid]) ? fullname($users[$userid]) : get_string('deleteduser', 'local_appraisal');
    }

    /**
     * A timestamp as an export date, or null when it is not set.
     *
     * @param int|null $time
     * @return string|null
     */
    private static function date_or_null(?int $time): ?string {
        return $time === null ? null : transform::datetime($time);
    }
}
