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
 * Tests for the per-set projection comparison.
 *
 * Metadata stays in docblock annotations: the plugin supports Moodle 4.5, whose moodle-cs
 * cannot read PHPUnit attributes.
 *
 * @package    profilefield_repeatable
 * @covers     \profilefield_repeatable\local\projection
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class projection_test extends \advanced_testcase {
    /**
     * Build one stored row.
     *
     * @param int $setid
     * @param string $data
     * @return \stdClass
     */
    private function row(int $setid, string $data): \stdClass {
        return (object) ['set_id' => (string)$setid, 'data' => $data];
    }

    /**
     * Rows mirror a payload whatever their key order or spacing.
     */
    public function test_is_current_ignores_key_order_and_spacing(): void {
        $payload = ['@@ID#1' => ['name' => 'Alice', 'role' => 'Manager'], '@@ID#2' => ['name' => 'Bob', 'role' => '']];

        $this->assertTrue(projection::is_current($payload, [
            $this->row(1, '{"role": "Manager", "name": "Alice"}'),
            $this->row(2, '{"name":"Bob","role":""}'),
        ]));
        $this->assertTrue(projection::is_current([], []));
    }

    /**
     * Any difference in count, set numbering or values makes the rows stale.
     */
    public function test_is_current_detects_every_difference(): void {
        $payload = ['@@ID#1' => ['name' => 'Alice', 'role' => 'Manager'], '@@ID#2' => ['name' => 'Bob', 'role' => '']];
        $alice = $this->row(1, '{"name":"Alice","role":"Manager"}');

        $this->assertFalse(projection::is_current($payload, [$alice]), 'Missing set');
        $this->assertFalse(projection::is_current([], [$alice]), 'Rows left after the payload emptied');
        $this->assertFalse(projection::is_current($payload, [$alice, $this->row(3, '{"name":"Bob","role":""}')]), 'Set gap');
        $this->assertFalse(projection::is_current($payload, [$alice, $this->row(2, '{"name":"Bob","role":"x"}')]), 'Value');
        $this->assertFalse(projection::is_current($payload, [$alice, $this->row(2, '{"name":"Bob"}')]), 'Missing key');
        $this->assertFalse(projection::is_current($payload, [$alice, $this->row(2, 'not json')]), 'Corrupt row');
        // Numeric-looking strings must not compare equal by value.
        $this->assertFalse(projection::is_current(
            ['@@ID#1' => ['name' => '01', 'role' => '']],
            [$this->row(1, '{"name":"1","role":""}')]
        ), 'Numeric string');
    }

    /**
     * Create a repeatable field and a user whose payload is stored without any projection rows.
     *
     * @param string $json Stored payload.
     * @return array [dataid, userid, fieldid]
     */
    private function user_with_payload(string $json): array {
        global $DB;

        $field = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'repeatable',
            'name' => 'Record',
            'shortname' => 'record',
            'defaultdata' => '{}',
            'param1' => "name\nrole",
        ]);
        $userid = (int)$this->getDataGenerator()->create_user()->id;
        $dataid = (int)$DB->insert_record('user_info_data', (object) [
            'userid' => $userid,
            'fieldid' => $field->id,
            'data' => $json,
            'dataformat' => 0,
        ]);

        return [$dataid, $userid, (int)$field->id];
    }

    /**
     * The record is re-read inside the transaction, so a save that landed after the staleness
     * snapshot is projected and the snapshot's payload never overwrites it.
     *
     * The race itself cannot be interleaved from a single PHP process, so the test builds its
     * outcome: rows are stale against the older payload, the stored payload then changes, and the
     * per-record writer, which takes no payload from its caller, must write the newer one.
     */
    public function test_reproject_record_writes_the_payload_as_stored_now(): void {
        global $DB;

        $this->resetAfterTest();
        $subitems = ['name', 'role'];
        [$dataid, $userid, $fieldid] = $this->user_with_payload('{"@@ID#1":{"name":"Old","role":""}}');
        $this->assertTrue(projection::reproject_record($dataid, $subitems));

        // The newer save lands after the snapshot that found the rows stale.
        $DB->set_field('user_info_data', 'data', '{"@@ID#1":{"name":"New","role":"Boss"},"@@ID#2":{"name":"Two","role":""}}', [
            'id' => $dataid,
        ]);
        $this->assertTrue(projection::reproject_record($dataid, $subitems));

        $rows = $DB->get_records('profilefield_repeatable_data', ['dataid' => $dataid], 'set_id ASC');
        // Decoded: PostgreSQL JSONB does not keep the spacing of the JSON it was given.
        $this->assertSame(
            [['name' => 'New', 'role' => 'Boss'], ['name' => 'Two', 'role' => '']],
            array_map(fn($row) => json_decode((string)$row->data, true), array_values($rows))
        );
        $this->assertSame($userid, (int)reset($rows)->userid);
        $this->assertSame($fieldid, (int)reset($rows)->fieldid);
    }

    /**
     * A record whose rows already match its stored payload is skipped, and a vanished record is not an error.
     */
    public function test_reproject_record_skips_current_and_missing_records(): void {
        global $DB;

        $this->resetAfterTest();
        $subitems = ['name', 'role'];
        [$dataid] = $this->user_with_payload('{"@@ID#1":{"name":"Same","role":""}}');

        $this->assertTrue(projection::reproject_record($dataid, $subitems));
        $DB->set_field('profilefield_repeatable_data', 'timemodified', 1, ['dataid' => $dataid]);

        $this->assertFalse(projection::reproject_record($dataid, $subitems), 'Already current');
        $this->assertEquals(1, $DB->get_field('profilefield_repeatable_data', 'timemodified', ['dataid' => $dataid]));
        $this->assertFalse(projection::reproject_record($dataid + 1000, $subitems), 'Missing record');
    }

    /**
     * A vanished record is skipped before anything is written, even when rows of it remain.
     */
    public function test_reproject_record_leaves_orphan_rows_of_a_missing_record(): void {
        global $DB;

        $this->resetAfterTest();
        $subitems = ['name', 'role'];
        [$dataid] = $this->user_with_payload('{"@@ID#1":{"name":"Same","role":""}}');
        $this->assertTrue(projection::reproject_record($dataid, $subitems));
        $before = $DB->get_records('profilefield_repeatable_data', ['dataid' => $dataid], 'id ASC');
        $this->assertCount(1, $before);

        // The record vanishes but its projection rows stay behind as orphans.
        $DB->delete_records('user_info_data', ['id' => $dataid]);

        $this->assertFalse(projection::reproject_record($dataid, $subitems));
        $this->assertEquals($before, $DB->get_records('profilefield_repeatable_data', ['dataid' => $dataid], 'id ASC'));
    }

    /**
     * Rows of a vanished record are stepped over without re-reading every later record.
     *
     * The outcome is the same either way (reproject_record() re-checks inside its transaction), so
     * the guard is held by cost: the reads of a run with an orphan group must equal those of a run
     * without one. Both runs happen on the same database, because a recordset counts as a different
     * number of reads on PostgreSQL and MariaDB.
     */
    public function test_reproject_field_skips_orphan_rows_without_rereading_records(): void {
        global $DB;

        $this->resetAfterTest();
        [$firstid, , $fieldid] = $this->user_with_payload('{"@@ID#1":{"name":"One","role":""}}');
        $secondid = (int)$DB->insert_record('user_info_data', (object) [
            'userid' => (int)$this->getDataGenerator()->create_user()->id,
            'fieldid' => $fieldid,
            'data' => '{"@@ID#1":{"name":"Two","role":""}}',
            'dataformat' => 0,
        ]);
        $field = $DB->get_record('user_info_field', ['id' => $fieldid], '*', MUST_EXIST);
        $this->assertSame(2, projection::reproject_field($field));
        $this->assertTrue($DB->record_exists('profilefield_repeatable_data', ['dataid' => $secondid]));

        // Control: every projection is current.
        $before = $DB->perf_get_reads();
        $this->assertSame(0, projection::reproject_field($field));
        $baseline = $DB->perf_get_reads() - $before;

        // The first record vanishes and leaves its rows behind.
        $DB->delete_records('user_info_data', ['id' => $firstid]);
        $this->assertTrue($DB->record_exists('profilefield_repeatable_data', ['dataid' => $firstid]));

        $before = $DB->perf_get_reads();
        $this->assertSame(0, projection::reproject_field($field));
        $this->assertSame($baseline, $DB->perf_get_reads() - $before, 'Orphan rows must not make later records stale.');
    }
}
