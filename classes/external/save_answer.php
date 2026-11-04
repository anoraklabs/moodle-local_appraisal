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

namespace local_appraisal\external;

use core\context\system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_appraisal\api;
use local_appraisal\record_answer;
use local_appraisal\template_field;

/**
 * Autosave one person's answer to one field of a record.
 *
 * Only the two write paths work: the record's owner as the appraiser while it is open, and the appraisee as the
 * appraisee while it is Not started or In progress. The response describes the saver's own answer and progress, and
 * never carries the other person's answers.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class save_answer extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'recordid' => new external_value(PARAM_INT, 'Record id'),
            'fieldid' => new external_value(PARAM_INT, 'Field id'),
            'role' => new external_value(PARAM_INT, 'Whose answer: 1 = appraiser, 2 = appraisee'),
            'value' => new external_value(
                PARAM_RAW,
                'The answer as typed or chosen: text, an option key, a JSON list of option keys, a scale level id, '
                . 'a day as YYYY-MM-DD, or a number. Empty clears the answer',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Save the answer and return the field's and record's new state.
     *
     * @param int $recordid
     * @param int $fieldid
     * @param int $role
     * @param string $value
     * @return array
     */
    public static function execute(int $recordid, int $fieldid, int $role, string $value = ''): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'recordid' => $recordid,
            'fieldid' => $fieldid,
            'role' => $role,
            'value' => $value,
        ]);
        $context = system::instance();
        self::validate_context($context);

        // The role is the user's own side; a mismatch is a refusal, not a redirect to the user's real side.
        $ctx = api::get_record_context($params['recordid']);
        if (api::get_viewer_role($ctx, (int) $USER->id) !== $params['role']) {
            throw new \moodle_exception('error:cannotwrite', 'local_appraisal');
        }
        require_capability(
            $params['role'] === record_answer::ROLE_APPRAISER ? 'local/appraisal:appraise' : 'local/appraisal:completeown',
            $context
        );

        $userid = (int) $USER->id;
        $answer = api::save_answer($params['recordid'], $params['fieldid'], $params['role'], $userid, $params['value']);

        $ctx = api::get_record_context($params['recordid']);
        $progress = api::get_progress($ctx, $params['role']);
        $field = template_field::get_record(['id' => $params['fieldid']], MUST_EXIST);
        return [
            'answered' => $answer !== null,
            'complete' => $answer !== null && api::is_answer_complete($field, $answer),
            'saved' => $answer !== null
                ? get_string('savedby', 'local_appraisal', (object) [
                    'name' => fullname($USER),
                    'date' => userdate($answer->get('timeanswered'), get_string('strftimedatetimeshort')),
                ])
                : '',
            'progresscomplete' => $progress['complete'],
            'progressrequired' => $progress['required'],
            'status' => $ctx->record->get('status'),
            'statusname' => $ctx->record->get_status_name(),
        ];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'answered' => new external_value(PARAM_BOOL, 'Whether the field now has an answer from the saver'),
            'complete' => new external_value(PARAM_BOOL, 'Whether that answer is complete'),
            'saved' => new external_value(PARAM_TEXT, 'Who saved the answer and when, or empty when it was cleared'),
            'progresscomplete' => new external_value(PARAM_INT, 'Required fields the saver has completed'),
            'progressrequired' => new external_value(PARAM_INT, 'Required fields the saver completes'),
            'status' => new external_value(PARAM_INT, 'The record status value'),
            'statusname' => new external_value(PARAM_TEXT, 'The record status name'),
        ]);
    }
}
