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
 * Collects the extra actions that hook subscribers add to a page.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_appraisal\local;

use coding_exception;
use moodle_url;

/**
 * An ordered list of labelled links, shared by the "before ... actions exported" hooks.
 *
 * It lives outside the hook namespace because Moodle lists every non-abstract class there as a hook.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait collects_actions {
    /** @var array[] Each action as ['label' => string, 'url' => string]. */
    private array $actions = [];

    /**
     * Add an action: a link shown with the page's own actions.
     *
     * The subscriber is responsible for checking that the current user may use the link's target.
     *
     * @param string $label Plain text for the link. It is escaped on output.
     * @param moodle_url $url Where the link goes.
     * @return void
     * @throws coding_exception If the label is empty.
     */
    public function add_action(string $label, moodle_url $url): void {
        if (trim($label) === '') {
            throw new coding_exception('An appraisal action needs a label');
        }
        $this->actions[] = ['label' => $label, 'url' => $url->out(false)];
    }

    /**
     * The actions added so far, in the order they were added.
     *
     * @return array[] Each action as ['label' => string, 'url' => string], ready for a template.
     */
    public function get_actions(): array {
        return $this->actions;
    }
}
