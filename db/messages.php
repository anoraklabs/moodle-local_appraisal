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
 * Message providers for local_appraisal.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$defaults = [
    'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
    'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
];

$messageproviders = [
    // To the appraisee when a cycle is created for them (only when the version has appraisee fields).
    'started' => ['defaults' => $defaults],
    // To the appraiser when the appraisee submits their part.
    'submitted' => ['defaults' => $defaults],
    // To the appraisee when the appraiser hands the appraisal back.
    'handedback' => ['defaults' => $defaults],
    // To the appraisee when the appraiser signs the appraisal off.
    'completed' => ['defaults' => $defaults],
    // To the appraiser, and to the appraisee while their part is open, before the due date.
    'duereminder' => ['defaults' => $defaults],
    // To the appraiser who has just lost an appraisal to someone else.
    'reassigned' => ['defaults' => $defaults],
];
