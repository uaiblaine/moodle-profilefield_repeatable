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

use profilefield_repeatable\helper;

/**
 * Tests for index ownership in the reconcile_indexes adhoc task.
 *
 * Metadata stays in docblock annotations: the plugin supports Moodle 4.5, whose moodle-cs
 * cannot read PHPUnit attributes.
 *
 * @package    profilefield_repeatable
 * @covers     \profilefield_repeatable\task\reconcile_indexes
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reconcile_indexes_test extends \advanced_testcase {
    /** @var string A hash of the length build_index_name() produces. */
    private const HASH = '0123456789abcdef';

    /**
     * Only names the task builds map to a field, and only to their own field.
     */
    public function test_owned_index_fieldid(): void {
        $this->assertSame(1, reconcile_indexes::owned_index_fieldid('pfrd_f1_' . self::HASH));
        $this->assertSame(12, reconcile_indexes::owned_index_fieldid('pfrd_f12_' . self::HASH));
        $this->assertNull(reconcile_indexes::owned_index_fieldid('pfrd_f1_custom'));
        $this->assertNull(reconcile_indexes::owned_index_fieldid('pfrd_f1_' . self::HASH . '0'));
        $this->assertNull(reconcile_indexes::owned_index_fieldid('pfrd_f1_0123456789ABCDEF'));
        $this->assertNull(reconcile_indexes::owned_index_fieldid('pfrd_fx_' . self::HASH));
        $this->assertNull(reconcile_indexes::owned_index_fieldid('xpfrd_f1_' . self::HASH));
    }

    /**
     * Field 1 does not claim the indexes of fields 12 or 100, which a bare LIKE matches.
     */
    public function test_filter_field_indexes_excludes_digit_prefixed_fields(): void {
        $names = [
            'pfrd_f1_' . self::HASH,
            'pfrd_f12_' . self::HASH,
            'pfrd_f100_' . self::HASH,
            'pfrd_f1_custom',
        ];

        $this->assertSame(['pfrd_f1_' . self::HASH], reconcile_indexes::filter_field_indexes($names, 1));
        $this->assertSame(['pfrd_f12_' . self::HASH], reconcile_indexes::filter_field_indexes($names, 12));
    }

    /**
     * The desired indexes are the indexed columns, named after the field and the key.
     *
     * Runs on every database family: the desired map is built from the field record alone. A stored
     * key the schema rules reject (here with ":") is dropped when the schema is read, so it never
     * reaches the DDL, whose placeholder scan would misread it.
     */
    public function test_desired_indexes_follow_the_indexed_keys(): void {
        $this->resetAfterTest();

        $columns = [
            ['key' => 'plain', 'label' => 'Plain', 'mode' => 'free', 'title' => true],
            ['key' => 'start_date', 'label' => 'Start:date', 'mode' => 'free', 'indexed' => true],
            ['key' => 'name', 'label' => 'Name?', 'mode' => 'free', 'indexed' => true],
            ['key' => 'bad:key', 'label' => 'Bad', 'mode' => 'free', 'indexed' => true],
        ];
        $field = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'repeatable',
            'name' => 'Repeatable placeholders',
            'shortname' => 'repeatable_placeholders',
            'param1' => json_encode(['version' => 2, 'columns' => $columns]),
        ]);

        $task = new reconcile_indexes();
        $map = new \ReflectionMethod(reconcile_indexes::class, 'get_desired_index_map');
        $desired = $map->invoke($task, (int)$field->id);
        $this->resetDebugging();

        $prefix = 'pfrd_f' . $field->id . '_';
        $this->assertSame([
            $prefix . substr(sha1('start_date'), 0, 16) => 'start_date',
            $prefix . substr(sha1('name'), 0, 16) => 'name',
        ], $desired);
    }

    /**
     * Create an index with a task-owned name on the storage table.
     *
     * @param string $indexname
     * @return void
     */
    private function create_index(string $indexname): void {
        global $DB;

        $table = helper::quote_identifier($DB->get_prefix() . 'profilefield_repeatable_data');
        $DB->execute('CREATE INDEX ' . helper::quote_identifier($indexname) . " ON {$table} (fieldid)");
    }

    /**
     * Index names the task dropped or tried to drop since the last debugging reset.
     *
     * PHPUnit may run each test inside a transaction, where DROP INDEX CONCURRENTLY fails; the
     * task then reports the attempt through debugging(), which is what is collected here.
     *
     * @param string[] $candidates Names that existed before the task ran.
     * @return string[]
     */
    private function dropped_or_attempted(array $candidates): array {
        global $DB;

        $tablename = $DB->get_prefix() . 'profilefield_repeatable_data';
        $messages = implode("\n", array_map(static fn($debug) => $debug->message, $this->getDebuggingMessages()));
        $this->resetDebugging();

        return array_values(array_filter(
            $candidates,
            static fn(string $name): bool => !helper::postgres_index_exists($DB, $tablename, $name)
                || strpos($messages, 'Could not drop repeatable index ' . $name . ':') !== false
        ));
    }

    /**
     * On PostgreSQL, a field lists and drops only its own indexes.
     *
     * Two indexes share the field's prefix without being its own: one of a field whose id starts
     * with the same digits (caught by the escaped LIKE) and one with a name the task never builds
     * (caught by the exact name check). The orphan sweep applies the same name check.
     */
    public function test_field_lists_and_drops_only_its_own_indexes(): void {
        global $DB;

        if ($DB->get_dbfamily() !== 'postgres') {
            $this->markTestSkipped('Expression indexes exist only on PostgreSQL.');
        }
        $this->resetAfterTest();

        $field = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'repeatable',
            'name' => 'Repeatable index',
            'shortname' => 'repeatable_index',
            'param1' => $this->getDataGenerator()->get_plugin_generator('profilefield_repeatable')->schema_json(['name', 'role']),
        ]);
        $fieldid = (int)$field->id;
        // A second repeatable field whose id starts with the first one's digits.
        $otherid = $fieldid * 10 + 1;
        $DB->insert_record_raw('user_info_field', [
            'id' => $otherid,
            'shortname' => 'repeatable_index_other',
            'name' => 'Repeatable index other',
            'datatype' => 'repeatable',
            'categoryid' => $field->categoryid,
            'param1' => $field->param1,
        ], false, false, true);

        $own = 'pfrd_f' . $fieldid . '_' . self::HASH;
        $other = 'pfrd_f' . $otherid . '_' . self::HASH;
        $foreign = 'pfrd_f' . $fieldid . '_custom';
        // Of a field that no longer exists: the sweep drops the owned one, never the foreign one.
        $orphan = 'pfrd_f' . ($otherid + 1) . '_' . self::HASH;
        $foreignorphan = 'pfrd_f' . ($otherid + 1) . '_custom';
        $all = [$own, $other, $foreign, $orphan, $foreignorphan];
        try {
            foreach ($all as $name) {
                $this->create_index($name);
            }

            $query = new \ReflectionMethod(reconcile_indexes::class, 'query_field_index_candidates');
            $candidates = $query->invoke(null, $fieldid);
            $this->assertEqualsCanonicalizing([$own, $foreign], $candidates, 'The LIKE prefix must be literal.');

            $list = new \ReflectionMethod(reconcile_indexes::class, 'list_field_indexes');
            $this->assertSame([$own], $list->invoke(null, $fieldid));

            // The field indexes nothing, so reconciling drops its own index (the control).
            $task = new reconcile_indexes();
            $task->set_custom_data(['fieldid' => $fieldid]);
            $task->execute();
            $this->assertSame([$own, $orphan], $this->dropped_or_attempted($all));

            reconcile_indexes::drop_all_for_field($fieldid);
            $dropped = $this->dropped_or_attempted($all);
            $this->assertContains($own, $dropped);
            $this->assertNotContains($other, $dropped);
            $this->assertNotContains($foreign, $dropped);
        } finally {
            foreach ($all as $name) {
                $DB->execute('DROP INDEX IF EXISTS ' . helper::quote_identifier($name));
            }
        }
    }
}
