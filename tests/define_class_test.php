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
require_once($CFG->dirroot . '/user/profile/field/repeatable/define.class.php');
require_once($CFG->dirroot . '/user/profile/field/repeatable/field.class.php');
require_once($CFG->libdir . '/formslib.php');

/**
 * Tests for profile_define_repeatable.
 *
 * The schema rules themselves are tested in {@see \profilefield_repeatable\local\schema_test}; this
 * covers how the definition form reads, reports and saves them.
 *
 * @package    profilefield_repeatable
 * @covers     \profile_define_repeatable
 * @covers     \profilefield_repeatable\form\column_editor
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class define_class_test extends \advanced_testcase {
    /**
     * The data generator of this plugin.
     *
     * @return \profilefield_repeatable_generator
     */
    private function generator(): \profilefield_repeatable_generator {
        return $this->getDataGenerator()->get_plugin_generator('profilefield_repeatable');
    }

    /**
     * Every broken rule is reported under param1, one per line.
     */
    public function test_define_validate_specific_lists_every_error_under_param1(): void {
        $this->resetAfterTest();
        $define = new \profile_define_repeatable();

        $errors = $define->define_validate_specific((object)[
            'param1' => json_encode(['version' => 2, 'columns' => [
                ['key' => 'name', 'label' => 'Name', 'mode' => 'free'],
                ['key' => 'Bad', 'label' => 'Role', 'mode' => 'select'],
            ]]),
        ], []);

        $this->assertSame([
            'param1' => implode('<br>', [
                get_string('errorkeyinvalid', 'profilefield_repeatable', 'Bad'),
                get_string('errormodeinvalid', 'profilefield_repeatable', 'Role'),
                get_string('errortitlecount', 'profilefield_repeatable'),
            ]),
        ], $errors);
    }

    /**
     * Version 1 text and an empty value are not a schema.
     */
    public function test_define_validate_specific_rejects_text_that_is_not_a_schema(): void {
        $this->resetAfterTest();
        $define = new \profile_define_repeatable();
        $expected = ['param1' => get_string('errorschemainvalid', 'profilefield_repeatable')];

        $this->assertSame($expected, $define->define_validate_specific((object)['param1' => "name\nrole"], []));
        $this->assertSame($expected, $define->define_validate_specific(['param1' => ''], []));
    }

    /**
     * A valid schema passes.
     */
    public function test_define_validate_specific_accepts_a_valid_schema(): void {
        $this->resetAfterTest();
        $define = new \profile_define_repeatable();

        $this->assertSame([], $define->define_validate_specific((object)[
            'param1' => $this->generator()->schema_json(['Name', ['label' => 'Role', 'required' => true]]),
        ], []));
    }

    /**
     * With the companion installed, a dictionary column must name an existing domain.
     */
    public function test_define_validate_specific_checks_domains_with_the_companion(): void {
        if (!class_exists('\local_dictionaries\resolver')) {
            $this->markTestSkipped('local_dictionaries is not installed.');
        }
        $this->resetAfterTest();
        $this->getDataGenerator()->get_plugin_generator('local_dictionaries')
            ->create_domain(['shortname' => 'diretorias', 'name' => 'Diretorias']);
        $define = new \profile_define_repeatable();
        $schema = fn(string $domain): string => $this->generator()->schema_json([
            ['label' => 'Diretoria', 'mode' => 'dictionary', 'domain' => $domain],
        ]);

        $this->assertSame([], $define->define_validate_specific((object)['param1' => $schema('diretorias')], []));
        $this->assertSame(
            ['param1' => get_string('errordomainmissing', 'profilefield_repeatable', 'escolas')],
            $define->define_validate_specific((object)['param1' => $schema('escolas')], [])
        );
    }

    /**
     * Saving rewrites param1 canonically and clears the version 1 parameters.
     */
    public function test_define_save_preprocess_canonicalises_and_clears_v1_params(): void {
        $define = new \profile_define_repeatable();

        $processed = $define->define_save_preprocess((object)[
            'param1' => "{\n  \"version\": 2,\n  \"columns\": [{\"label\": \" Name \", \"key\": \"name\", \"title\": 1,"
                . " \"mode\": \"free\", \"domain\": \"x\"}]\n}",
            'param2' => 'Name',
            'param3' => 'Name|names',
            'param4' => 'Name',
            'defaultdata' => 'x',
        ]);

        $this->assertSame('{"version":2,"columns":[{"key":"name","label":"Name","mode":"free","title":true}]}', $processed->param1);
        $this->assertSame('', $processed->param2);
        $this->assertSame('', $processed->param3);
        $this->assertSame('', $processed->param4);
        $this->assertSame('{}', $processed->defaultdata);
    }

    /**
     * Build the definition form elements the way core's profile field form does, then run define_after_data().
     *
     * @param int $fieldid
     * @param string $param1
     * @return \MoodleQuickForm
     */
    private function after_data(int $fieldid, string $param1): \MoodleQuickForm {
        $mform = new \MoodleQuickForm('profilefield', 'post', '');
        $mform->addElement('hidden', 'id', $fieldid);
        $mform->setType('id', PARAM_INT);
        $define = new \profile_define_repeatable();
        $define->define_form_specific($mform);
        $mform->setDefault('param1', $param1);
        $define->define_after_data($mform);
        return $mform;
    }

    /**
     * The editor learns which keys hold values, from the stored field, and how many users have data.
     */
    public function test_define_after_data_locks_the_keys_holding_values(): void {
        global $PAGE;

        $this->resetAfterTest();
        $fielddata = $this->generator()->create_field(['Name', 'Role', 'Notes']);
        $user1 = $this->getDataGenerator()->create_user();
        $user2 = $this->getDataGenerator()->create_user();
        foreach ([$user1, $user2] as $user) {
            $field = new \profile_field_repeatable((int)$fielddata->id, (int)$user->id);
            $field->edit_save_data((object)[
                'id' => $user->id,
                $field->inputname => json_encode(['@@ID#1' => ['name' => 'Ann', 'role' => '', 'notes' => ' ']]),
            ]);
        }
        $field = new \profile_field_repeatable((int)$fielddata->id, (int)$user1->id);
        $field->edit_save_data((object)[
            'id' => $user1->id,
            $field->inputname => json_encode(['@@ID#1' => ['name' => '', 'role' => 'Lead']]),
        ]);

        // The submitted schema renames a key; the lock still follows what is stored.
        $submitted = $this->generator()->schema_json([['key' => 'renamed', 'label' => 'Name']]);
        $element = $this->after_data((int)$fielddata->id, $submitted)->getElement('param1');

        $this->assertSame('["name","role"]', $element->getAttribute('data-lockedkeys'));
        $this->assertEquals(2, $element->getAttribute('data-users'));
        $this->assertStringContainsString("\n", $element->getValue(), 'Pretty-printed for editing without JavaScript');
        $this->assertSame(['renamed'], local\schema::from_json($element->getValue())->keys());

        $amdjscode = (new \ReflectionProperty($PAGE->requires, 'amdjscode'))->getValue($PAGE->requires);
        $this->assertStringContainsString('profilefield_repeatable/columneditor', implode("\n", $amdjscode));
    }

    /**
     * A new field has nothing locked, and text the editor cannot read is left as typed.
     */
    public function test_define_after_data_for_a_new_field(): void {
        $this->resetAfterTest();

        $element = $this->after_data(0, 'not json')->getElement('param1');

        $this->assertSame('[]', $element->getAttribute('data-lockedkeys'));
        $this->assertEquals(0, $element->getAttribute('data-users'));
        $this->assertSame('not json', $element->getValue());
        $companion = class_exists('\local_dictionaries\resolver') ? '1' : '0';
        $this->assertSame($companion, (string)$element->getAttribute('data-companion'));
    }

    /**
     * With the companion installed, the editor receives its domains with their plain names.
     */
    public function test_define_after_data_lists_the_companion_domains(): void {
        if (!method_exists('\local_dictionaries\api', 'list_domains')) {
            $this->markTestSkipped('local_dictionaries with api::list_domains() is not installed.');
        }
        $this->resetAfterTest();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_dictionaries');
        $generator->create_domain(['shortname' => 'uf', 'name' => 'States & <b>regions</b>']);
        $generator->create_item(['domain' => 'uf', 'code' => 'SP', 'label' => 'Sao Paulo']);

        $element = $this->after_data(0, '')->getElement('param1');

        $domains = json_decode($element->getAttribute('data-domains'), true);
        $uf = array_values(array_filter($domains, static fn(array $domain): bool => $domain['shortname'] === 'uf'));
        $this->assertCount(1, $uf);
        $this->assertSame('States & regions', $uf[0]['name']);
        $this->assertSame(1, $uf[0]['itemcount']);
        $this->assertSame('1', (string)$element->getAttribute('data-companion'));
    }

    /**
     * A field with stored values in a payload that has no projection row.
     *
     * Written straight into {user_info_data}, as core's profile_save_custom_fields() does, so only
     * the canonical payload knows about the value.
     *
     * @return \stdClass The field record.
     */
    private function field_with_unprojected_value(): \stdClass {
        global $DB;

        $fielddata = $this->generator()->create_field(['Name', 'Role', 'Notes']);
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('user_info_data', (object)[
            'userid' => $user->id,
            'fieldid' => $fielddata->id,
            'data' => '{"@@ID#1":{"name":"Ann","role":" ","notes":null}}',
            'dataformat' => 0,
        ]);
        $this->assertFalse($DB->record_exists('profilefield_repeatable_data', ['fieldid' => $fielddata->id]));
        return $fielddata;
    }

    /**
     * The server refuses a schema that removes or re-keys a column holding values, read from the payloads.
     */
    public function test_define_validate_specific_rejects_dropping_a_key_in_use(): void {
        global $DB;

        $this->resetAfterTest();
        $fielddata = $this->field_with_unprojected_value();
        $define = new \profile_define_repeatable();
        $validate = fn(array $columns, int $id): array => $define->define_validate_specific((object)[
            'id' => $id,
            'param1' => $this->generator()->schema_json($columns),
        ], []);
        $locked = ['param1' => get_string('errorkeylocked', 'profilefield_repeatable', 'Name')];

        $this->assertSame($locked, $validate(['Role', 'Notes'], (int)$fielddata->id), 'Removed');
        $this->assertSame($locked, $validate([['key' => 'full_name', 'label' => 'Name'], 'Role'], (int)$fielddata->id), 'Re-keyed');
        // Controls: renaming the label, or removing columns that hold no value, is allowed.
        $this->assertSame([], $validate([['key' => 'name', 'label' => 'Full name']], (int)$fielddata->id));
        // A new field, or an id that is not a repeatable field, locks nothing.
        $this->assertSame([], $validate(['Role'], 0));
        $other = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text', 'shortname' => 'other', 'name' => 'Other', 'param1' => $fielddata->param1,
        ]);
        $DB->insert_record('user_info_data', (object)[
            'userid' => $this->getDataGenerator()->create_user()->id,
            'fieldid' => $other->id,
            'data' => '{"@@ID#1":{"name":"Not a repeatable value"}}',
            'dataformat' => 0,
        ]);
        $this->assertSame([], $validate(['Role'], (int)$other->id));
    }

    /**
     * The editor locks the same keys as the server, from the payloads, without a projection row.
     */
    public function test_define_after_data_locks_keys_of_unprojected_payloads(): void {
        $this->resetAfterTest();
        $fielddata = $this->field_with_unprojected_value();

        $element = $this->after_data((int)$fielddata->id, $fielddata->param1)->getElement('param1');

        $this->assertSame('["name"]', $element->getAttribute('data-lockedkeys'));
        $this->assertEquals(1, $element->getAttribute('data-users'));
    }

    /**
     * A field still on the version 1 parameters opens as its version 2 schema, and saving it converts it.
     */
    public function test_v1_field_opens_as_its_schema_and_converts_on_save(): void {
        global $DB;

        $this->resetAfterTest();
        if (class_exists('\local_dictionaries\resolver')) {
            // With the companion installed, validation requires the mapped domain to exist.
            $this->getDataGenerator()->get_plugin_generator('local_dictionaries')
                ->create_domain(['shortname' => 'roles', 'name' => 'Roles']);
        }
        $fielddata = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'repeatable', 'shortname' => 'oldfield', 'name' => 'Old field',
            'param1' => "Full name\nRole", 'param3' => 'Role|roles', 'param4' => 'Role',
        ]);
        $user = $this->getDataGenerator()->create_user();
        $dataid = $DB->insert_record('user_info_data', (object)[
            'userid' => $user->id, 'fieldid' => $fielddata->id, 'dataformat' => 0,
            'data' => '{"@@ID#1":{"Full name":"Ann","Role":"lead"}}',
        ]);

        $element = $this->after_data((int)$fielddata->id, $fielddata->param1)->getElement('param1');
        $schema = local\schema::from_json($element->getValue());
        $this->assertSame(['full_name', 'role'], $schema->keys());
        $this->assertSame(['role' => 'roles'], $schema->domain_map());
        $this->assertSame(['role'], $schema->subline_keys());
        $define = new \profile_define_repeatable();
        $this->assertSame([], $define->define_validate_specific((object)[
            'id' => $fielddata->id,
            'param1' => $element->getValue(),
        ], []));

        $record = $DB->get_record('user_info_field', ['id' => $fielddata->id]);
        $record->param1 = $element->getValue();
        $define->define_save($record);

        $this->assertSame(
            ['full_name' => 'Full name', 'role' => 'Role'],
            json_decode(get_config('profilefield_repeatable', local\field_schema::legacy_config_name((int)$fielddata->id)), true)
        );
        $field = new \profile_field_repeatable((int)$fielddata->id, (int)$user->id);
        $loaded = new \stdClass();
        $field->edit_load_user_data($loaded);
        $this->assertSame('{"@@ID#1":{"full_name":"Ann","role":"lead"}}', $loaded->{$field->inputname});

        $this->expectOutputRegex('/re-keyed 1 payload/');
        $this->runAdhocTasks(task\rekey_payloads::class);
        $this->assertSame(
            '{"@@ID#1":{"full_name":"Ann","role":"lead"}}',
            $DB->get_field('user_info_data', 'data', ['id' => $dataid])
        );
        $this->assertFalse(get_config('profilefield_repeatable', local\field_schema::legacy_config_name((int)$fielddata->id)));
    }
}
