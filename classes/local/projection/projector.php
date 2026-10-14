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

namespace profilefield_repeatable\local\projection;

use local_repeatable\local\payload;
use local_repeatable\local\schema;
use local_repeatable\local\sql;

/**
 * Keeps an adapter's projection table a projection of its canonical payloads.
 *
 * Engine side: the canonical payload and the projection table belong to the adapter, read and
 * written only through its {@see source}. The payload is the single source of truth and every
 * read decodes it; the per-set rows exist only so Report Builder can list one row per set and
 * filter on sub-item values in SQL. A writer can change the payload without calling the engine
 * (for a profile field, core's profile_save_custom_fields() under auth_db and auth_ldap sync), so
 * the rows are rebuilt from the payload whenever they no longer match it.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class projector {
    /**
     * Rewrite the per-set rows of one canonical record from a normalised payload.
     *
     * Set N of the payload becomes the row with set_id N; rows for vanished sets are deleted.
     * The caller owns the transaction, in which it also writes the canonical record.
     *
     * Internal API, not for other plugins: only an adapter's payload write and the reprojection
     * paths ({@see self::reproject_record()}) call it. The dataid, fieldid and owner id must belong
     * to the same canonical record, and the payload must come from {@see payload::normalise()};
     * nothing here checks either.
     *
     * @param source $source The adapter.
     * @param int $dataid The canonical record's id.
     * @param int $fieldid The field id.
     * @param int $ownerid The owner id (the user, for a profile field).
     * @param array $payload Normalised payload ({@see payload::normalise()}).
     * @return void
     */
    public static function sync(source $source, int $dataid, int $fieldid, int $ownerid, array $payload): void {
        global $DB;

        $store = $source->store();
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
                    $source->component() . ': JSON encode failed for field ' . $fieldid .
                    ', owner ' . $ownerid . ', set ' . $setid . ': ' . json_last_error_msg(),
                    DEBUG_DEVELOPER
                );
                $setid++;
                continue;
            }

            self::upsert_set_row($store, $dataid, $fieldid, $ownerid, $setid, $jsonset, $now);
            $persistedsetids[] = $setid;
            $setid++;
        }

        if (empty($persistedsetids)) {
            $DB->delete_records($store->table(), ['dataid' => $dataid]);
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($persistedsetids, SQL_PARAMS_NAMED, 'setid', false);
        $params['dataid'] = $dataid;
        $DB->delete_records_select($store->table(), "dataid = :dataid AND set_id $insql", $params);
    }

    /**
     * Whether the stored rows are exactly the projection of a normalised payload.
     *
     * Rows are compared as decoded arrays with sorted keys, because PostgreSQL JSONB does not
     * keep the key order or spacing of the JSON it was given.
     *
     * @param array $payload Normalised payload ({@see payload::normalise()}).
     * @param \stdClass[] $records Stored rows (set_id, data) of one canonical record.
     * @return bool
     */
    public static function is_current(array $payload, array $records): bool {
        // Every set of a normalised payload is an array that encodes to JSON, so sync() writes one row per set
        // ({@see payload::normalise()}); a count mismatch therefore always means stale rows.
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
     * Rebuild the rows of every canonical record of one field whose rows no longer match the payload.
     *
     * Walks the payloads and the rows of the field in two ordered recordsets, so each run costs
     * two queries per field plus one {@see self::reproject_record()} transaction per stale record.
     *
     * @param source $source The adapter.
     * @param int $fieldid
     * @param schema|null $schema The field's schema when the caller has it; null reads it from the source.
     * @return int Number of canonical records whose rows were rewritten.
     */
    public static function reproject_field(source $source, int $fieldid, ?schema $schema = null): int {
        global $DB;

        $schema = $schema ?? $source->schema($fieldid);
        if ($schema === null) {
            return 0;
        }

        $datars = $source->canonical_recordset($fieldid);
        $rowrs = $DB->get_recordset(
            $source->store()->table(),
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

            $payload = payload::normalise($datarecord->data, $schema);
            if (!self::is_current($payload, $records)) {
                $stale[] = [$dataid, $schema];
            }
        }
        $datars->close();
        $rowrs->close();

        return self::write_stale($source, $stale);
    }

    /**
     * Rewrite the rows of each stale record, one transaction per record.
     *
     * @param source $source The adapter.
     * @param array $stale List of [dataid, schema].
     * @return int Number of records rewritten.
     */
    public static function write_stale(source $source, array $stale): int {
        $written = 0;
        foreach ($stale as [$dataid, $schema]) {
            if (self::reproject_record($source, $dataid, $schema)) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * Rebuild the rows of one canonical record from its payload as it is now.
     *
     * The staleness check that selected the record ran on a snapshot taken before this
     * transaction. A save may land in between, so the record is re-read inside the
     * transaction and the payload and the staleness are decided again from that read: a
     * projection written from the snapshot would overwrite the newer save's rows.
     *
     * Internal API: called by the reprojection paths and by their tests.
     *
     * @param source $source The adapter.
     * @param int $dataid The canonical record's id.
     * @param schema $schema Column schema of the field.
     * @return bool Whether rows were rewritten; false when the record is gone or already current.
     */
    public static function reproject_record(source $source, int $dataid, schema $schema): bool {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        $datarecord = $source->canonical($dataid);
        if (!$datarecord) {
            $transaction->allow_commit();
            return false;
        }

        $payload = payload::normalise($datarecord->data, $schema);
        $records = $DB->get_records(
            $source->store()->table(),
            ['dataid' => $dataid],
            'set_id ASC',
            'id, dataid, set_id, data'
        );
        if (self::is_current($payload, $records)) {
            $transaction->allow_commit();
            return false;
        }

        self::sync($source, $dataid, (int)$datarecord->fieldid, (int)$datarecord->ownerid, $payload);
        $transaction->allow_commit();

        return true;
    }

    /**
     * Insert or update one set row, preserving timecreated on update.
     *
     * PostgreSQL upserts with ON CONFLICT on the unique (dataid, set_id) index; other families
     * select then write. A schema or semantic change must land in both branches.
     *
     * @param store $store
     * @param int $dataid
     * @param int $fieldid
     * @param int $ownerid
     * @param int $setid
     * @param string $jsonset
     * @param int $now
     * @return void
     */
    private static function upsert_set_row(
        store $store,
        int $dataid,
        int $fieldid,
        int $ownerid,
        int $setid,
        string $jsonset,
        int $now
    ): void {
        global $DB;

        $table = $store->table();
        $owner = $store->owner_column();
        if (!sql::is_postgres($DB)) {
            $existing = $DB->get_record(
                $table,
                ['dataid' => $dataid, 'set_id' => $setid],
                'id',
                IGNORE_MISSING
            );

            if ($existing) {
                $DB->update_record($table, (object) [
                    'id' => (int)$existing->id,
                    'fieldid' => $fieldid,
                    $owner => $ownerid,
                    'data' => $jsonset,
                    'timemodified' => $now,
                ]);
                return;
            }

            $DB->insert_record($table, (object) [
                'dataid' => $dataid,
                'fieldid' => $fieldid,
                $owner => $ownerid,
                'set_id' => $setid,
                'data' => $jsonset,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            return;
        }

        $sql = "INSERT INTO {{$table}}
                    (dataid, fieldid, {$owner}, set_id, data, timecreated, timemodified)
                VALUES
                    (:dataid, :fieldid, :ownerid, :setid, :data, :timecreated, :timemodified)
                ON CONFLICT (dataid, set_id)
                DO UPDATE SET
                    fieldid = EXCLUDED.fieldid,
                    {$owner} = EXCLUDED.{$owner},
                    data = EXCLUDED.data,
                    timemodified = EXCLUDED.timemodified";

        $DB->execute($sql, [
            'dataid' => $dataid,
            'fieldid' => $fieldid,
            'ownerid' => $ownerid,
            'setid' => $setid,
            'data' => $jsonset,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }
}
