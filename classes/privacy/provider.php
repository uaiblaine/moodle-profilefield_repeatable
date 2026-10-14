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
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace profilefield_repeatable\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;

/**
 * Privacy provider for repeatable field payloads and their per-set projection rows.
 *
 * Projection rows are reached only through the user's {user_info_data} records; rows left behind
 * by core's direct deletes are purged by {@see \profilefield_repeatable\observer} instead.
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
        $collection->add_database_table('user_info_data', [
            'userid' => 'privacy:metadata:profilefield_repeatable:userid',
            'fieldid' => 'privacy:metadata:profilefield_repeatable:fieldid',
            'data' => 'privacy:metadata:profilefield_repeatable:data',
            'dataformat' => 'privacy:metadata:profilefield_repeatable:dataformat',
        ], 'privacy:metadata:profilefield_repeatable:tableexplanation');

        return $collection->add_database_table('profilefield_repeatable_data', [
            'dataid' => 'privacy:metadata:profilefield_repeatable_data:dataid',
            'fieldid' => 'privacy:metadata:profilefield_repeatable_data:fieldid',
            'userid' => 'privacy:metadata:profilefield_repeatable_data:userid',
            'set_id' => 'privacy:metadata:profilefield_repeatable_data:set_id',
            'data' => 'privacy:metadata:profilefield_repeatable_data:data',
            'timecreated' => 'privacy:metadata:profilefield_repeatable_data:timecreated',
            'timemodified' => 'privacy:metadata:profilefield_repeatable_data:timemodified',
        ], 'privacy:metadata:profilefield_repeatable_data:tableexplanation');
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
        if (!$context instanceof \core\context\user) {
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
     * Exports the sets of each field, each set's values under the column labels
     * ({@see self::export_sets()}); the projection rows are derived from the payload and are not
     * exported.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_USER || (int)$context->instanceid !== (int)$user->id) {
                continue;
            }

            $results = static::get_records($user->id);
            foreach ($results as $result) {
                $data = (object) [
                    'name' => $result->name,
                    'description' => $result->description,
                    'sets' => static::export_sets($result),
                ];

                /* One subcontext per field: a shared subcontext would make each
                 * export_data() call overwrite the previous field's data. */
                \core_privacy\local\request\writer::with_context($context)->export_data([
                    get_string('pluginname', 'profilefield_repeatable'),
                    format_string((string)$result->name),
                ], $data);
            }
        }
    }

    /**
     * The sets of one stored payload, keyed "Set N", each mapping a column name to its value.
     *
     * Every stored set with a value is exported, read through the field's schema (so a payload
     * not yet re-keyed exports the same way). Values stored under a name the schema no longer
     * reads (a removed column) are exported under that raw name. A name already used in the set
     * gets the key or the raw name appended, so no value overwrites another. A pattern value is
     * exported as it is shown ({@see \profilefield_repeatable\local\pattern_columns::format()}),
     * as plain text like every other value: the export is JSON, not HTML.
     *
     * @param \stdClass $record A {@see self::get_records()} row: data, fieldid, param1..param4.
     * @return array
     */
    protected static function export_sets(\stdClass $record): array {
        $schema = \profilefield_repeatable\local\field_schema::from_field((object)[
            'id' => $record->fieldid,
            'param1' => $record->param1,
            'param2' => $record->param2,
            'param3' => $record->param3,
            'param4' => $record->param4,
        ]);
        $legacy = $schema->legacy_labels();
        $context = \core\context\system::instance();
        $decoded = json_decode((string)$record->data, true);

        $sets = [];
        foreach (is_array($decoded) ? $decoded : [] as $rawset) {
            if (!is_array($rawset)) {
                continue;
            }
            $values = [];
            $read = [];
            foreach ($schema->keys() as $key) {
                $read[$key] = true;
                if (isset($legacy[$key])) {
                    $read[$legacy[$key]] = true;
                }
                $label = format_string($schema->label($key), true, ['context' => $context, 'escape' => false]);
                $value = self::export_value(\profilefield_repeatable\helper::column_value($rawset, $key, $legacy[$key] ?? null));
                $patternconfig = $schema->pattern_config($key);
                if ($patternconfig !== null && trim($value) !== '') {
                    $value = \profilefield_repeatable\local\pattern_columns::format($patternconfig, $value);
                }
                $values[self::unique_name($values, $label, $key)] = $value;
            }
            foreach ($rawset as $name => $value) {
                if (!isset($read[$name])) {
                    $values[self::unique_name($values, (string)$name, (string)$name)] = self::export_value($value);
                }
            }

            if (array_filter($values, static fn(string $value): bool => trim($value) !== '')) {
                $sets[get_string('repeatableset', 'profilefield_repeatable', count($sets) + 1)] = $values;
            }
        }

        return $sets;
    }

    /**
     * A name not yet used in an exported set: the name itself, else "name (suffix)", numbered if needed.
     *
     * @param array $values The set built so far.
     * @param string $name
     * @param string $suffix The column key or raw name.
     * @return string
     */
    private static function unique_name(array $values, string $name, string $suffix): string {
        $candidate = $name;
        $counter = 1;
        while (array_key_exists($candidate, $values)) {
            $candidate = $name . ' (' . $suffix . ($counter > 1 ? ' ' . $counter : '') . ')';
            $counter++;
        }
        return $candidate;
    }

    /**
     * One stored value as exported text.
     *
     * @param mixed $value
     * @return string
     */
    private static function export_value($value): string {
        if ($value === null) {
            return '';
        }
        return is_scalar($value) ? (string)$value : (string)json_encode($value, JSON_UNESCAPED_UNICODE);
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

        if ($context instanceof \core\context\user) {
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
            if ($context->contextlevel === CONTEXT_USER && (int)$context->instanceid === (int)$user->id) {
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

        if (!self::has_storage_table()) {
            $params = [
                'userid' => $userid,
                'datatype' => 'repeatable',
            ];

            $DB->delete_records_select('user_info_data', "fieldid IN (
                    SELECT id FROM {user_info_field} WHERE datatype = :datatype)
                    AND userid = :userid", $params);
            return;
        }

        $dataids = $DB->get_fieldset_sql(
            "SELECT uda.id
               FROM {user_info_data} uda
               JOIN {user_info_field} uif ON uif.id = uda.fieldid
              WHERE uda.userid = :userid
                AND uif.datatype = :datatype",
            [
                'userid' => $userid,
                'datatype' => 'repeatable',
            ]
        );

        if (!empty($dataids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($dataids, SQL_PARAMS_NAMED, 'dataid');
            $DB->delete_records_select('profilefield_repeatable_data', "dataid $insql", $inparams);
        }

        $params = [
            'userid' => $userid,
            'datatype' => 'repeatable',
        ];

        $DB->delete_records_select('user_info_data', "fieldid IN (
                SELECT id FROM {user_info_field} WHERE datatype = :datatype)
                AND userid = :userid", $params);
    }

    /**
     * Check if the storage table is available.
     *
     * @return bool
     */
    protected static function has_storage_table(): bool {
        static $hastable = null;

        if ($hastable === null) {
            global $DB;
            $hastable = $DB->get_manager()->table_exists(new \xmldb_table('profilefield_repeatable_data'));
        }

        return $hastable;
    }

    /**
     * Fetch stored rows related to this field type for one user.
     *
     * @param int $userid
     * @return array
     */
    protected static function get_records(int $userid): array {
        global $DB;

        $sql = "SELECT uda.id, uda.userid, uda.fieldid, uda.data, uda.dataformat,
                       uif.shortname, uif.name, uif.description, uif.param1, uif.param2, uif.param3, uif.param4
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
