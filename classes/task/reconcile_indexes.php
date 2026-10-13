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

namespace profilefield_repeatable\task;

use core\task\adhoc_task;
use core\task\manager;
use profilefield_repeatable\helper;

/**
 * Reconcile dynamic PostgreSQL indexes for configured repeatable sub-items.
 *
 * Creates and drops one expression index per indexed sub-item (param2) of a field. It is an adhoc
 * task, outside any transaction, because CREATE/DROP INDEX CONCURRENTLY cannot run inside one and
 * may take long on a large table. Every run also drops the indexes of any field that no longer
 * exists ({@see self::sweep_orphaned_indexes()}).
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine (anderson@blaine.com.br)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconcile_indexes extends adhoc_task {
    /** Maximum number of indexed sub-items per field; keep in step with profile_define_repeatable. */
    private const MAX_INDEXED_SUBITEMS = 3;

    /** Name of every index this task owns: field id, then the hash from {@see build_index_name()}. */
    private const INDEX_NAME_PATTERN = '/^pfrd_f(\d+)_[0-9a-f]{16}$/';

    #[\Override]
    public function get_name() {
        return get_string('taskreconcileindexes', 'profilefield_repeatable');
    }

    #[\Override]
    public function execute() {
        global $DB;

        if ($DB->get_dbfamily() !== 'postgres') {
            return;
        }

        if (!$DB->get_manager()->table_exists(new \xmldb_table('profilefield_repeatable_data'))) {
            return;
        }

        $customdata = $this->get_custom_data();
        $fieldid = clean_param($customdata->fieldid ?? 0, PARAM_INT);
        if (empty($fieldid)) {
            return;
        }

        $desired = $this->get_desired_index_map((int)$fieldid);
        $existing = $this->get_existing_indexes((int)$fieldid);

        foreach ($existing as $indexname) {
            if (isset($desired[$indexname])) {
                continue;
            }
            $this->drop_index_concurrently($indexname);
        }

        foreach ($desired as $indexname => $subitem) {
            if (in_array($indexname, $existing, true)) {
                continue;
            }
            $this->create_index_concurrently((int)$fieldid, $subitem, $indexname);
        }

        $this->sweep_orphaned_indexes();
    }

    /**
     * Schedule task for one profile field.
     *
     * Queued with checkforexisting, so repeated saves of a field before cron runs queue one task.
     *
     * @param int $fieldid
     */
    public static function schedule_for_field(int $fieldid): void {
        if ($fieldid <= 0) {
            return;
        }

        $task = new static();
        $task->set_custom_data(['fieldid' => $fieldid]);
        manager::queue_adhoc_task($task, true);
    }

    /**
     * Immediately drop all managed indexes for a field.
     *
     * Runs synchronously, unlike {@see schedule_for_field()}. DROP INDEX CONCURRENTLY fails inside
     * a transaction; a failed drop is reported through debugging() and left to the orphan sweep.
     *
     * @param int $fieldid
     */
    public static function drop_all_for_field(int $fieldid): void {
        global $DB;

        if ($fieldid <= 0 || $DB->get_dbfamily() !== 'postgres') {
            return;
        }

        if (!$DB->get_manager()->table_exists(new \xmldb_table('profilefield_repeatable_data'))) {
            return;
        }

        foreach (self::list_field_indexes($fieldid) as $indexname) {
            $quoted = helper::quote_identifier($indexname);
            try {
                $DB->execute("DROP INDEX CONCURRENTLY IF EXISTS $quoted");
            } catch (\Throwable $e) {
                debugging('Could not drop repeatable index ' . $indexname . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Resolve desired indexes for a field from param2.
     *
     * @param int $fieldid
     * @return array<string, string> Index name => canonical sub-item; empty once the field is gone.
     */
    private function get_desired_index_map(int $fieldid): array {
        global $DB;

        $field = $DB->get_record('user_info_field', [
            'id' => $fieldid,
            'datatype' => 'repeatable',
        ], 'id, param1, param2');

        if (!$field) {
            return [];
        }

        $subitems = helper::parse_subitems((string)$field->param1);
        if (empty($subitems)) {
            return [];
        }

        $canonicalmap = [];
        foreach ($subitems as $subitem) {
            $canonicalmap[\core_text::strtolower($subitem)] = $subitem;
        }

        $indexedsubitems = [];
        $seen = [];
        $lines = preg_split('/\R/u', (string)$field->param2) ?: [];
        foreach ($lines as $line) {
            $candidate = trim($line);
            if ($candidate === '') {
                continue;
            }

            $normalised = \core_text::strtolower($candidate);
            if (isset($seen[$normalised]) || !isset($canonicalmap[$normalised])) {
                continue;
            }

            $seen[$normalised] = true;
            $indexedsubitems[] = $canonicalmap[$normalised];

            if (count($indexedsubitems) >= self::MAX_INDEXED_SUBITEMS) {
                break;
            }
        }

        $desired = [];
        foreach ($indexedsubitems as $subitem) {
            if (!helper::is_indexable_subitem($subitem)) {
                // Its literal would be read as a query placeholder, see helper::is_indexable_subitem().
                debugging(
                    'profilefield_repeatable: not indexing sub-item "' . $subitem . '" of field ' . $fieldid .
                    ': it contains ":", "?" or "$"',
                    DEBUG_DEVELOPER
                );
                continue;
            }
            $indexname = $this->build_index_name($fieldid, $subitem);
            $desired[$indexname] = $subitem;
        }

        return $desired;
    }

    /**
     * List plugin-managed indexes currently present for the field.
     *
     * @param int $fieldid
     * @return string[]
     */
    private function get_existing_indexes(int $fieldid): array {
        return self::list_field_indexes($fieldid);
    }

    /**
     * List the indexes this task owns for one field.
     *
     * The LIKE prefix is escaped so "_" is literal, and every name is then checked whole:
     * a bare "pfrd_f1_%" also matches "pfrd_f12_...", the indexes of fields 10-19 and 100-199.
     *
     * @param int $fieldid
     * @return string[]
     */
    private static function list_field_indexes(int $fieldid): array {
        return self::filter_field_indexes(self::query_field_index_candidates($fieldid), $fieldid);
    }

    /**
     * Indexes on the storage table whose name starts with the field's literal prefix.
     *
     * @param int $fieldid
     * @return string[]
     */
    private static function query_field_index_candidates(int $fieldid): array {
        global $DB;

        $like = $DB->sql_like('indexname', ':pattern', true, true, false, '\\');
        return $DB->get_fieldset_sql(
            "SELECT indexname
               FROM pg_indexes
              WHERE schemaname = ANY(current_schemas(false))
                AND tablename = :tablename
                AND {$like}",
            [
                'tablename' => $DB->get_prefix() . 'profilefield_repeatable_data',
                'pattern' => $DB->sql_like_escape('pfrd_f' . $fieldid . '_', '\\') . '%',
            ]
        );
    }

    /**
     * Keep only the index names this task owns for one field.
     *
     * @param string[] $indexnames
     * @param int $fieldid
     * @return string[]
     */
    public static function filter_field_indexes(array $indexnames, int $fieldid): array {
        return array_values(array_filter(
            $indexnames,
            static fn(string $indexname): bool => self::owned_index_fieldid($indexname) === $fieldid
        ));
    }

    /**
     * The field id an index belongs to, when this task created it.
     *
     * @param string $indexname
     * @return int|null Null for any name {@see build_index_name()} cannot produce.
     */
    public static function owned_index_fieldid(string $indexname): ?int {
        if (!preg_match(self::INDEX_NAME_PATTERN, $indexname, $matches)) {
            return null;
        }

        return (int)$matches[1];
    }

    /**
     * Create one expression index for a flagged sub-item.
     *
     * CREATE INDEX CONCURRENTLY cannot run inside a transaction; the cron runner starts none
     * around an adhoc task.
     *
     * @param int $fieldid
     * @param string $subitem
     * @param string $indexname
     */
    private function create_index_concurrently(int $fieldid, string $subitem, string $indexname): void {
        global $DB;

        $quotedindex = helper::quote_identifier($indexname);
        $quotedtable = helper::quote_identifier($DB->get_prefix() . 'profilefield_repeatable_data');
        $literal = $this->quote_literal($subitem);
        $fieldid = (int)$fieldid;

        $sql = "CREATE INDEX CONCURRENTLY IF NOT EXISTS $quotedindex
                  ON $quotedtable (fieldid, ((data ->> $literal)))
               WHERE fieldid = $fieldid
                 AND jsonb_exists(data, $literal)";

        // The function jsonb_exists() stands in for the jsonb ? operator, which Moodle's DML
        // would count as a query placeholder.

        try {
            $DB->execute($sql);
        } catch (\Throwable $e) {
            debugging(
                'Could not create repeatable index ' . $indexname .
                ' for field ' . $fieldid . ' and subitem "' . $subitem . '": ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Drop one obsolete expression index.
     *
     * @param string $indexname
     */
    private function drop_index_concurrently(string $indexname): void {
        global $DB;

        $quotedindex = helper::quote_identifier($indexname);
        $sql = "DROP INDEX CONCURRENTLY IF EXISTS $quotedindex";

        try {
            $DB->execute($sql);
        } catch (\Throwable $e) {
            debugging('Could not drop repeatable index ' . $indexname . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Build deterministic index name using field id + sub-item hash.
     *
     * @param int $fieldid
     * @param string $subitem
     * @return string
     */
    private function build_index_name(int $fieldid, string $subitem): string {
        $hash = substr(sha1(\core_text::strtolower($subitem)), 0, 16);
        return 'pfrd_f' . $fieldid . '_' . $hash;
    }

    /**
     * Drop indexes whose owning field no longer exists as a repeatable profile field.
     *
     * This piggybacks on every reconciliation run so orphans left by field
     * deletion are eventually cleaned up.
     */
    private function sweep_orphaned_indexes(): void {
        global $DB;

        $tablename = $DB->get_prefix() . 'profilefield_repeatable_data';
        $allindexes = $DB->get_fieldset_sql(
            "SELECT indexname FROM pg_indexes
              WHERE schemaname = ANY(current_schemas(false))
                AND tablename = :tablename
                AND indexname LIKE :pattern",
            ['tablename' => $tablename, 'pattern' => 'pfrd_f%']
        );

        if (empty($allindexes)) {
            return;
        }

        $indexfieldmap = [];
        foreach ($allindexes as $indexname) {
            $owner = self::owned_index_fieldid($indexname);
            if ($owner !== null) {
                $indexfieldmap[$indexname] = $owner;
            }
        }

        if (empty($indexfieldmap)) {
            return;
        }

        $uniqueids = array_values(array_unique(array_values($indexfieldmap)));
        [$insql, $params] = $DB->get_in_or_equal($uniqueids, SQL_PARAMS_NAMED, 'fid');
        $activeids = $DB->get_fieldset_sql(
            "SELECT id FROM {user_info_field} WHERE id $insql AND datatype = :datatype",
            $params + ['datatype' => 'repeatable']
        );
        $activeset = array_flip($activeids);

        foreach ($indexfieldmap as $indexname => $fid) {
            if (!isset($activeset[$fid])) {
                $this->drop_index_concurrently($indexname);
            }
        }
    }

    /**
     * Quote SQL literal.
     *
     * For DDL, which takes no bound parameters; DML stays on placeholders.
     *
     * @param string $value
     * @return string
     * @throws \coding_exception When the value contains a NUL byte.
     */
    private function quote_literal(string $value): string {
        if (strpos($value, "\0") !== false) {
            throw new \coding_exception('Invalid SQL literal provided.');
        }

        return "'" . str_replace("'", "''", $value) . "'";
    }
}
