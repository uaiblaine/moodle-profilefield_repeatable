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

use local_repeatable\local\payload;
use local_repeatable\local\schema;

/**
 * Re-keys the stored payloads of a field converted to the version 2 schema.
 *
 * Saving a field still on the version 1 parameters in the definition form
 * ({@see \profile_define_repeatable::define_save()}) writes the field's legacy labels, then the
 * schema, and queues {@see \profilefield_repeatable\task\rekey_payloads}, which calls
 * {@see self::finish_field()}. Until the legacy labels are removed, every reader resolves
 * label-keyed sets through them, so a field halfway through hides nothing.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class migration {
    /** @var int Rows read and written per transaction. */
    public const CHUNK_SIZE = 200;

    /**
     * Re-key every payload of a field, rebuild its projection and drop its legacy labels.
     *
     * Idempotent: a payload already keyed by key normalises to itself and is not written.
     *
     * @param int $fieldid
     * @return int Number of payloads rewritten.
     */
    public static function finish_field(int $fieldid): int {
        global $DB;

        $field = $DB->get_record('user_info_field', ['id' => $fieldid, 'datatype' => 'repeatable']);
        if (!$field) {
            unset_config(field_schema::legacy_config_name($fieldid), 'profilefield_repeatable');
            return 0;
        }
        if (schema::decode((string)$field->param1) === null) {
            // Still on the version 1 parameters: nothing to re-key until the definition form converts it.
            return 0;
        }

        $schema = field_schema::from_field($field);
        $rewritten = self::rekey_payloads($fieldid, $schema);
        projection\projector::reproject_field(new source(), $fieldid, $schema);
        unset_config(field_schema::legacy_config_name($fieldid), 'profilefield_repeatable');

        return $rewritten;
    }

    /**
     * Rewrite every payload of a field in its normalised, key-keyed form.
     *
     * Rows are read in id order, CHUNK_SIZE at a time, and each chunk is written in one
     * transaction. A write is conditional on the stored text being the one read: a user save
     * landing in between already wrote the key-keyed form and is kept.
     *
     * @param int $fieldid
     * @param schema $schema The field's schema with its legacy labels.
     * @return int Number of payloads written.
     */
    public static function rekey_payloads(int $fieldid, schema $schema): int {
        global $DB;

        $rewritten = 0;
        $lastid = 0;
        do {
            $rows = $DB->get_records_select(
                'user_info_data',
                'fieldid = :fieldid AND id > :lastid',
                ['fieldid' => $fieldid, 'lastid' => $lastid],
                'id ASC',
                'id, data',
                0,
                self::CHUNK_SIZE
            );
            if (empty($rows)) {
                break;
            }

            $transaction = $DB->start_delegated_transaction();
            foreach ($rows as $row) {
                $lastid = (int)$row->id;
                $json = payload::encode(payload::normalise($row->data, $schema));
                if ($json === (string)$row->data) {
                    continue;
                }
                if (self::write_if_unchanged($lastid, (string)$row->data, $json)) {
                    $rewritten++;
                }
            }
            $transaction->allow_commit();
        } while (count($rows) === self::CHUNK_SIZE);

        return $rewritten;
    }

    /**
     * Replace a stored payload, unless it is no longer exactly the text that was read.
     *
     * On PostgreSQL and MySQL/MariaDB the comparison is part of the UPDATE, case and accent
     * sensitive ({@see \moodle_database::sql_equal()}: MariaDB's default collation would
     * otherwise take "Alice" for "alice"). Other families compare text columns only through
     * truncating casts, so the row is re-read and compared in PHP, inside the caller's
     * transaction. Called inside the transaction of a chunk ({@see self::rekey_payloads()}).
     *
     * @param int $dataid The {user_info_data} id.
     * @param string $old The payload as read.
     * @param string $new The payload to write.
     * @return bool Whether the stored payload is now $new because of this call.
     */
    public static function write_if_unchanged(int $dataid, string $old, string $new): bool {
        global $DB;

        if (in_array($DB->get_dbfamily(), ['postgres', 'mysql'], true)) {
            $DB->set_field_select(
                'user_info_data',
                'data',
                $new,
                'id = :id AND ' . $DB->sql_equal('data', ':olddata', true, true),
                ['id' => $dataid, 'olddata' => $old]
            );
            return $old !== $new && $DB->get_field('user_info_data', 'data', ['id' => $dataid]) === $new;
        }

        if ($DB->get_field('user_info_data', 'data', ['id' => $dataid]) !== $old) {
            return false;
        }
        $DB->set_field('user_info_data', 'data', $new, ['id' => $dataid]);
        return true;
    }
}
