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

use local_repeatable\local\schema;
use local_repeatable\local\stored_values as engine_stored_values;

/**
 * What a field's stored payloads hold, read from the canonical {user_info_data} rows.
 *
 * Profile adapter of {@see engine_stored_values}: it reads the payloads and the engine decides
 * which keys hold values. Never from the projection: core's profile_save_custom_fields() writes
 * payloads without rebuilding it, so a projection can miss values that exist.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class stored_values {
    /**
     * Which column keys hold a non-blank value in some set of one field, and how many users have sets.
     *
     * One recordset pass over the field's payloads, handed to
     * {@see engine_stored_values::keys_in_use()}, which reads each as the field reads it (under the
     * key or its legacy label). The definition form locks these keys in the editor and rejects a
     * schema that drops one.
     *
     * @param int $fieldid
     * @param schema $schema The field's stored schema ({@see field_schema::from_field()}).
     * @return array ['users' => int, 'keys' => string[] the keys in use, in schema order].
     */
    public static function usage(int $fieldid, schema $schema): array {
        global $DB;

        $rs = $DB->get_recordset('user_info_data', ['fieldid' => $fieldid], 'id ASC', 'id, data');
        try {
            return engine_stored_values::keys_in_use(self::payloads($rs), $schema);
        } finally {
            $rs->close();
        }
    }

    /**
     * The raw payload of each record of a recordset, without loading them all.
     *
     * @param \moodle_recordset $rs Records with a `data` property.
     * @return \Generator The payloads, as stored.
     */
    private static function payloads(\moodle_recordset $rs): \Generator {
        foreach ($rs as $row) {
            yield $row->data;
        }
    }

    /**
     * Key => stored label of every column of a field whose key holds values.
     *
     * @param \stdClass $field The stored {user_info_field} record.
     * @return string[]
     */
    public static function locked_labels(\stdClass $field): array {
        $schema = field_schema::from_field($field);
        $labels = [];
        foreach (self::usage((int)$field->id, $schema)['keys'] as $key) {
            $labels[$key] = $schema->label($key);
        }
        return $labels;
    }
}
