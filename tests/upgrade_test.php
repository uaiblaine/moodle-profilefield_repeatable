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

namespace profilefield_repeatable;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/user/profile/field/repeatable/db/upgradelib.php');

/**
 * Tests for the 2026042001 upgrade step, which removes the r1 PostgreSQL index layer.
 *
 * @package    profilefield_repeatable
 * @covers     ::profilefield_repeatable_drop_index_layer
 * @covers     ::profilefield_repeatable_is_index_layer_index
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upgrade_test extends \advanced_testcase {
    /** @var string Class name of the removed task, as core's task manager stores it. */
    private const REMOVED_TASK = '\\profilefield_repeatable\\task\\reconcile_indexes';

    /** @var string A task of the plugin that still exists. */
    private const KEPT_TASK = '\\profilefield_repeatable\\task\\rekey_payloads';

    /**
     * Only the two name shapes the r1 layer built are its own.
     */
    public function test_is_index_layer_index(): void {
        $hash = '0123456789abcdef';
        $this->assertTrue(profilefield_repeatable_is_index_layer_index('pfrd_f1_' . $hash));
        $this->assertTrue(profilefield_repeatable_is_index_layer_index('pfrd_f10_' . $hash));
        $this->assertTrue(profilefield_repeatable_is_index_layer_index('pfrd_0123abcd_gin_ix'));

        $this->assertFalse(profilefield_repeatable_is_index_layer_index('pfrd_f1_custom'));
        $this->assertFalse(profilefield_repeatable_is_index_layer_index('pfrd_f1_' . $hash . '_old'));
        $this->assertFalse(profilefield_repeatable_is_index_layer_index('old_pfrd_f1_' . $hash));
        $this->assertFalse(profilefield_repeatable_is_index_layer_index('pfrd_f_' . $hash));
        $this->assertFalse(profilefield_repeatable_is_index_layer_index('pfrd_0123abcd_gin_ix_old'));
        $this->assertFalse(profilefield_repeatable_is_index_layer_index('mdl_profrepedata_fieuse_ix'));
    }

    /**
     * On every database family the queued tasks of the removed class go, and no other task does.
     */
    public function test_removes_queued_tasks_of_the_removed_class(): void {
        global $DB;

        $this->resetAfterTest();
        $removed = $this->queue_raw_task(self::REMOVED_TASK);
        $kept = $this->queue_raw_task(self::KEPT_TASK);

        profilefield_repeatable_drop_index_layer($DB);

        $this->assertFalse($DB->record_exists('task_adhoc', ['id' => $removed]));
        $this->assertTrue($DB->record_exists('task_adhoc', ['id' => $kept]));
    }

    /**
     * On PostgreSQL the r1 state is undone: the layer's indexes go, the column is text again, data is intact.
     *
     * Controls: three indexes on the table whose names only resemble the layer's, one with a layer
     * name on another table, and the indexes install.xml declares, all of which must survive.
     */
    public function test_postgres_r1_state_is_undone(): void {
        global $DB;

        if ($DB->get_dbfamily() !== 'postgres') {
            $this->markTestSkipped('The index layer existed only on PostgreSQL.');
        }
        $this->resetAfterTest();

        $table = $DB->get_prefix() . 'profilefield_repeatable_data';
        $quote = static fn(string $identifier): string => \profilefield_repeatable_quote_identifier($identifier);
        $quotedtable = $quote($table);
        $declared = $this->table_indexes($table);
        $sets = [
            1 => ['language' => 'pt', 'level' => 'C2'],
            2 => ['language' => 'es', 'level' => "O'Neil \"quoted\""],
            3 => ['language' => 'en'],
        ];
        $now = time();
        foreach ($sets as $setid => $set) {
            $DB->insert_record('profilefield_repeatable_data', (object)[
                'dataid' => 100 + $setid,
                'fieldid' => $setid === 3 ? 10 : 1,
                'userid' => 2,
                'set_id' => $setid,
                'data' => json_encode($set),
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        $layer = [
            'pfrd_f1_' . substr(sha1('language'), 0, 16),
            'pfrd_f1_' . substr(sha1('level'), 0, 16),
            'pfrd_f10_' . substr(sha1('language'), 0, 16),
            'pfrd_' . substr(sha1($table), 0, 8) . '_gin_ix',
        ];
        $controls = ['pfrd_f1_custom', 'pfrd_f1_0123456789abcdef_old', 'old_pfrd_f1_0123456789abcdef'];
        $elsewhere = 'pfrd_f1_0123456789abcdef';
        $othertable = $DB->get_prefix() . 'user_info_data';

        try {
            // The r1 state, as its install hook and reconcile_indexes task built it.
            $DB->execute(
                "ALTER TABLE {$quotedtable} ALTER COLUMN data TYPE jsonb USING COALESCE(NULLIF(data, ''), '{}')::jsonb"
            );
            $DB->reset_caches(['profilefield_repeatable_data']);
            $DB->execute(
                'CREATE INDEX ' . $quote($layer[3]) . " ON {$quotedtable} USING gin (data jsonb_path_ops)"
            );
            $expressions = [[$layer[0], 1, 'language'], [$layer[1], 1, 'level'], [$layer[2], 10, 'language']];
            foreach ($expressions as [$name, $fieldid, $key]) {
                $DB->execute(
                    'CREATE INDEX ' . $quote($name) . " ON {$quotedtable} (fieldid, ((data ->> '{$key}')))"
                        . " WHERE fieldid = {$fieldid} AND jsonb_exists(data, '{$key}')"
                );
            }
            foreach ($controls as $name) {
                $DB->execute('CREATE INDEX ' . $quote($name) . " ON {$quotedtable} (userid)");
            }
            $DB->execute('CREATE INDEX ' . $quote($elsewhere) . ' ON '
                . $quote($othertable) . ' (fieldid)');
            $this->assertSame('jsonb', $this->column_type($table));
            $this->assertEqualsCanonicalizing(array_merge($declared, $layer, $controls), $this->table_indexes($table));
            $removed = $this->queue_raw_task(self::REMOVED_TASK);

            profilefield_repeatable_drop_index_layer($DB);

            $this->assertEqualsCanonicalizing(array_merge($declared, $controls), $this->table_indexes($table));
            $this->assertContains($elsewhere, $this->table_indexes($othertable));
            $this->assertSame('text', $this->column_type($table));
            $stored = $DB->get_records_menu('profilefield_repeatable_data', null, 'set_id', 'set_id, data');
            $this->assertSame(array_keys($sets), array_map('intval', array_keys($stored)));
            foreach ($sets as $setid => $set) {
                $this->assertEquals($set, json_decode($stored[$setid], true));
            }
            $this->assertFalse($DB->record_exists('task_adhoc', ['id' => $removed]));

            // A second run finds nothing left to do.
            profilefield_repeatable_drop_index_layer($DB);
            $this->assertEqualsCanonicalizing(array_merge($declared, $controls), $this->table_indexes($table));
        } finally {
            foreach (array_merge($layer, $controls, [$elsewhere]) as $name) {
                $DB->execute('DROP INDEX IF EXISTS ' . $quote($name));
            }
            if ($this->column_type($table) !== 'text') {
                $DB->execute("ALTER TABLE {$quotedtable} ALTER COLUMN data TYPE text USING data::text");
            }
            $DB->reset_caches(['profilefield_repeatable_data']);
        }
    }

    /**
     * Queue an adhoc task row directly: the removed class can no longer be instantiated.
     *
     * @param string $classname
     * @return int The task_adhoc id.
     */
    private function queue_raw_task(string $classname): int {
        global $DB;

        return (int)$DB->insert_record('task_adhoc', (object)[
            'component' => 'profilefield_repeatable',
            'classname' => $classname,
            'nextruntime' => time(),
            'customdata' => json_encode(['fieldid' => 1]),
            'timecreated' => time(),
        ]);
    }

    /**
     * Names of the indexes on one table of the current schema.
     *
     * @param string $tablename Prefixed table name.
     * @return string[]
     */
    private function table_indexes(string $tablename): array {
        global $DB;

        return $DB->get_fieldset_sql(
            "SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = :tablename",
            ['tablename' => $tablename]
        );
    }

    /**
     * Type of the projection's data column.
     *
     * @param string $tablename Prefixed table name.
     * @return string
     */
    private function column_type(string $tablename): string {
        global $DB;

        return (string)$DB->get_field_sql(
            "SELECT data_type
               FROM information_schema.columns
              WHERE table_schema = current_schema()
                AND table_name = :tablename
                AND column_name = 'data'",
            ['tablename' => $tablename]
        );
    }
}
