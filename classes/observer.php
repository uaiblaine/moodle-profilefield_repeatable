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

namespace profilefield_repeatable;

/**
 * Event observers cleaning up storage rows orphaned by core deletions.
 *
 * Core deletes {user_info_data} directly when a user or a custom profile
 * field is removed, so {profilefield_repeatable_data} rows must be purged
 * here or they are retained forever and become invisible to the privacy
 * API (which discovers contexts through user_info_data).
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine (anderson@blaine.com.br)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Remove storage rows belonging to a deleted user.
     *
     * @param \core\event\user_deleted $event
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        global $DB;

        $userid = (int)$event->objectid;
        if ($userid <= 0) {
            return;
        }

        $DB->delete_records('profilefield_repeatable_data', ['userid' => $userid]);
    }

    /**
     * Remove storage rows belonging to a deleted repeatable profile field.
     *
     * Also queues index reconciliation on PostgreSQL so the per-field
     * expression indexes are dropped without waiting for the orphan sweep.
     *
     * @param \core\event\user_info_field_deleted $event
     */
    public static function user_info_field_deleted(\core\event\user_info_field_deleted $event): void {
        global $DB;

        if (($event->other['datatype'] ?? '') !== 'repeatable') {
            return;
        }

        $fieldid = (int)$event->objectid;
        if ($fieldid <= 0) {
            return;
        }

        $DB->delete_records('profilefield_repeatable_data', ['fieldid' => $fieldid]);

        if ($DB->get_dbfamily() === 'postgres') {
            task\reconcile_indexes::schedule_for_field($fieldid);
        }
    }
}
