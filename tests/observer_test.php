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
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->dirroot . '/user/profile/definelib.php');
require_once($CFG->dirroot . '/user/profile/field/repeatable/field.class.php');

/**
 * Tests for event observers cleaning up storage rows.
 *
 * @package    profilefield_repeatable
 * @covers     \profilefield_repeatable\observer
 * @copyright  2026 Anderson Blaine (anderson@blaine.com.br)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class observer_test extends \advanced_testcase {
    /**
     * Create one repeatable profile field and return its definition record id.
     *
     * @param string $shortname
     * @return int
     */
    private function create_repeatable_field(string $shortname): int {
        $fielddata = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'repeatable',
            'name' => 'Repeatable ' . $shortname,
            'shortname' => $shortname,
            'required' => 0,
            'forceunique' => 0,
            'defaultdata' => '{}',
            'param1' => "name\nrole",
        ]);

        return (int)$fielddata->id;
    }

    /**
     * Save one payload for a user through the field save path.
     *
     * @param int $fieldid
     * @param int $userid
     */
    private function save_field_data(int $fieldid, int $userid): void {
        global $DB;

        $fieldrecord = $DB->get_record('user_info_field', ['id' => $fieldid], '*', MUST_EXIST);
        $field = new \profile_field_repeatable(0, 0, $fieldrecord);

        $field->edit_save_data((object) [
            'id' => $userid,
            $field->inputname => '{"@@ID#1":{"name":"Alice","role":"Manager"}}',
        ]);
    }

    /**
     * Deleting a user must remove that user's storage rows and keep other users' rows.
     */
    public function test_user_deleted_removes_storage_rows(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $fieldid = $this->create_repeatable_field('repeatable_obs_user');
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();

        $this->save_field_data($fieldid, (int)$usera->id);
        $this->save_field_data($fieldid, (int)$userb->id);

        $this->assertSame(1, $DB->count_records('profilefield_repeatable_data', ['userid' => $usera->id]));
        $this->assertSame(1, $DB->count_records('profilefield_repeatable_data', ['userid' => $userb->id]));

        delete_user($usera);

        $this->assertSame(0, $DB->count_records('profilefield_repeatable_data', ['userid' => $usera->id]));
        $this->assertSame(1, $DB->count_records('profilefield_repeatable_data', ['userid' => $userb->id]));
    }

    /**
     * Deleting a repeatable field must remove that field's storage rows and keep other fields' rows.
     */
    public function test_user_info_field_deleted_removes_storage_rows(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $fielda = $this->create_repeatable_field('repeatable_obs_fielda');
        $fieldb = $this->create_repeatable_field('repeatable_obs_fieldb');
        $user = $this->getDataGenerator()->create_user();

        $this->save_field_data($fielda, (int)$user->id);
        $this->save_field_data($fieldb, (int)$user->id);

        $this->assertSame(1, $DB->count_records('profilefield_repeatable_data', ['fieldid' => $fielda]));
        $this->assertSame(1, $DB->count_records('profilefield_repeatable_data', ['fieldid' => $fieldb]));

        profile_delete_field($fielda);

        $this->assertSame(0, $DB->count_records('profilefield_repeatable_data', ['fieldid' => $fielda]));
        $this->assertSame(1, $DB->count_records('profilefield_repeatable_data', ['fieldid' => $fieldb]));
    }

    /**
     * Field deletion must queue index reconciliation on PostgreSQL for index cleanup.
     */
    public function test_user_info_field_deleted_queues_reconcile_on_postgres(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $fieldid = $this->create_repeatable_field('repeatable_obs_queue');
        $user = $this->getDataGenerator()->create_user();
        $this->save_field_data($fieldid, (int)$user->id);

        $before = $this->count_reconcile_tasks_for_field($fieldid);

        profile_delete_field($fieldid);

        $after = $this->count_reconcile_tasks_for_field($fieldid);

        if ($DB->get_dbfamily() === 'postgres') {
            $this->assertGreaterThan($before, $after, 'Expected a reconcile_indexes task queued for the deleted field.');
        } else {
            $this->assertSame($before, $after);
        }
    }

    /**
     * Count queued reconcile_indexes adhoc tasks targeting one field.
     *
     * @param int $fieldid
     * @return int
     */
    private function count_reconcile_tasks_for_field(int $fieldid): int {
        $count = 0;
        foreach (\core\task\manager::get_adhoc_tasks(task\reconcile_indexes::class) as $task) {
            $customdata = $task->get_custom_data();
            if ((int)($customdata->fieldid ?? 0) === $fieldid) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Deleting a non-repeatable field must not touch repeatable storage rows.
     */
    public function test_other_datatype_field_deleted_keeps_storage_rows(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $repeatableid = $this->create_repeatable_field('repeatable_obs_keep');
        $textfield = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'name' => 'Plain text field',
            'shortname' => 'repeatable_obs_text',
        ]);
        $user = $this->getDataGenerator()->create_user();
        $this->save_field_data($repeatableid, (int)$user->id);

        profile_delete_field((int)$textfield->id);

        $this->assertSame(1, $DB->count_records('profilefield_repeatable_data', ['fieldid' => $repeatableid]));
    }
}
