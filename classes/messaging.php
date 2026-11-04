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

use core\message\message;
use core_user;
use html_writer;
use moodle_url;
use stdClass;

/**
 * Sends the plugin's notifications through Moodle's messaging API.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class messaging {
    /**
     * Send one of the plugin's notifications about a record.
     *
     * Provider names match db/messages.php: started, submitted, handedback, completed, duereminder, reassigned. The
     * message text comes from the lang strings message:<key>_subject and message:<key>_body, where the key is the
     * provider unless $stringkey names a variant of the same provider's wording. Each audience gets wording written to
     * it, so a provider that can reach both people has a variant for the second reader; the provider itself, and
     * therefore the user's notification preferences, stay the same.
     *
     * Nothing is sent to a deleted, suspended or missing user (an appraiser anonymised to 0, for instance).
     *
     * @param string $provider
     * @param int $touserid
     * @param record $record
     * @param string|null $stringkey Wording variant to use instead of $provider.
     * @param array $extra Further plain-text placeholders a variant needs, keyed by name.
     * @return void
     */
    public static function send(
        string $provider,
        int $touserid,
        record $record,
        ?string $stringkey = null,
        array $extra = []
    ): void {
        $touser = core_user::get_user($touserid);
        if (!$touser || $touser->deleted || $touser->suspended) {
            return;
        }

        $a = (object) ($extra + (array) self::get_placeholders($record));
        $stringkey = $stringkey ?? $provider;
        $subject = get_string("message:{$stringkey}_subject", 'local_appraisal', $a);
        $body = get_string("message:{$stringkey}_body", 'local_appraisal', $a);

        $message = new message();
        $message->component = 'local_appraisal';
        $message->name = $provider;
        $message->userfrom = core_user::get_noreply_user();
        $message->userto = $touser;
        $message->subject = $subject;
        $message->fullmessage = $body;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = self::body_to_html($body, $a->url);
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->contexturl = $a->url;
        $message->contexturlname = $a->template;
        message_send($message);
    }

    /**
     * The HTML version of a plain-text message body: escaped, line breaks kept, and the record URL made a link.
     *
     * @param string $body The plain-text body.
     * @param string $url The record URL the body ends with.
     * @return string
     */
    public static function body_to_html(string $body, string $url): string {
        // Convert the line breaks before adding the link: text_to_html() turns a newline in front of a tag into a
        // space, which would lose the blank line between the text and the link.
        $html = text_to_html(s($body), false, false, true);
        return str_replace(s($url), html_writer::link($url, s($url)), $html);
    }

    /**
     * The values the message strings can use.
     *
     * Values are plain text, since the body goes out as plain text; body_to_html() escapes them for the HTML version.
     * The due date is shown in the site timezone, like the dashboards, so everyone reads the day the appraiser chose.
     *
     * @param record $record
     * @return stdClass With template, appraisee, appraiser, label, duedate and url.
     */
    private static function get_placeholders(record $record): stdClass {
        $ctx = api::get_record_context($record->get('id'));
        $appraisee = core_user::get_user($ctx->assignment->get('userid'));
        $appraiser = $record->get('appraiserid') ? core_user::get_user($record->get('appraiserid')) : null;
        $duedate = $record->get('duedate');
        return (object) [
            'template' => format_string($ctx->template->get('name'), true, [
                'context' => \core\context\system::instance(),
                'escape' => false,
            ]),
            'appraisee' => $appraisee ? fullname($appraisee) : '',
            'appraiser' => $appraiser ? fullname($appraiser) : '',
            'label' => (string) $record->get('label'),
            'duedate' => $duedate ? userdate($duedate, get_string('strftimedate'), api::site_timezone()) : '',
            'url' => (new moodle_url('/local/appraisal/record.php', ['id' => $record->get('id')]))->out(false),
        ];
    }
}
