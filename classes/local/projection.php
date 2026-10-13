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
 * Keeps {profilefield_repeatable_data} a projection of the canonical payload.
 *
 * The payload in {user_info_data}.data is the single source of truth; every profile read
 * decodes it. The per-set rows exist only so Report Builder can list one row per set and
 * filter on sub-item values in SQL. Core's profile_save_custom_fields() (auth_db and
 * auth_ldap attribute sync) writes {user_info_data} without calling this plugin, so the
 * rows are rebuilt from the payload whenever they no longer match it.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class projection {
    /**
     * Rewrite the per-set rows of one {user_info_data} record from a normalised payload.
     *
     * Set N of the payload becomes the row with set_id N; rows for vanished sets are deleted.
     * The caller owns the transaction.
     *
     * Internal API, not for other plugins: only
     * profile_field_repeatable::edit_save_data() and the reprojection paths
     * ({@see self::reproject_record()}) call it. The dataid, fieldid and userid must belong to the
     * same {user_info_data} record, and the payload must come from {@see helper::normalise_payload()};
     * nothing here checks either.
     *
     * @param int $dataid The {user_info_data}.id the rows belong to.
     * @param int $fieldid The profile field id.
     * @param int $userid The user id.
     * @param array $payload Normalised payload ({@see helper::normalise_payload()}).
     * @return void
     */
    public static function sync(int $dataid, int $fieldid, int $userid, array $payload): void {
        global $DB;

        $now = time();
        $persistedsetids = [];
        $setid = 1;

        foreach ($payload as $setdata) {
            if (!is_array($setdata)) {
                $setid++;
                continue;
            }

            $jsonset = json_encode($setdata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($jsonset === false) {
                debugging(
                    'profilefield_repeatable: JSON encode failed for field ' . $fieldid .
                    ', user ' . $userid . ', set ' . $setid . ': ' . json_last_error_msg(),
                    DEBUG_DEVELOPER
                );
                $setid++;
                continue;
            }

            self::upsert_set_row($dataid, $fieldid, $userid, $setid, $jsonset, $now);
            $persistedsetids[] = $setid;
            $setid++;
        }

        if (empty($persistedsetids)) {
            $DB->delete_records('profilefield_repeatable_data', ['dataid' => $dataid]);
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($persistedsetids, SQL_PARAMS_NAMED, 'setid', false);
        $params['dataid'] = $dataid;
        $DB->delete_records_select('profilefield_repeatable_data', "dataid = :dataid AND set_id $insql", $params);
    }

    /**
     * Whether the stored rows are exactly the projection of a normalised payload.
     *
     * Rows are compared as decoded arrays with sorted keys, because PostgreSQL JSONB does not
     * keep the key order or spacing of the JSON it was given.
     *
     * @param array $payload Normalised payload ({@see helper::normalise_payload()}).
     * @param \stdClass[] $records Stored rows (set_id, data) of one {user_info_data} record.
     * @return bool
     */
    public static function is_current(array $payload, array $records): bool {
        // Every set of a normalised payload is an array that encodes to JSON, so sync() writes one row per set
        // ({@see helper::normalise_payload()}); a count mismatch therefore always means stale rows.
        if (count($payload) !== count($records)) {
            return false;
        }

        $stored = [];
        foreach ($records as $record) {
            $stored[(int)$record->set_id] = self::canonical_set(json_decode((string)$record->data, true));
        }

        $setid = 1;
        foreach ($payload as $setdata) {
            if (!isset($stored[$setid]) || $stored[$setid] !== self::canonical_set($setdata)) {
                return false;
            }
            $setid++;
        }

        return true;
    }

    /**
     * Canonical string form of one set, used to compare a stored row with a payload set.
     *
     * @param mixed $set Decoded set.
     * @return string|null Null when the set is not an array.
     */
    public static function canonical_set($set): ?string {
        if (!is_array($set)) {
            return null;
        }

        ksort($set, SORT_STRING);
        $json = json_encode($set, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? null : $json;
    }

    /**
     * Rebuild the rows of every repeatable field of one user whose rows no longer match the payload.
     *
     * @param int $userid
     * @return int Number of {user_info_data} records whose rows were rewritten.
     */
    public static function reproject_user(int $userid): int {
        global $DB;

        if ($userid <= 0) {
            return 0;
        }

        $datarecords = $DB->get_records_sql(
            "SELECT d.id, d.userid, d.fieldid, d.data, f.param1, f.param2, f.param3, f.param4
               FROM {user_info_data} d
               JOIN {user_info_field} f ON f.id = d.fieldid
              WHERE d.userid = :userid
                AND f.datatype = :datatype",
            ['userid' => $userid, 'datatype' => 'repeatable']
        );
        if (empty($datarecords)) {
            return 0;
        }

        $rowsbydataid = [];
        $rows = $DB->get_records(
            'profilefield_repeatable_data',
            ['userid' => $userid],
            'dataid ASC, set_id ASC',
            'id, dataid, set_id, data'
        );
        foreach ($rows as $row) {
            $rowsbydataid[(int)$row->dataid][] = $row;
        }

        $stale = [];
        foreach ($datarecords as $datarecord) {
            $schema = schema::from_field((object)[
                'id' => $datarecord->fieldid,
                'param1' => $datarecord->param1,
                'param2' => $datarecord->param2,
                'param3' => $datarecord->param3,
                'param4' => $datarecord->param4,
            ]);
            $payload = helper::normalise_payload($datarecord->data, $schema);
            if (!self::is_current($payload, $rowsbydataid[(int)$datarecord->id] ?? [])) {
                $stale[] = [(int)$datarecord->id, $schema];
            }
        }

        return self::write_stale($stale);
    }

    /**
     * Rebuild the rows of every user of one repeatable field whose rows no longer match the payload.
     *
     * Walks the payloads and the rows of the field in two ordered recordsets, so each run costs
     * two queries per field plus one {@see self::reproject_record()} transaction per stale record.
     *
     * @param \stdClass $field The {user_info_field} record (id, param1..param4).
     * @return int Number of {user_info_data} records whose rows were rewritten.
     */
    public static function reproject_field(\stdClass $field): int {
        global $DB;

        $fieldid = (int)$field->id;
        $schema = schema::from_field($field);

        $datars = $DB->get_recordset('user_info_data', ['fieldid' => $fieldid], 'id ASC', 'id, userid, data');
        $rowrs = $DB->get_recordset(
            'profilefield_repeatable_data',
            ['fieldid' => $fieldid],
            'dataid ASC, set_id ASC',
            'id, dataid, set_id, data'
        );

        // Writes wait until both recordsets are closed: a PostgreSQL cursor stays open meanwhile.
        $stale = [];
        foreach ($datars as $datarecord) {
            $dataid = (int)$datarecord->id;
            $records = [];
            while ($rowrs->valid() && (int)$rowrs->current()->dataid < $dataid) {
                $rowrs->next();
            }
            while ($rowrs->valid() && (int)$rowrs->current()->dataid === $dataid) {
                $records[] = $rowrs->current();
                $rowrs->next();
            }

            $payload = helper::normalise_payload($datarecord->data, $schema);
            if (!self::is_current($payload, $records)) {
                $stale[] = [$dataid, $schema];
            }
        }
        $datars->close();
        $rowrs->close();

        return self::write_stale($stale);
    }

    /**
     * Rewrite the rows of each stale record, one transaction per record.
     *
     * @param array $stale List of [dataid, schema].
     * @return int Number of records rewritten.
     */
    private static function write_stale(array $stale): int {
        $written = 0;
        foreach ($stale as [$dataid, $schema]) {
            if (self::reproject_record($dataid, $schema)) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * Rebuild the rows of one {user_info_data} record from its payload as it is now.
     *
     * The staleness check that selected the record ran on a snapshot taken before this
     * transaction. A user save may land in between, so the record is re-read inside the
     * transaction and the payload and the staleness are decided again from that read: a
     * projection written from the snapshot would overwrite the newer save's rows.
     *
     * Internal API: called by the reprojection paths of this class and by its tests.
     *
     * @param int $dataid The {user_info_data}.id.
     * @param schema $schema Column schema of the field ({@see schema::from_field()}).
     * @return bool Whether rows were rewritten; false when the record is gone or already current.
     */
    public static function reproject_record(int $dataid, schema $schema): bool {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        $datarecord = $DB->get_record('user_info_data', ['id' => $dataid], 'id, userid, fieldid, data');
        if (!$datarecord) {
            $transaction->allow_commit();
            return false;
        }

        $payload = helper::normalise_payload($datarecord->data, $schema);
        $records = $DB->get_records(
            'profilefield_repeatable_data',
            ['dataid' => $dataid],
            'set_id ASC',
            'id, dataid, set_id, data'
        );
        if (self::is_current($payload, $records)) {
            $transaction->allow_commit();
            return false;
        }

        self::sync($dataid, (int)$datarecord->fieldid, (int)$datarecord->userid, $payload);
        $transaction->allow_commit();

        return true;
    }

    /**
     * Insert or update one set row, preserving timecreated on update.
     *
     * PostgreSQL upserts with ON CONFLICT on the unique (dataid, set_id) index; other families
     * select then write. A schema or semantic change must land in both branches.
     *
     * @param int $dataid
     * @param int $fieldid
     * @param int $userid
     * @param int $setid
     * @param string $jsonset
     * @param int $now
     * @return void
     */
    private static function upsert_set_row(
        int $dataid,
        int $fieldid,
        int $userid,
        int $setid,
        string $jsonset,
        int $now
    ): void {
        global $DB;

        if (!helper::is_postgres($DB)) {
            $existing = $DB->get_record(
                'profilefield_repeatable_data',
                ['dataid' => $dataid, 'set_id' => $setid],
                'id',
                IGNORE_MISSING
            );

            if ($existing) {
                $DB->update_record('profilefield_repeatable_data', (object) [
                    'id' => (int)$existing->id,
                    'fieldid' => $fieldid,
                    'userid' => $userid,
                    'data' => $jsonset,
                    'timemodified' => $now,
                ]);
                return;
            }

            $DB->insert_record('profilefield_repeatable_data', (object) [
                'dataid' => $dataid,
                'fieldid' => $fieldid,
                'userid' => $userid,
                'set_id' => $setid,
                'data' => $jsonset,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            return;
        }

        $sql = "INSERT INTO {profilefield_repeatable_data}
                    (dataid, fieldid, userid, set_id, data, timecreated, timemodified)
                VALUES
                    (:dataid, :fieldid, :userid, :setid, :data, :timecreated, :timemodified)
                ON CONFLICT (dataid, set_id)
                DO UPDATE SET
                    fieldid = EXCLUDED.fieldid,
                    userid = EXCLUDED.userid,
                    data = EXCLUDED.data,
                    timemodified = EXCLUDED.timemodified";

        $DB->execute($sql, [
            'dataid' => $dataid,
            'fieldid' => $fieldid,
            'userid' => $userid,
            'setid' => $setid,
            'data' => $jsonset,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }
}
