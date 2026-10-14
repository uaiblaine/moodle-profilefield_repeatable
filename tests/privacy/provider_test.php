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
 * @covers     \profilefield_repeatable\privacy\set_exporter
 * @copyright  2026 Anderson Blaine
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
            'param1' => $this->getDataGenerator()->get_plugin_generator('profilefield_repeatable')->schema_json([
                ['key' => 'name', 'label' => 'Full <b>name</b>'],
                'Role',
            ]),
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
     * The plugin counts as compliant with the privacy API.
     *
     * Core's own compliance test sweeps every component but is not in the plugin's testsuite,
     * which is all moodle-plugin-ci runs, so the check is repeated here: a metadata provider
     * without a request data provider fails it ({@see \core_privacy\manager::component_is_compliant()}).
     *
     * @return void
     */
    public function test_the_component_is_compliant(): void {
        $this->assertTrue((new \core_privacy\manager())->component_is_compliant('profilefield_repeatable'));
    }

    /**
     * Exporting must keep the data of every repeatable field, one subcontext per field, sets under labels.
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
        $set = get_string('repeatableset', 'profilefield_repeatable', 1);
        $this->assertSame([$set => ['Full name' => 'Alice', 'Role' => 'Manager']], $dataa->sets);
        $this->assertSame([$set => ['Full name' => 'Bob', 'Role' => 'Teacher']], $datab->sets);
        $this->assertObjectNotHasProperty('data', $dataa);
    }

    /**
     * A payload still keyed by version 1 labels exports the same sets.
     */
    public function test_export_user_data_reads_label_keyed_payloads(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $fieldid = $this->create_field_with_data('repeatable_priv_v1', 'Repeatable V1', (int)$user->id, '{}');
        $DB->set_field('user_info_data', 'data', '{"@@ID#1":{"Old name":"Alice","role":"Lead"}}', [
            'userid' => $user->id,
            'fieldid' => $fieldid,
        ]);
        set_config(
            \profilefield_repeatable\local\field_schema::legacy_config_name($fieldid),
            json_encode(['name' => 'Old name']),
            'profilefield_repeatable'
        );

        $context = \context_user::instance($user->id);
        provider::export_user_data(new approved_contextlist($user, 'profilefield_repeatable', [$context->id]));

        $data = writer::with_context($context)->get_data([get_string('pluginname', 'profilefield_repeatable'), 'Repeatable V1']);
        $set = get_string('repeatableset', 'profilefield_repeatable', 1);
        $this->assertSame([$set => ['Full name' => 'Alice', 'Role' => 'Lead']], $data->sets);
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
    /**
     * Export and deletion match the user to the context whatever the id types.
     *
     * A context loaded from the database carries a string instanceid, one created in the same
     * request an int, and a user record either; a strict comparison would skip the user.
     */
    public function test_export_and_delete_compare_ids_as_integers(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();
        $this->create_field_with_data(
            'repeatable_priv_types',
            'Repeatable Types',
            (int)$user->id,
            '{"@@ID#1":{"name":"Alice","role":"Manager"}}'
        );

        $context = \context_user::instance($user->id);
        $requester = clone $user;
        $requester->id = is_int($context->instanceid) ? (string)$user->id : (int)$user->id;

        provider::export_user_data(new approved_contextlist($requester, 'profilefield_repeatable', [$context->id]));
        $data = writer::with_context($context)->get_data([
            get_string('pluginname', 'profilefield_repeatable'),
            'Repeatable Types',
        ]);
        $this->assertSame('Alice', $data->sets[get_string('repeatableset', 'profilefield_repeatable', 1)]['Full name'] ?? null);

        provider::delete_data_for_user(new approved_contextlist($requester, 'profilefield_repeatable', [$context->id]));
        $this->assertFalse($DB->record_exists('profilefield_repeatable_data', ['userid' => $user->id]));
    }

    /**
     * Names that format to the same text stay apart, and values under no current column are exported raw.
     */
    public function test_export_keeps_colliding_names_and_leftover_values(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $field = $this->getDataGenerator()->get_plugin_generator('profilefield_repeatable')->create_field([
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'name_tagged', 'label' => '<b>Name</b>'],
        ], ['shortname' => 'collide', 'name' => 'Collide']);
        $DB->insert_record('user_info_data', (object)[
            'userid' => $user->id,
            'fieldid' => $field->id,
            'data' => '{"@@ID#1":{"name":"one","name_tagged":"two","removed":"three","Name":"four"}}',
            'dataformat' => 0,
        ]);

        $context = \context_user::instance($user->id);
        provider::export_user_data(new approved_contextlist($user, 'profilefield_repeatable', [$context->id]));

        $data = writer::with_context($context)->get_data([get_string('pluginname', 'profilefield_repeatable'), 'Collide']);
        $this->assertSame([get_string('repeatableset', 'profilefield_repeatable', 1) => [
            'Name' => 'one',
            'Name (name_tagged)' => 'two',
            'removed' => 'three',
            'Name (Name)' => 'four',
        ]], $data->sets);
    }

    /**
     * Export one user's pattern column: a CPF stored raw, then text the mask does not fit.
     *
     * @return array The exported sets.
     */
    private function export_pattern_values(): array {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $field = $this->getDataGenerator()->get_plugin_generator('profilefield_repeatable')->create_field([
            ['key' => 'cpf', 'label' => 'CPF', 'mode' => 'pattern', 'pattern' => ['preset' => 'cpf', 'storeformat' => 'raw']],
        ], ['shortname' => 'docs', 'name' => 'Docs']);
        $DB->insert_record('user_info_data', (object)[
            'userid' => $user->id,
            'fieldid' => $field->id,
            'data' => '{"@@ID#1":{"cpf":"52998224725"},"@@ID#2":{"cpf":"<b>1</b>"}}',
            'dataformat' => 0,
        ]);

        $context = \context_user::instance($user->id);
        provider::export_user_data(new approved_contextlist($user, 'profilefield_repeatable', [$context->id]));
        return writer::with_context($context)->get_data([get_string('pluginname', 'profilefield_repeatable'), 'Docs'])->sets;
    }

    /**
     * A pattern value is exported as it is shown, as plain text.
     */
    public function test_export_formats_pattern_values(): void {
        if (!\profilefield_repeatable\local\pattern_columns::available()) {
            $this->markTestSkipped('customfield_pattern with its public API is not installed (Moodle 5.1 or later).');
        }
        $this->resetAfterTest();

        $this->assertSame([
            get_string('repeatableset', 'profilefield_repeatable', 1) => ['CPF' => '529.982.247-25'],
            get_string('repeatableset', 'profilefield_repeatable', 2) => ['CPF' => '<b>1</b>'],
        ], $this->export_pattern_values());
    }

    /**
     * Without customfield_pattern a pattern value is exported as stored.
     */
    public function test_export_without_the_plugin_keeps_pattern_values(): void {
        if (\profilefield_repeatable\local\pattern_columns::available()) {
            $this->markTestSkipped('customfield_pattern is installed; its absence is tested on Moodle 4.5 and 5.0.');
        }
        $this->resetAfterTest();

        $this->assertSame([
            get_string('repeatableset', 'profilefield_repeatable', 1) => ['CPF' => '52998224725'],
            get_string('repeatableset', 'profilefield_repeatable', 2) => ['CPF' => '<b>1</b>'],
        ], $this->export_pattern_values());
    }
}
