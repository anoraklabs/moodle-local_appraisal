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

namespace local_appraisal\task;

use core\task\scheduled_task;
use local_appraisal\api;

/**
 * The daily task: sends the due date reminders that have come round.
 *
 * It changes no status. Overdue is worked out when a record is displayed.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_due_reminders extends scheduled_task {
    /**
     * The task name shown in the admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task:senddue', 'local_appraisal');
    }

    /**
     * Run the task.
     *
     * @return void
     */
    public function execute(): void {
        $now = \core\di::get(\core\clock::class)->time();
        $reminded = api::send_due_reminders($now);
        mtrace("local_appraisal: {$reminded} due date reminder(s) sent.");
    }
}
