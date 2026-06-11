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

namespace profilefield_repeatable\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->dirroot . '/user/profile/field/repeatable/field.class.php');

/**
 * Tests for the privacy provider.
 *
 * @package    profilefield_repeatable
 * @covers     \profilefield_repeatable\privacy\provider
 * @copyright  2026 Anderson Blaine (anderson@blaine.com.br)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Create one repeatable field and save one payload for the user.
     *
     * @param string $shortname
     * @param string $name
     * @param int $userid
     * @param string $payload
     * @return int Field id.
     */
    private function create_field_with_data(string $shortname, string $name, int $userid, string $payload): int {
        global $DB;

        $fielddata = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'repeatable',
            'name' => $name,
            'shortname' => $shortname,
            'required' => 0,
            'forceunique' => 0,
            'defaultdata' => '{}',
            'param1' => "name\nrole",
        ]);

        $fieldrecord = $DB->get_record('user_info_field', ['id' => $fielddata->id], '*', MUST_EXIST);
        $field = new \profile_field_repeatable(0, 0, $fieldrecord);
        $field->edit_save_data((object) [
            'id' => $userid,
            $field->inputname => $payload,
        ]);

        return (int)$fielddata->id;
    }

    /**
     * Exporting must keep the data of every repeatable field, one subcontext per field.
     */
    public function test_export_user_data_keeps_all_repeatable_fields(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();
        $this->create_field_with_data(
            'repeatable_priv_a',
            'Repeatable A',
            (int)$user->id,
            '{"@@ID#1":{"name":"Alice","role":"Manager"}}'
        );
        $this->create_field_with_data(
            'repeatable_priv_b',
            'Repeatable B',
            (int)$user->id,
            '{"@@ID#1":{"name":"Bob","role":"Teacher"}}'
        );

        $context = \context_user::instance($user->id);
        $contextlist = provider::get_contexts_for_userid((int)$user->id);
        $this->assertContains((int)$context->id, array_map('intval', $contextlist->get_contextids()));

        $approved = new approved_contextlist($user, 'profilefield_repeatable', [$context->id]);
        provider::export_user_data($approved);

        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());

        $pluginname = get_string('pluginname', 'profilefield_repeatable');
        $dataa = $writer->get_data([$pluginname, 'Repeatable A']);
        $datab = $writer->get_data([$pluginname, 'Repeatable B']);

        $this->assertNotEmpty((array)$dataa, 'Field "Repeatable A" must be exported under its own subcontext.');
        $this->assertNotEmpty((array)$datab, 'Field "Repeatable B" must be exported under its own subcontext.');
        $this->assertStringContainsString('Alice', (string)$dataa->data);
        $this->assertStringContainsString('Bob', (string)$datab->data);
    }

    /**
     * Deleting data for one user must clean both tables and keep other users intact.
     */
    public function test_delete_data_for_user_cleans_both_tables(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $fieldid = $this->create_field_with_data(
            'repeatable_priv_del',
            'Repeatable Del',
            (int)$usera->id,
            '{"@@ID#1":{"name":"Alice","role":"Manager"}}'
        );

        $fieldrecord = $DB->get_record('user_info_field', ['id' => $fieldid], '*', MUST_EXIST);
        $field = new \profile_field_repeatable(0, 0, $fieldrecord);
        $field->edit_save_data((object) [
            'id' => (int)$userb->id,
            $field->inputname => '{"@@ID#1":{"name":"Bob","role":"Teacher"}}',
        ]);

        $contexta = \context_user::instance($usera->id);
        $approved = new approved_contextlist($usera, 'profilefield_repeatable', [$contexta->id]);
        provider::delete_data_for_user($approved);

        $this->assertSame(0, $DB->count_records('profilefield_repeatable_data', ['userid' => $usera->id]));
        $this->assertSame(0, $DB->count_records('user_info_data', ['userid' => $usera->id, 'fieldid' => $fieldid]));
        $this->assertSame(1, $DB->count_records('profilefield_repeatable_data', ['userid' => $userb->id]));
        $this->assertSame(1, $DB->count_records('user_info_data', ['userid' => $userb->id, 'fieldid' => $fieldid]));
    }

    /**
     * Userlist discovery and approved-userlist deletion must work on user contexts.
     */
    public function test_userlist_discovery_and_deletion(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();
        $this->create_field_with_data(
            'repeatable_priv_list',
            'Repeatable List',
            (int)$user->id,
            '{"@@ID#1":{"name":"Alice","role":"Manager"}}'
        );

        $context = \context_user::instance($user->id);
        $userlist = new userlist($context, 'profilefield_repeatable');
        provider::get_users_in_context($userlist);
        $this->assertContains((int)$user->id, array_map('intval', $userlist->get_userids()));

        $approved = new approved_userlist($context, 'profilefield_repeatable', [(int)$user->id]);
        provider::delete_data_for_users($approved);

        $this->assertSame(0, $DB->count_records('profilefield_repeatable_data', ['userid' => $user->id]));
    }
}
