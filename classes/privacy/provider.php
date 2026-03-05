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
 * Privacy class for requesting user data.
 *
 * @package    profilefield_repeatable
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace profilefield_repeatable\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;

/**
 * Privacy class for requesting user data.
 *
 * @package    profilefield_repeatable
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    /**
     * Returns metadata about this plugin.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        return $collection->add_database_table('user_info_data', [
            'userid' => 'privacy:metadata:profilefield_repeatable:userid',
            'fieldid' => 'privacy:metadata:profilefield_repeatable:fieldid',
            'data' => 'privacy:metadata:profilefield_repeatable:data',
            'dataformat' => 'privacy:metadata:profilefield_repeatable:dataformat',
        ], 'privacy:metadata:profilefield_repeatable:tableexplanation');
    }

    /**
     * Get contexts containing user information for this plugin.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $sql = "SELECT ctx.id
                  FROM {user_info_data} uda
                  JOIN {user_info_field} uif ON uda.fieldid = uif.id
                  JOIN {context} ctx ON ctx.instanceid = uda.userid
                       AND ctx.contextlevel = :contextlevel
                 WHERE uda.userid = :userid
                       AND uif.datatype = :datatype";

        $params = [
            'userid' => $userid,
            'contextlevel' => CONTEXT_USER,
            'datatype' => 'repeatable',
        ];

        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Get users with data in a specific context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_user) {
            return;
        }

        $sql = "SELECT uda.userid
                  FROM {user_info_data} uda
                  JOIN {user_info_field} uif ON uda.fieldid = uif.id
                 WHERE uda.userid = :userid
                       AND uif.datatype = :datatype";

        $params = [
            'userid' => $context->instanceid,
            'datatype' => 'repeatable',
        ];

        $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Export all user data in approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_USER || $context->instanceid !== $user->id) {
                continue;
            }

            $results = static::get_records($user->id);
            foreach ($results as $result) {
                $data = (object) [
                    'name' => $result->name,
                    'description' => $result->description,
                    'data' => $result->data,
                ];

                \core_privacy\local\request\writer::with_context($context)->export_data([
                    get_string('pluginname', 'profilefield_repeatable'),
                ], $data);
            }
        }
    }

    /**
     * Delete all user data in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        if ($context->contextlevel === CONTEXT_USER) {
            static::delete_data($context->instanceid);
        }
    }

    /**
     * Delete user data for approved users in a context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();

        if ($context instanceof \context_user) {
            static::delete_data($context->instanceid);
        }
    }

    /**
     * Delete all user data in approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel === CONTEXT_USER && $context->instanceid === $user->id) {
                static::delete_data($context->instanceid);
            }
        }
    }

    /**
     * Delete data rows related to this field type for one user.
     *
     * @param int $userid
     */
    protected static function delete_data(int $userid): void {
        global $DB;

        $params = [
            'userid' => $userid,
            'datatype' => 'repeatable',
        ];

        $DB->delete_records_select('user_info_data', "fieldid IN (
                SELECT id FROM {user_info_field} WHERE datatype = :datatype)
                AND userid = :userid", $params);
    }

    /**
     * Fetch stored rows related to this field type for one user.
     *
     * @param int $userid
     * @return array
     */
    protected static function get_records(int $userid): array {
        global $DB;

        $sql = "SELECT *
                  FROM {user_info_data} uda
                  JOIN {user_info_field} uif ON uda.fieldid = uif.id
                 WHERE uda.userid = :userid
                       AND uif.datatype = :datatype";

        $params = [
            'userid' => $userid,
            'datatype' => 'repeatable',
        ];

        return $DB->get_records_sql($sql, $params);
    }
}
