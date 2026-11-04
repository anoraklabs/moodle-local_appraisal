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
 * Behat page resolvers for local_appraisal.
 *
 * @package    local_appraisal
 * @category   test
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use local_appraisal\api;
use local_appraisal\scale;
use local_appraisal\template;

/**
 * Behat page resolvers for local_appraisal.
 *
 * @package    local_appraisal
 * @category   test
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_appraisal extends behat_base {
    /**
     * Convert a page name into a URL, for "I am on the "local_appraisal > [page]" page".
     *
     * Recognised pages: Grading scales, Templates (Manage templates), People I appraise and Notifications (the logged in
     * user's notifications, where the reminders can be read).
     *
     * @param string $page The page name.
     * @return moodle_url
     * @throws Exception If the page name is not recognised.
     */
    protected function resolve_page_url(string $page): moodle_url {
        switch (strtolower($page)) {
            case 'grading scales':
                return new moodle_url('/local/appraisal/scales.php');
            case 'templates':
                return new moodle_url('/local/appraisal/templates.php');
            case 'people i appraise':
                return new moodle_url('/local/appraisal/people.php');
            case 'notifications':
                return new moodle_url('/message/output/popup/notifications.php');
            default:
                throw new Exception('Unrecognised local_appraisal page "' . $page . '".');
        }
    }

    /**
     * Convert a page type and identifier into a URL, for "I am on the "[identifier]" "local_appraisal > [type]" page".
     *
     * Recognised types: Scale, identified by the scale's name, which opens its editor; Template, identified by the
     * template's name, which opens its published version, or its draft when nothing has been published; and Dashboard,
     * identified by a username, which opens that user's appraisal dashboard.
     *
     * @param string $type The page type.
     * @param string $identifier The scale or template name, or a username.
     * @return moodle_url
     * @throws Exception If the type is not recognised or the scale, template or user cannot be found.
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        global $DB;

        switch (strtolower($type)) {
            case 'scale':
                $scale = scale::get_record(['name' => $identifier]);
                if (!$scale) {
                    throw new Exception('There is no local_appraisal grading scale named "' . $identifier . '".');
                }
                return new moodle_url('/local/appraisal/scale.php', ['id' => $scale->get('id')]);
            case 'template':
                $template = template::get_record(['name' => $identifier]);
                if (!$template) {
                    throw new Exception('There is no local_appraisal template named "' . $identifier . '".');
                }
                $version = api::get_published_version($template->get('id')) ?? api::get_draft($template->get('id'));
                if (!$version) {
                    throw new Exception('Template "' . $identifier . '" has no published or draft version.');
                }
                return new moodle_url('/local/appraisal/templateversion.php', ['id' => $version->get('id')]);
            case 'dashboard':
                $userid = $DB->get_field('user', 'id', ['username' => $identifier]);
                if (!$userid) {
                    throw new Exception('There is no user with the username "' . $identifier . '".');
                }
                return new moodle_url('/local/appraisal/dashboard.php', ['userid' => $userid]);
            default:
                throw new Exception('Unrecognised local_appraisal page type "' . $type . '".');
        }
    }
}
