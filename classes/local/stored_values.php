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

use profilefield_repeatable\helper;

/**
 * What a field's stored payloads hold, read from the canonical {user_info_data} rows.
 *
 * Never from the projection: core's profile_save_custom_fields() writes payloads without
 * rebuilding it, so a projection can miss values that exist.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class stored_values {
    /**
     * Which column keys hold a non-blank value in some set of one field, and how many users have sets.
     *
     * One recordset pass over the field's payloads, each read as the field reads it
     * ({@see helper::normalise_payload()}: under the key or its legacy label). The definition
     * form locks these keys in the editor and rejects a schema that drops one.
     *
     * @param int $fieldid
     * @param schema $schema The field's stored schema ({@see schema::from_field()}).
     * @return array ['users' => int, 'keys' => string[] the keys in use, in schema order].
     */
    public static function keys_in_use(int $fieldid, schema $schema): array {
        global $DB;

        $inuse = [];
        $users = 0;
        $rs = $DB->get_recordset('user_info_data', ['fieldid' => $fieldid], 'id ASC', 'id, data');
        foreach ($rs as $row) {
            $payload = helper::normalise_payload($row->data, $schema);
            if (!empty($payload)) {
                $users++;
            }
            foreach ($payload as $set) {
                foreach ($set as $key => $value) {
                    if (trim($value) !== '') {
                        $inuse[$key] = true;
                    }
                }
            }
        }
        $rs->close();

        return [
            'users' => $users,
            'keys' => array_values(array_filter($schema->keys(), static fn(string $key): bool => isset($inuse[$key]))),
        ];
    }

    /**
     * Key => stored label of every column of a field whose key holds values.
     *
     * @param \stdClass $field The stored {user_info_field} record.
     * @return string[]
     */
    public static function locked_labels(\stdClass $field): array {
        $schema = schema::from_field($field);
        $labels = [];
        foreach (self::keys_in_use((int)$field->id, $schema)['keys'] as $key) {
            $labels[$key] = $schema->label($key);
        }
        return $labels;
    }
}
