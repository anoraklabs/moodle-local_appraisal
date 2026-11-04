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

namespace local_appraisal;

use core\context\system;
use moodle_exception;
use stdClass;

/**
 * Who may reach and change what: the three dashboard access gates (cohort link, profile field, site-wide) and the
 * rules built on them.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access {
    /** @var string Config value prefix for a custom profile field, followed by its id. Stored in config - DO NOT CHANGE. */
    const FIELD_CUSTOM = 'custom_';

    /**
     * Whether the viewer may open the given user's dashboard.
     *
     * True for the user themselves (with completeown), and for anyone who may act as their appraiser.
     *
     * @param int $viewerid
     * @param int $userid
     * @return bool
     */
    public static function can_access_dashboard(int $viewerid, int $userid): bool {
        if ($viewerid === $userid) {
            return has_capability('local/appraisal:completeown', system::instance(), $viewerid);
        }
        return self::can_appraise($viewerid, $userid);
    }

    /**
     * Whether the viewer may act as appraiser for the given user: reach their dashboard to assign templates, start
     * cycles and take ownership of records.
     *
     * Needs the appraise capability and one of the three gates. Nobody can appraise themselves.
     *
     * @param int $viewerid
     * @param int $userid
     * @return bool
     */
    public static function can_appraise(int $viewerid, int $userid): bool {
        if ($viewerid === $userid || isguestuser($userid)) {
            return false;
        }
        $context = system::instance();
        if (!has_capability('local/appraisal:appraise', $context, $viewerid)) {
            return false;
        }
        if (has_capability('local/appraisal:accessalldashboards', $context, $viewerid)) {
            return true;
        }
        return self::is_linked_appraiser($viewerid, $userid) || self::is_profile_appraiser($viewerid, $userid);
    }

    /**
     * Whether the user may delete the given user's appraisals outright.
     *
     * Needs deleteappraisals on top of reaching the user's dashboard as an appraiser. can_appraise() also rules out
     * the viewer's own dashboard, so nobody can remove their own appraisal this way.
     *
     * @param int $viewerid
     * @param int $userid
     * @return bool
     */
    public static function can_delete_appraisal(int $viewerid, int $userid): bool {
        return has_capability('local/appraisal:deleteappraisals', system::instance(), $viewerid)
            && self::can_appraise($viewerid, $userid);
    }

    /**
     * Whether a user may permanently delete a template.
     *
     * Deleting needs managetemplates, and also deleteappraisals once the template has assignments, because it takes
     * those users' appraisals with it.
     *
     * @param int $templateid
     * @param int|null $userid The user to check, or null for the current user.
     * @return bool
     */
    public static function can_delete_template(int $templateid, ?int $userid = null): bool {
        $context = system::instance();
        if (!has_capability('local/appraisal:managetemplates', $context, $userid)) {
            return false;
        }
        return !assignment::count_records(['templateid' => $templateid])
            || has_capability('local/appraisal:deleteappraisals', $context, $userid);
    }

    /**
     * Throw unless the viewer may open the user's dashboard.
     *
     * @param int $viewerid
     * @param int $userid
     * @return void
     * @throws moodle_exception
     */
    public static function require_dashboard_access(int $viewerid, int $userid): void {
        if (!self::can_access_dashboard($viewerid, $userid)) {
            throw new moodle_exception('error:nodashboardaccess', 'local_appraisal');
        }
    }

    /**
     * Whether a cohort link lets the viewer appraise the user: the viewer belongs to a link's appraiser cohort and
     * the user to the same link's appraisee cohort.
     *
     * Membership is read live from core's cohort tables, so adding or removing someone from a cohort changes access
     * at once.
     *
     * @param int $viewerid
     * @param int $userid
     * @return bool
     */
    public static function is_linked_appraiser(int $viewerid, int $userid): bool {
        global $DB;

        $sql = "SELECT 1
                  FROM {" . cohort_link::TABLE . "} cl
                  JOIN {cohort_members} am ON am.cohortid = cl.appraisercohortid
                  JOIN {cohort_members} em ON em.cohortid = cl.appraiseecohortid
                 WHERE am.userid = :viewerid
                   AND em.userid = :userid";
        return $DB->record_exists_sql($sql, ['viewerid' => $viewerid, 'userid' => $userid]);
    }

    /**
     * Whether profile-based access is switched on and points at a usable field.
     *
     * @return bool
     */
    public static function profile_access_enabled(): bool {
        return (bool) get_config('local_appraisal', 'profileaccessenabled') && self::get_appraiser_fieldid() !== null;
    }

    /**
     * The id of the custom profile field configured to hold the appraiser's username.
     *
     * @return int|null Null if unset or invalid.
     */
    public static function get_appraiser_fieldid(): ?int {
        $value = (string) get_config('local_appraisal', 'appraiserfield');
        if (!str_starts_with($value, self::FIELD_CUSTOM)) {
            return null;
        }
        $id = (int) substr($value, strlen(self::FIELD_CUSTOM));
        return $id ?: null;
    }

    /**
     * Whether the viewer is named as appraiser in the user's profile field (profile-based access only).
     *
     * @param int $viewerid
     * @param int $userid
     * @return bool
     */
    public static function is_profile_appraiser(int $viewerid, int $userid): bool {
        global $DB;

        if (!self::profile_access_enabled()) {
            return false;
        }
        $username = $DB->get_field('user', 'username', ['id' => $viewerid]);
        return $username !== false && strcasecmp(self::get_appraiser_username($userid) ?? '', $username) === 0;
    }

    /**
     * The username stored in the user's appraiser field, or null if empty.
     *
     * @param int $userid
     * @return string|null
     */
    public static function get_appraiser_username(int $userid): ?string {
        global $DB;

        $fieldid = self::get_appraiser_fieldid();
        if (!$fieldid) {
            return null;
        }
        $value = trim((string) $DB->get_field('user_info_data', 'data', ['userid' => $userid, 'fieldid' => $fieldid]));
        return $value === '' ? null : $value;
    }

    /**
     * SQL fragment and params matching users whose appraiser field names the given username.
     *
     * @param string $useralias Alias of the {user} table in the outer query.
     * @param string $username
     * @return array [sql, params]; sql is '1 = 0' when profile-based access is off.
     */
    public static function get_profile_appraisees_sql(string $useralias, string $username): array {
        global $DB;

        $fieldid = self::get_appraiser_fieldid();
        if (!self::profile_access_enabled() || !$fieldid) {
            return ['1 = 0', []];
        }
        $sql = "EXISTS (SELECT 1
                          FROM {user_info_data} pd
                         WHERE pd.userid = {$useralias}.id
                           AND pd.fieldid = :appraiserfieldid
                           AND " . $DB->sql_equal('pd.data', ':appraiserusername', false) . ")";
        return [$sql, ['appraiserfieldid' => $fieldid, 'appraiserusername' => $username]];
    }

    /**
     * SQL fragment and params matching users the viewer reaches through a cohort link.
     *
     * @param string $useralias Alias of the {user} table in the outer query.
     * @param int $viewerid
     * @return array [sql, params]
     */
    public static function get_linked_appraisees_sql(string $useralias, int $viewerid): array {
        $sql = "EXISTS (SELECT 1
                          FROM {" . cohort_link::TABLE . "} cl
                          JOIN {cohort_members} am ON am.cohortid = cl.appraisercohortid
                          JOIN {cohort_members} em ON em.cohortid = cl.appraiseecohortid
                         WHERE am.userid = :linkviewerid
                           AND em.userid = {$useralias}.id)";
        return [$sql, ['linkviewerid' => $viewerid]];
    }

    /**
     * Options for the appraiser field setting: the custom text profile fields.
     *
     * Standard user columns are not offered: users can usually edit them on their own profile, so they could name
     * their own appraiser.
     *
     * @return string[] Keyed by config value.
     */
    public static function get_appraiser_field_options(): array {
        global $DB;

        $options = ['' => get_string('choosedots')];
        foreach ($DB->get_records('user_info_field', ['datatype' => 'text'], 'name') as $field) {
            $name = format_string($field->name, true, ['escape' => false]);
            $options[self::FIELD_CUSTOM . $field->id] = $name;
        }
        return $options;
    }

    /**
     * Whether the configured custom field can be edited by the user themselves (a security gap to warn about).
     *
     * @return bool
     */
    public static function is_appraiser_field_user_editable(): bool {
        global $DB;

        $fieldid = self::get_appraiser_fieldid();
        if (!$fieldid) {
            return false;
        }
        $record = $DB->get_record('user_info_field', ['id' => $fieldid], 'id, locked, visible');
        return $record && !$record->locked;
    }

    /**
     * Whether the user is an appraiser of anyone: holds appraise and either accessalldashboards, belongs to the
     * appraiser cohort of a link that reaches someone else, or is named in at least one profile field.
     *
     * Records they already own don't count on their own. Ownership is a record of who did the work, not a standing
     * permission, so an appraiser who has lost every gate is no longer an appraiser of anyone.
     *
     * @param int $userid
     * @return bool
     */
    public static function is_appraiser(int $userid): bool {
        global $DB;

        $context = system::instance();
        if (!has_capability('local/appraisal:appraise', $context, $userid)) {
            return false;
        }
        if (has_capability('local/appraisal:accessalldashboards', $context, $userid)) {
            return true;
        }
        $username = (string) $DB->get_field('user', 'username', ['id' => $userid]);
        [$profilesql, $profileparams] = self::get_profile_appraisees_sql('u', $username);
        [$linksql, $linkparams] = self::get_linked_appraisees_sql('u', $userid);
        return $DB->record_exists_sql(
            "SELECT u.id
               FROM {user} u
              WHERE u.deleted = 0
                AND u.id <> :selfid
                AND ({$profilesql} OR {$linksql})",
            ['selfid' => $userid] + $profileparams + $linkparams
        );
    }

    /**
     * Users the viewer has a relationship with: reached through a cohort link or named in their profile field, or,
     * for a viewer who holds accessalldashboards, with a record the viewer appraises.
     *
     * The record branch is the site-wide gate's route onto this list, so it is conditional on that capability.
     * Without it, a viewer who had lost every gate would still be listed against everyone they had ever appraised,
     * and every row would lead to a dashboard they are refused on.
     *
     * @param stdClass $viewer
     * @return array [sql fragment matching u.id, params]
     */
    public static function get_appraisees_sql(stdClass $viewer): array {
        if (!has_capability('local/appraisal:appraise', system::instance(), $viewer->id)) {
            return ['1 = 0', []];
        }
        [$profilesql, $params] = self::get_profile_appraisees_sql('u', $viewer->username);
        [$linksql, $linkparams] = self::get_linked_appraisees_sql('u', (int) $viewer->id);
        $params += $linkparams;
        $branches = [$profilesql, $linksql];
        if (has_capability('local/appraisal:accessalldashboards', system::instance(), $viewer->id)) {
            $branches[] = "EXISTS (SELECT 1
                                     FROM {" . assignment::TABLE . "} ra
                                     JOIN {" . record::TABLE . "} rr ON rr.assignmentid = ra.id
                                    WHERE ra.userid = u.id
                                      AND rr.appraiserid = :ownerid)";
            $params['ownerid'] = $viewer->id;
        }
        // The viewer never appears in their own list, even when they sit in both cohorts of a link.
        $params['notviewer'] = $viewer->id;
        return ['(u.deleted = 0 AND u.id <> :notviewer AND (' . implode(' OR ', $branches) . '))', $params];
    }
}
