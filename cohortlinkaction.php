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
 * Sesskey-protected cohort link actions, with a confirmation that says how many people lose access.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\notification;
use local_appraisal\api;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$action = required_param('action', PARAM_ALPHA);
$id = required_param('id', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$url = new moodle_url('/local/appraisal/cohortlinkaction.php', ['action' => $action, 'id' => $id]);
admin_externalpage_setup('local_appraisal_cohortlinks', '', null, $url);
require_sesskey();

$listurl = new moodle_url('/local/appraisal/cohortlinks.php');

switch ($action) {
    case 'delete':
        $link = api::get_cohort_link($id);
        $summary = api::get_cohort_link_rows()[$id] ?? null;
        if (!$confirm) {
            $message = get_string('confirmdeletelink', 'local_appraisal', (object) [
                'appraiser' => $summary ? format_string($summary->appraisername) : $link->get('appraisercohortid'),
                'appraisee' => $summary ? format_string($summary->appraiseename) : $link->get('appraiseecohortid'),
                'appraisers' => $summary->appraisercount ?? 0,
                'appraisees' => $summary->appraiseecount ?? 0,
            ]);
            $url->params(['confirm' => 1, 'sesskey' => sesskey()]);
            echo $OUTPUT->header();
            echo $OUTPUT->confirm($message, new single_button($url, get_string('continue'), 'post'), $listurl);
            echo $OUTPUT->footer();
            exit;
        }
        api::delete_cohort_link($id);
        redirect($listurl, get_string('linkdeleted', 'local_appraisal'), null, notification::NOTIFY_SUCCESS);
        break;

    default:
        throw new moodle_exception('invalidaction', 'error');
}
