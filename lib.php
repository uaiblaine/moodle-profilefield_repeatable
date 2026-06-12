<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Library functions for profilefield_repeatable.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine (anderson@blaine.com.br)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add repeatable profile fields as a dedicated widget on the user profile page.
 *
 * @param \core_user\output\myprofile\tree $tree
 * @param stdClass $user
 * @param bool $iscurrentuser
 * @param stdClass|null $course
 */
function profilefield_repeatable_myprofile_navigation(
    \core_user\output\myprofile\tree $tree,
    $user,
    $iscurrentuser,
    $course
) {
    global $CFG;
    require_once($CFG->dirroot . '/user/profile/lib.php');

    $fields = profile_get_user_fields_with_data($user->id);
    $repeatablefields = [];

    foreach ($fields as $formfield) {
        if ($formfield->field->datatype !== 'repeatable') {
            continue;
        }
        if (!$formfield->is_visible() || $formfield->is_empty()) {
            continue;
        }
        $repeatablefields[] = $formfield;
    }

    if (empty($repeatablefields)) {
        return;
    }

    // Register a dedicated category after 'contact'.
    $category = new \core_user\output\myprofile\category(
        'repeatablefields',
        get_string('profilewidgettitle', 'profilefield_repeatable'),
        'contact'
    );
    $tree->add_category($category);

    // Add each repeatable field as a node with rendered HTML content.
    foreach ($repeatablefields as $formfield) {
        $node = new \core_user\output\myprofile\node(
            'repeatablefields',
            'repeatable_' . $formfield->field->shortname,
            $formfield->display_name(),
            null,
            null,
            $formfield->display_data()
        );
        $tree->add_node($node);
    }
}
