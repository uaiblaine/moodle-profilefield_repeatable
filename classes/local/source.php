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

namespace profilefield_repeatable\local;

/**
 * The profile field's canonical payloads and projection rows, as the engine reads them.
 *
 * Profile adapter: the canonical payload is {user_info_data}.data, the owner is the user, and the
 * projection is {profilefield_repeatable_data}, owned by userid. Implements the engine contract
 * {@see projection\source}.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source implements projection\source {
    /** @var string The projection table of the profile field. */
    public const TABLE = 'profilefield_repeatable_data';

    /**
     * The plugin that owns the data.
     *
     * @return string
     */
    public function component(): string {
        return 'profilefield_repeatable';
    }

    /**
     * The projection table, owned by userid.
     *
     * @return projection\store
     */
    public function store(): projection\store {
        return new projection\store(self::TABLE, 'userid');
    }

    /**
     * The schema of a repeatable profile field, version 2 or version 1.
     *
     * @param int $fieldid
     * @return schema|null Null when no repeatable profile field has that id.
     */
    public function schema(int $fieldid): ?schema {
        global $DB;

        $field = $DB->get_record(
            'user_info_field',
            ['id' => $fieldid, 'datatype' => 'repeatable'],
            'id, param1, param2, param3, param4'
        );
        return $field ? field_schema::from_field($field) : null;
    }

    /**
     * One {user_info_data} record, read as it is now.
     *
     * @param int $dataid
     * @return \stdClass|null {id, fieldid, ownerid (the user), data}, or null when it is gone.
     */
    public function canonical(int $dataid): ?\stdClass {
        global $DB;

        $record = $DB->get_record('user_info_data', ['id' => $dataid], 'id, userid, fieldid, data');
        if (!$record) {
            return null;
        }
        return (object)[
            'id' => (int)$record->id,
            'fieldid' => (int)$record->fieldid,
            'ownerid' => (int)$record->userid,
            'data' => $record->data,
        ];
    }

    /**
     * Every {user_info_data} record of one field, in id order.
     *
     * @param int $fieldid
     * @return \moodle_recordset Records (id, userid, data).
     */
    public function canonical_recordset(int $fieldid): \moodle_recordset {
        global $DB;

        return $DB->get_recordset('user_info_data', ['fieldid' => $fieldid], 'id ASC', 'id, userid, data');
    }

    /**
     * The projection rows of one user's value of one field.
     *
     * @param int $fieldid
     * @param int $ownerid The user id.
     * @return \stdClass[] Rows (id, set_id, data, timemodified) in set order.
     */
    public function projection_rows(int $fieldid, int $ownerid): array {
        global $DB;

        return array_values($DB->get_records(
            self::TABLE,
            ['fieldid' => $fieldid, 'userid' => $ownerid],
            'set_id ASC, id ASC',
            'id, set_id, data, timemodified'
        ));
    }
}
