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
 * Tests for the event observers: projection cleanup on deletion and reprojection on user events.
 *
 * @package    profilefield_repeatable
 * @covers     \profilefield_repeatable\observer
 * @covers     \profilefield_repeatable\local\projection
 * @copyright  2026 Anderson Blaine
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
            'param1' => $this->getDataGenerator()->get_plugin_generator('profilefield_repeatable')->schema_json(['name', 'role']),
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
     * Event cases: the user event class whose observer reprojects the stored payload.
     *
     * Core triggers user_updated (user_create_user() user_created) before auth_ldap's
     * profile_save_custom_fields() writes the payload, inside the delegated transaction its sync opens.
     *
     * @return array
     */
    public static function user_event_provider(): array {
        return [
            'user_updated' => [\core\event\user_updated::class],
            'user_created' => [\core\event\user_created::class],
        ];
    }

    /**
     * A user event rebuilds the projection from a payload core wrote directly.
     *
     * @dataProvider user_event_provider
     * @param string $eventclass
     */
    public function test_user_event_reprojects_direct_write(string $eventclass): void {
        $this->resetAfterTest();
        // The observers are non-internal: core holds them while a transaction is open, and PostgreSQL runs
        // every test inside one.
        $this->preventResetByRollback();
        $this->setAdminUser();

        $this->create_repeatable_field('repeatable_obs_sync');
        $user = $this->getDataGenerator()->create_user();
        $userid = (int)$user->id;
        profile_save_data((object) [
            'id' => $userid,
            'profile_field_repeatable_obs_sync' => '{"@@ID#1":{"name":"Alice","role":"Manager"}}',
        ]);

        profile_save_custom_fields($userid, [
            'repeatable_obs_sync' => '{"@@ID#1":{"name":"Bob","role":"Teacher"},"@@ID#2":{"name":"Carol","role":""}}',
        ]);
        // Precondition: the direct write left the projection behind.
        $this->assertSame([1 => ['name' => 'Alice', 'role' => 'Manager']], $this->projected_sets($userid));

        $eventclass::create_from_userid($userid)->trigger();
        $this->assertSame([
            1 => ['name' => 'Bob', 'role' => 'Teacher'],
            2 => ['name' => 'Carol', 'role' => ''],
        ], $this->projected_sets($userid));

        // An emptied payload empties the projection.
        profile_save_custom_fields($userid, ['repeatable_obs_sync' => '']);
        $eventclass::create_from_userid($userid)->trigger();
        $this->assertSame([], $this->projected_sets($userid));
    }

    /**
     * Inside a transaction the event fires before the payload is written and still sees the new one.
     *
     * This is auth_ldap's order: user_updated (or user_created) first, profile_save_custom_fields()
     * after, both inside one delegated transaction. The observers are non-internal, so core holds
     * them until the commit.
     *
     * @dataProvider user_event_provider
     * @param string $eventclass
     */
    public function test_user_event_inside_a_transaction_sees_the_payload_written_after_it(string $eventclass): void {
        global $DB;

        $this->resetAfterTest();
        // The observers are non-internal: core holds them while a transaction is open, and PostgreSQL runs
        // every test inside one.
        $this->preventResetByRollback();
        $this->setAdminUser();

        $this->create_repeatable_field('repeatable_obs_ldap');
        $user = $this->getDataGenerator()->create_user();
        $userid = (int)$user->id;
        profile_save_data((object) [
            'id' => $userid,
            'profile_field_repeatable_obs_ldap' => '{"@@ID#1":{"name":"Alice","role":"Manager"}}',
        ]);

        $transaction = $DB->start_delegated_transaction();
        $eventclass::create_from_userid($userid)->trigger();
        profile_save_custom_fields($userid, [
            'repeatable_obs_ldap' => '{"@@ID#1":{"name":"Bob","role":"Teacher"}}',
        ]);
        // The observer has not run yet: the projection is still the old one.
        $this->assertSame([1 => ['name' => 'Alice', 'role' => 'Manager']], $this->projected_sets($userid));

        $transaction->allow_commit();
        $this->assertSame([1 => ['name' => 'Bob', 'role' => 'Teacher']], $this->projected_sets($userid));
    }

    /**
     * A user event leaves a projection that still matches the payload untouched.
     */
    public function test_user_updated_keeps_current_projection(): void {
        global $DB;

        $this->resetAfterTest();
        // The observers are non-internal: core holds them while a transaction is open, and PostgreSQL runs
        // every test inside one.
        $this->preventResetByRollback();
        $this->setAdminUser();

        $fieldid = $this->create_repeatable_field('repeatable_obs_current');
        $user = $this->getDataGenerator()->create_user();
        $this->save_field_data($fieldid, (int)$user->id);
        $DB->set_field('profilefield_repeatable_data', 'timemodified', 1, ['userid' => $user->id]);

        \core\event\user_updated::create_from_userid($user->id)->trigger();
        $this->assertSame(1, (int)$DB->get_field('profilefield_repeatable_data', 'timemodified', ['userid' => $user->id]));

        // Control: the same event does rewrite a stale row.
        $DB->set_field('profilefield_repeatable_data', 'data', '{"name":"Old","role":"Manager"}', ['userid' => $user->id]);
        \core\event\user_updated::create_from_userid($user->id)->trigger();
        $this->assertGreaterThan(1, (int)$DB->get_field('profilefield_repeatable_data', 'timemodified', ['userid' => $user->id]));
        $this->assertSame([1 => ['name' => 'Alice', 'role' => 'Manager']], $this->projected_sets((int)$user->id));
    }
}
