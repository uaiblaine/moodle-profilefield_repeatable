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

namespace profilefield_repeatable\task;

use core\task\scheduled_task;
use profilefield_repeatable\local\projection;

/**
 * Rebuild the per-set projection of every user whose canonical payload changed behind the plugin.
 *
 * Writers that change {user_info_data} without a later user event the observers can use (auth_db's
 * sync of existing users, any direct write) leave {profilefield_repeatable_data} stale; profile
 * pages are unaffected because they read the payload, but Report Builder lists the stale sets
 * until this task runs.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reproject_sets extends scheduled_task {
    /**
     * Task name shown in the admin UI.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskreprojectsets', 'profilefield_repeatable');
    }

    /**
     * Compare every repeatable field's payloads with their projection and rewrite the stale ones.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $fields = $DB->get_records(
            'user_info_field',
            ['datatype' => 'repeatable'],
            'id ASC',
            'id, shortname, param1, param2, param3, param4'
        );
        foreach ($fields as $field) {
            $count = projection::reproject_field($field);
            if ($count > 0) {
                mtrace('profilefield_repeatable: rebuilt ' . $count . ' projection(s) of field ' . $field->shortname);
            }
        }
    }
}
