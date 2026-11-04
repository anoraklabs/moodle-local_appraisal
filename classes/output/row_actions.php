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
 * A table row's actions as core's kebab (⋮) action menu.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_appraisal\output;

use core\output\action_menu;
use core\output\action_menu\link_secondary;
use core\output\pix_icon;
use core\output\renderer_base;
use core\url;

/**
 * Renders a row's actions as a kebab menu, the way core's management lists (cohorts, badges, custom reports) do.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class row_actions {
    /**
     * Render the menu, or an empty string when there are no actions.
     *
     * Destructive actions are shown in red, as core does; callers list them last.
     *
     * @param array[] $actions Each as ['label' => string, 'url' => string|url, 'icon' => string|null (a core pix
     *     identifier such as 't/edit'), 'danger' => bool]. icon and danger are optional. The label is plain text
     * @param renderer_base $output The renderer
     * @return string HTML
     */
    public static function render(array $actions, renderer_base $output): string {
        if (!$actions) {
            return '';
        }
        $menu = new action_menu();
        $menu->set_kebab_trigger(get_string('actions'));
        foreach ($actions as $action) {
            $icon = empty($action['icon']) ? null : new pix_icon($action['icon'], '');
            $attributes = empty($action['danger']) ? [] : ['class' => 'text-danger'];
            $menu->add(new link_secondary(new url($action['url']), $icon, s($action['label']), $attributes));
        }
        return $output->render($menu);
    }
}
