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

defined('MOODLE_INTERNAL') || die();

/**
 * Reconcile dynamic PostgreSQL indexes for configured repeatable sub-items.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine (anderson@blaine.com.br)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconcile_indexes extends adhoc_task {
    /** Maximum number of indexed sub-items per field. */
    private const MAX_INDEXED_SUBITEMS = 3;

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
     * Unlike {@see schedule_for_field()}, this runs synchronously and does not
     * require the field record to still exist in the database.
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

        $tablename = $DB->get_prefix() . 'profilefield_repeatable_data';
        $indexes = $DB->get_fieldset_sql(
            "SELECT indexname FROM pg_indexes
              WHERE schemaname = ANY(current_schemas(false))
                AND tablename = :tablename
                AND indexname LIKE :pattern",
            ['tablename' => $tablename, 'pattern' => 'pfrd_f' . $fieldid . '_%']
        );

        foreach ($indexes as $indexname) {
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
     * @return array<string, string>
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
        global $DB;

        $sql = "SELECT indexname
                  FROM pg_indexes
                 WHERE schemaname = ANY(current_schemas(false))
                   AND tablename = :tablename
                   AND indexname LIKE :pattern";

        $tablename = $DB->get_prefix() . 'profilefield_repeatable_data';
        $pattern = 'pfrd_f' . $fieldid . '_%';

        return $DB->get_fieldset_sql($sql, [
            'tablename' => $tablename,
            'pattern' => $pattern,
        ]);
    }

    /**
     * Create one expression index for a flagged sub-item.
     *
     * Note: CREATE INDEX CONCURRENTLY cannot run inside a transaction.
     * Adhoc tasks are executed outside explicit transactions by the Moodle cron runner.
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

        try {
            $DB->execute($sql);
        } catch (\Throwable $e) {
            debugging('Could not create repeatable index ' . $indexname . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
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

        // Map each index to its field ID (pfrd_f{id}_{hash}).
        $indexfieldmap = [];
        foreach ($allindexes as $indexname) {
            if (preg_match('/^pfrd_f(\d+)_/', $indexname, $matches)) {
                $indexfieldmap[$indexname] = (int)$matches[1];
            }
        }

        if (empty($indexfieldmap)) {
            return;
        }

        // Determine which field IDs still exist as repeatable fields.
        $uniqueids = array_values(array_unique(array_values($indexfieldmap)));
        [$insql, $params] = $DB->get_in_or_equal($uniqueids, SQL_PARAMS_NAMED, 'fid');
        $activeids = $DB->get_fieldset_sql(
            "SELECT id FROM {user_info_field} WHERE id $insql AND datatype = :datatype",
            $params + ['datatype' => 'repeatable']
        );
        $activeset = array_flip($activeids);

        // Drop indexes belonging to deleted fields.
        foreach ($indexfieldmap as $indexname => $fid) {
            if (!isset($activeset[$fid])) {
                $this->drop_index_concurrently($indexname);
            }
        }
    }

    /**
     * Quote SQL literal.
     *
     * @param string $value
     * @return string
     */
    private function quote_literal(string $value): string {
        if (strpos($value, "\0") !== false) {
            throw new \coding_exception('Invalid SQL literal provided.');
        }

        return "'" . str_replace("'", "''", $value) . "'";
    }
}
