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

use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\models\column;
use core_reportbuilder\tests\core_reportbuilder_testcase;
use profilefield_repeatable\reportbuilder\datasource\repeatable_sets;
use profilefield_repeatable\task\rekey_payloads;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->dirroot . '/user/profile/definelib.php');
require_once($CFG->dirroot . '/user/profile/field/repeatable/field.class.php');

/**
 * Re-keying the payloads of a field converted from the version 1 parameters.
 *
 * A converted field is set up the way {@see \profile_define_repeatable::define_save()} leaves
 * it: legacy labels in the plugin config, the version 2 schema in param1, payloads and
 * projection still keyed by label.
 *
 * Metadata stays in docblocks: the plugin supports Moodle 4.5, whose moodle-cs cannot read
 * PHPUnit attributes.
 *
 * @package    profilefield_repeatable
 * @category   test
 * @covers     \profilefield_repeatable\local\migration
 * @covers     \profilefield_repeatable\task\rekey_payloads
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class migration_test extends core_reportbuilder_testcase {
    /** @var string The version 1 payload of the first user. */
    private const ALICE = '{"@@ID#1":{"Língua":"pt","Level":"C2","Certificate number":"CEL-1"},'
        . '"@@ID#2":{"Língua":"es","Level":"","Certificate number":"DEL-2"}}';

    /**
     * A version 1 field: three labels, one indexed, two mapped, one in the subline.
     *
     * @param string $shortname
     * @return \stdClass
     */
    private function v1_field(string $shortname = 'langcerts'): \stdClass {
        return $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'repeatable',
            'name' => 'Language certificates',
            'shortname' => $shortname,
            'param1' => "Língua\nLevel\nCertificate number",
            'param2' => 'língua',
            'param3' => "Língua|iso639\nLevel|cefr",
            'param4' => 'Level',
        ]);
    }

    /**
     * Store a version 1 payload and its version 1 projection rows.
     *
     * @param \stdClass $field
     * @param int $userid
     * @param string $json
     * @return int The {user_info_data} id.
     */
    private function v1_payload(\stdClass $field, int $userid, string $json): int {
        global $DB;

        $dataid = (int)$DB->insert_record('user_info_data', (object)[
            'userid' => $userid,
            'fieldid' => $field->id,
            'data' => $json,
            'dataformat' => 0,
        ]);
        $setid = 1;
        foreach (json_decode($json, true) as $set) {
            $DB->insert_record('profilefield_repeatable_data', (object)[
                'dataid' => $dataid,
                'fieldid' => $field->id,
                'userid' => $userid,
                'set_id' => $setid++,
                'data' => json_encode($set, JSON_UNESCAPED_UNICODE),
                'timecreated' => 1,
                'timemodified' => 1,
            ]);
        }
        return $dataid;
    }

    /**
     * Convert a version 1 field as the definition form does, without re-keying its payloads.
     *
     * @param \stdClass $field The {user_info_field} record.
     * @return schema The new schema with its legacy labels.
     */
    private function convert(\stdClass $field): schema {
        global $DB;

        $schema = field_schema::from_v1(
            (string)$field->param1,
            (string)$field->param2,
            (string)$field->param3,
            (string)$field->param4
        );
        set_config(
            field_schema::legacy_config_name((int)$field->id),
            json_encode((object)$schema->legacy_labels(), JSON_UNESCAPED_UNICODE),
            'profilefield_repeatable'
        );
        $DB->update_record('user_info_field', (object)[
            'id' => (int)$field->id,
            'param1' => $schema->to_json(),
            'param2' => '',
            'param3' => '',
            'param4' => '',
        ]);
        profile_purge_user_fields_cache();

        return $schema;
    }

    /**
     * Decoded projection rows of one record, by set number; compare with assertEquals(), JSONB reorders keys.
     *
     * @param int $dataid
     * @return array
     */
    private function projected(int $dataid): array {
        global $DB;

        $sets = [];
        foreach ($DB->get_records('profilefield_repeatable_data', ['dataid' => $dataid], 'set_id ASC') as $row) {
            $sets[(int)$row->set_id] = json_decode((string)$row->data, true);
        }
        return $sets;
    }

    /**
     * Until the task re-keys a converted field, every reader takes either payload shape; the task finishes it.
     */
    public function test_converted_field_reads_both_shapes_until_the_task_rekeys_it(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $field = $this->v1_field();
        $alice = $this->getDataGenerator()->create_user(['username' => 'alice']);
        $alicedata = $this->v1_payload($field, (int)$alice->id, self::ALICE);

        $rbgenerator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgenerator->create_report(['name' => 'Certificates', 'source' => repeatable_sets::class, 'default' => 0]);
        $identifiers = ['user:username', 'repeatable_langcerts:lingua_code', 'repeatable_langcerts:certificate_number'];
        foreach ($identifiers as $order => $id) {
            (new column(0, (object)['reportid' => $report->get('id'), 'uniqueidentifier' => $id, 'columnorder' => $order + 1]))
                ->create();
        }
        $rbgenerator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:username']);

        $this->convert($field);
        rekey_payloads::queue_for_field((int)$field->id);

        // Half-way: schema written, payloads and projection still keyed by label.
        $this->assertSame(self::ALICE, $DB->get_field('user_info_data', 'data', ['id' => $alicedata]));
        $this->assertSame('pt', $this->projected($alicedata)[1]['Língua']);

        $profilefield = new \profile_field_repeatable((int)$field->id, (int)$alice->id);
        $loaded = new \stdClass();
        $profilefield->edit_load_user_data($loaded);
        $this->assertStringStartsWith(
            '{"@@ID#1":{"lingua":"pt","level":"C2","certificate_number":"CEL-1"}',
            $loaded->{$profilefield->inputname}
        );
        $content = $this->get_custom_report_content((int)$report->get('id'), 30, [
            'user:username_operator' => text::IS_EQUAL_TO,
            'user:username_value' => 'alice',
        ]);
        $this->assertEqualsCanonicalizing(
            [['alice', 'pt', 'CEL-1'], ['alice', 'es', 'DEL-2']],
            array_map('array_values', $content)
        );

        // The define form locks the keys holding values through the legacy labels too.
        $stored = $DB->get_record('user_info_field', ['id' => $field->id]);
        $usage = stored_values::keys_in_use((int)$field->id, field_schema::from_field($stored));
        $this->assertSame(['lingua', 'level', 'certificate_number'], $usage['keys']);

        // The task finishes the field.
        $this->expectOutputRegex('/re-keyed 1 payload\(s\) of field ' . $field->id . '/');
        $this->runAdhocTasks(rekey_payloads::class);
        $this->assertSame(
            '{"@@ID#1":{"lingua":"pt","level":"C2","certificate_number":"CEL-1"},'
                . '"@@ID#2":{"lingua":"es","level":"","certificate_number":"DEL-2"}}',
            $DB->get_field('user_info_data', 'data', ['id' => $alicedata])
        );
        $this->assertEquals(['lingua' => 'pt', 'level' => 'C2', 'certificate_number' => 'CEL-1'], $this->projected($alicedata)[1]);
        $this->assertFalse(get_config('profilefield_repeatable', field_schema::legacy_config_name((int)$field->id)));
        $this->assertSame(0, migration::finish_field((int)$field->id), 'Idempotent');
    }

    /**
     * A user save landing while the payloads are re-keyed is kept, and a vanished field forgets its labels.
     */
    public function test_rekey_keeps_newer_saves_and_handles_missing_fields(): void {
        global $DB;

        $this->resetAfterTest();
        $field = $this->v1_field();
        $user = $this->getDataGenerator()->create_user();
        $dataid = $this->v1_payload($field, (int)$user->id, self::ALICE);
        $schema = $this->convert($field);
        $this->assertNotEmpty(get_config('profilefield_repeatable', field_schema::legacy_config_name((int)$field->id)));

        // A save of the new shape lands between the read and the write: the write leaves it alone.
        $saved = '{"@@ID#1":{"lingua":"fr","level":"","certificate_number":""}}';
        $DB->set_field('user_info_data', 'data', $saved, ['id' => $dataid]);
        $this->assertFalse(migration::write_if_unchanged($dataid, self::ALICE, '{"stale":"rewrite"}'));
        $this->assertSame($saved, $DB->get_field('user_info_data', 'data', ['id' => $dataid]));
        // Control: written when the stored text is the one read.
        $this->assertTrue(migration::write_if_unchanged($dataid, $saved, '{}'));
        $this->assertSame('{}', $DB->get_field('user_info_data', 'data', ['id' => $dataid]));
        // A payload already in the new shape is not rewritten.
        $DB->set_field('user_info_data', 'data', $saved, ['id' => $dataid]);
        $this->assertSame(0, migration::rekey_payloads((int)$field->id, $schema));

        $DB->delete_records('user_info_field', ['id' => $field->id]);
        $this->assertSame(0, migration::finish_field((int)$field->id));
        $this->assertFalse(get_config('profilefield_repeatable', field_schema::legacy_config_name((int)$field->id)));
    }

    /**
     * The compare-and-set is exact: a payload differing only in case or accents is not the one read.
     *
     * MariaDB's default collation is case and accent insensitive; the comparison must not be.
     */
    public function test_write_if_unchanged_compares_case_and_accents(): void {
        global $DB;

        $this->resetAfterTest();
        $field = $this->v1_field();
        $user = $this->getDataGenerator()->create_user();
        $stored = '{"@@ID#1":{"Língua":"Alice"}}';
        $dataid = $this->v1_payload($field, (int)$user->id, $stored);

        $this->assertFalse(migration::write_if_unchanged($dataid, '{"@@ID#1":{"Língua":"alice"}}', '{"case":1}'));
        $this->assertFalse(migration::write_if_unchanged($dataid, '{"@@ID#1":{"Lingua":"Alice"}}', '{"accent":1}'));
        $this->assertSame($stored, $DB->get_field('user_info_data', 'data', ['id' => $dataid]));
        $this->assertTrue(migration::write_if_unchanged($dataid, $stored, '{"exact":1}'));
    }
}
