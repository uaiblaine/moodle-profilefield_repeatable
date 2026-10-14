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
 * Event observers keeping {profilefield_repeatable_data} in step with core writes.
 *
 * Core deletes {user_info_data} directly when a user or a custom profile
 * field is removed, so {profilefield_repeatable_data} rows must be purged
 * here: the privacy provider finds them only through {user_info_data} and
 * would never see the orphans.
 *
 * Core's profile_save_custom_fields() also rewrites {user_info_data} directly. auth_ldap's
 * update_user_record() triggers user_updated (and sync_users() user_created, for new accounts)
 * BEFORE it calls profile_save_custom_fields(), but inside the delegated transaction the sync
 * opens around each user. The user_updated and user_created observers are therefore declared
 * non-internal in db/events.php: core buffers them until that transaction commits, so they see
 * the new payload and rebuild the projection. Outside a transaction a non-internal observer
 * still runs at once, before profile_save_custom_fields(), and sees the old payload. The
 * reproject_sets scheduled task covers that case and auth_db's sync, which updates existing
 * users without any event ({@see \profilefield_repeatable\task\reproject_sets}).
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
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
     * Rebuild the projection of an updated user when it no longer matches the payload.
     *
     * @param \core\event\user_updated $event
     */
    public static function user_updated(\core\event\user_updated $event): void {
        local\projection::reproject_user((int)$event->objectid);
    }

    /**
     * Rebuild the projection of a created user when it no longer matches the payload.
     *
     * @param \core\event\user_created $event
     */
    public static function user_created(\core\event\user_created $event): void {
        local\projection::reproject_user((int)$event->objectid);
    }

    /**
     * Remove storage rows belonging to a deleted repeatable profile field.
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
    }
}
