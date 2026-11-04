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
 * Page heading naming the person a page is about, with their profile picture.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_appraisal\output;

use core\output\context_header;
use core\output\renderer_base;
use core\output\user_picture;
use stdClass;

/**
 * Renders a person's name as the page heading, with their profile picture beside it when they have uploaded one.
 *
 * Core only puts a picture in the theme's page header on user-context pages, and these pages run at system
 * context, so the page leaves the theme heading empty and prints this one at the top of its content instead.
 * It uses core's context header markup, so it looks the same as the heading it replaces.
 *
 * @package    local_appraisal
 * @copyright  2026 Anorak Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class person_heading {
    /**
     * Whether the user has uploaded a profile picture.
     *
     * Gravatar isn't counted: whether one exists can't be known without asking Gravatar, and showing its
     * fallback image would put a placeholder in the heading.
     *
     * @param stdClass $user The user, with at least the picture field
     * @return bool
     */
    public static function has_picture(stdClass $user): bool {
        return !empty($user->picture);
    }

    /**
     * Render the heading: the user's full name as the page's h1, with their picture to the left when they have one.
     *
     * The picture isn't a link, since the viewer may not be allowed to see the user's profile, and it is hidden
     * from screen readers because the name sits beside it.
     *
     * @param stdClass $user The user, with the name and picture fields
     * @param renderer_base $output The renderer
     * @return string HTML
     */
    public static function render(stdClass $user, renderer_base $output): string {
        $picture = null;
        if (self::has_picture($user)) {
            $userpicture = new user_picture($user);
            $userpicture->size = 100;
            $userpicture->link = false;
            $userpicture->visibletoscreenreaders = false;
            $picture = $output->render($userpicture);
        }
        return $output->render(new context_header(s(fullname($user)), 1, $picture));
    }
}
