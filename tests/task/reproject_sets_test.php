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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->dirroot . '/user/profile/field/repeatable/field.class.php');

/**
 * Tests for the reproject_sets scheduled task.
 *
 * Metadata stays in docblock annotations: the plugin supports Moodle 4.5, whose moodle-cs
 * cannot read PHPUnit attributes.
 *
 * @package    profilefield_repeatable
 * @covers     \profilefield_repeatable\task\reproject_sets
 * @covers     \profilefield_repeatable\local\projection
 * @covers     \profilefield_repeatable\local\projection\projector
 * @covers     \profilefield_repeatable\local\source
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reproject_sets_test extends \advanced_testcase {
    /**
     * Decoded sets of the projection rows of one user, keyed by set_id.
     *
     * @param int $userid
     * @return array
     */
    private function projected_sets(int $userid): array {
        global $DB;

        $sets = [];
        foreach ($DB->get_records('profilefield_repeatable_data', ['userid' => $userid], 'set_id ASC') as $row) {
            $sets[(int)$row->set_id] = json_decode((string)$row->data, true);
        }

        return $sets;
    }

    /**
     * The task rebuilds stale and missing projections and leaves current ones alone.
     *
     * Covers the paths with no usable event: auth_db's sync writes through
     * profile_save_custom_fields() without triggering anything, and a restored user has a
     * payload but no rows.
     */
    public function test_execute_rebuilds_only_stale_projections(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $field = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'repeatable',
            'name' => 'Repeatable task',
            'shortname' => 'repeatable_task',
            'defaultdata' => '{}',
            'param1' => $this->getDataGenerator()->get_plugin_generator('profilefield_repeatable')->schema_json(['name', 'role']),
        ]);
        $current = (int)$this->getDataGenerator()->create_user()->id;
        $stale = (int)$this->getDataGenerator()->create_user()->id;
        $restored = (int)$this->getDataGenerator()->create_user()->id;

        foreach ([$current, $stale] as $userid) {
            profile_save_data((object) [
                'id' => $userid,
                'profile_field_repeatable_task' => '{"@@ID#1":{"name":"Alice","role":"Manager"}}',
            ]);
        }
        $DB->set_field('profilefield_repeatable_data', 'timemodified', 1, ['userid' => $current]);
        profile_save_custom_fields($stale, ['repeatable_task' => '{"@@ID#1":{"name":"Bob","role":"Teacher"}}']);
        $DB->insert_record('user_info_data', (object) [
            'userid' => $restored,
            'fieldid' => $field->id,
            'data' => '{"@@ID#1":{"name":"Carol","role":""}}',
            'dataformat' => 0,
        ]);
        // Rows of a vanished user_info_data record sort before the others and must be skipped.
        $DB->insert_record('profilefield_repeatable_data', (object) [
            'dataid' => 0,
            'fieldid' => $field->id,
            'userid' => $restored,
            'set_id' => 9,
            'data' => '{"name":"Orphan","role":""}',
            'timecreated' => 1,
            'timemodified' => 1,
        ]);

        $this->expectOutputRegex('/rebuilt 2 projection\(s\) of field repeatable_task/');
        (new reproject_sets())->execute();

        $this->assertSame([1 => ['name' => 'Bob', 'role' => 'Teacher']], $this->projected_sets($stale));
        $this->assertSame(
            [1 => ['name' => 'Carol', 'role' => ''], 9 => ['name' => 'Orphan', 'role' => '']],
            $this->projected_sets($restored)
        );
        $this->assertSame(1, (int)$DB->get_field('profilefield_repeatable_data', 'timemodified', ['userid' => $current]));
    }
}
