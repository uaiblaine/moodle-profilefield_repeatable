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
require_once($CFG->dirroot . '/user/profile/field/repeatable/field.class.php');

use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\user_filter_manager;
use core_reportbuilder\local\models\column;
use core_reportbuilder\local\models\filter;
use core_reportbuilder\tests\core_reportbuilder_testcase;
use profilefield_repeatable\local\migration;
use profilefield_repeatable\local\schema;
use profilefield_repeatable\reportbuilder\datasource\repeatable_sets;
use profilefield_repeatable\task\rekey_payloads;

/**
 * Runs the real column schema v2 upgrade step on version 1 fields, payloads, projections and reports.
 *
 * Metadata stays in docblocks: the plugin supports Moodle 4.5, whose moodle-cs cannot read
 * PHPUnit attributes.
 *
 * @package    profilefield_repeatable
 * @category   test
 * @covers     ::xmldb_profilefield_repeatable_upgrade
 * @covers     ::profilefield_repeatable_upgrade_to_schema_v2
 * @covers     \profilefield_repeatable\local\migration
 * @covers     \profilefield_repeatable\task\rekey_payloads
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upgrade_test extends core_reportbuilder_testcase {
    /** @var string The version 1 payload of the first user. */
    private const ALICE = '{"@@ID#1":{"Língua":"pt","Level":"C2","Certificate number":"CEL-1"},'
        . '"@@ID#2":{"Língua":"es","Level":"","Certificate number":"DEL-2"}}';

    /**
     * Load the upgrade code.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/user/profile/field/repeatable/db/upgrade.php');
    }

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
     * Store a version 1 payload and its version 1 projection rows, as 2.x wrote them.
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
     * Run the step from the last version 1 release.
     */
    private function upgrade(): void {
        // The savepoint refuses to run unless the stored version is below the step's.
        set_config('version', 2026080705, 'profilefield_repeatable');
        $this->assertTrue(xmldb_profilefield_repeatable_upgrade(2026080705));
        $this->assertEquals(2026101301, get_config('profilefield_repeatable', 'version'));
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
     * The step converts the schema, re-keys payloads and projection, and renames saved report identifiers.
     */
    public function test_upgrade_converts_a_v1_field_and_its_reports(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $field = $this->v1_field();
        $alice = $this->getDataGenerator()->create_user(['username' => 'alice']);
        $bob = $this->getDataGenerator()->create_user(['username' => 'bob']);
        $alicedata = $this->v1_payload($field, (int)$alice->id, self::ALICE);
        $bobdata = $this->v1_payload($field, (int)$bob->id, '{"@@ID#1":{"Língua":"en","Level":"B1","Certificate number":""}}');
        // A field already on version 2 is left alone.
        $v2 = $this->getDataGenerator()->get_plugin_generator('profilefield_repeatable')
            ->create_field(['Name'], ['shortname' => 'already']);

        // A report saved under version 1: columns, a filter, a condition and a user's filter values.
        $entity = 'repeatable_langcerts';
        $report = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder')->create_report([
            'name' => 'Certificates', 'source' => repeatable_sets::class, 'default' => 0,
        ]);
        $reportid = (int)$report->get('id');
        $identifiers = ['user:username', "{$entity}:setnumber", "{$entity}:subitemname1", "{$entity}:subitemcode1",
            "{$entity}:subitemname2", "{$entity}:subitem3"];
        foreach ($identifiers as $order => $identifier) {
            (new column(0, (object)['reportid' => $reportid, 'uniqueidentifier' => $identifier, 'columnorder' => $order + 1]))
                ->create();
        }
        (new filter(0, (object)['reportid' => $reportid, 'uniqueidentifier' => "{$entity}:subitemcode1", 'filterorder' => 1]))
            ->create();
        (new filter(0, (object)['reportid' => $reportid, 'uniqueidentifier' => "{$entity}:subitem3", 'iscondition' => 1,
            'filterorder' => 1]))->create();
        $DB->set_field('reportbuilder_report', 'conditiondata', json_encode([
            "{$entity}:subitem3_operator" => text::IS_NOT_EMPTY,
            "{$entity}:subitem3_value" => '',
        ]), ['id' => $reportid]);
        user_filter_manager::set($reportid, [
            "{$entity}:subitemcode1_operator" => text::IS_EQUAL_TO,
            "{$entity}:subitemcode1_value" => 'pt',
        ], (int)$alice->id);
        // A report of another source keeps its identifiers.
        $other = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder')->create_report([
            'name' => 'Users', 'source' => \core_user\reportbuilder\datasource\users::class, 'default' => 0,
        ]);
        (new column(0, (object)['reportid' => $other->get('id'), 'uniqueidentifier' => "{$entity}:subitem3",
            'columnorder' => 1]))->create();

        $this->upgrade();

        // Schema.
        $record = $DB->get_record('user_info_field', ['id' => $field->id]);
        $this->assertSame(
            '{"version":2,"columns":['
                . '{"key":"lingua","label":"Língua","mode":"dictionary","domain":"iso639","title":true,"indexed":true},'
                . '{"key":"level","label":"Level","mode":"dictionary","domain":"cefr","subline":true},'
                . '{"key":"certificate_number","label":"Certificate number","mode":"free"}]}',
            $record->param1
        );
        $this->assertSame(['', '', ''], [$record->param2, $record->param3, $record->param4]);
        $this->assertSame($v2->param1, $DB->get_field('user_info_field', 'param1', ['id' => $v2->id]));

        // Payloads and projection, re-keyed; the legacy labels are gone.
        $this->assertSame(
            '{"@@ID#1":{"lingua":"pt","level":"C2","certificate_number":"CEL-1"},'
                . '"@@ID#2":{"lingua":"es","level":"","certificate_number":"DEL-2"}}',
            $DB->get_field('user_info_data', 'data', ['id' => $alicedata])
        );
        $this->assertEquals(
            [1 => ['lingua' => 'en', 'level' => 'B1', 'certificate_number' => '']],
            $this->projected($bobdata)
        );
        $this->assertEquals(
            ['lingua' => 'es', 'level' => '', 'certificate_number' => 'DEL-2'],
            $this->projected($alicedata)[2]
        );
        $this->assertFalse(get_config('profilefield_repeatable', schema::legacy_config_name((int)$field->id)));

        // Report identifiers.
        $columns = $DB->get_fieldset_sql(
            'SELECT uniqueidentifier FROM {reportbuilder_column} WHERE reportid = ? ORDER BY columnorder',
            [$reportid]
        );
        $this->assertSame(['user:username', "{$entity}:setnumber", "{$entity}:lingua_name", "{$entity}:lingua_code",
            "{$entity}:level_name", "{$entity}:certificate_number"], $columns);
        $this->assertEqualsCanonicalizing(
            ["{$entity}:lingua_code", "{$entity}:certificate_number"],
            $DB->get_fieldset_select('reportbuilder_filter', 'uniqueidentifier', 'reportid = ?', [$reportid])
        );
        $this->assertSame([
            "{$entity}:certificate_number_operator" => text::IS_NOT_EMPTY,
            "{$entity}:certificate_number_value" => '',
        ], json_decode($DB->get_field('reportbuilder_report', 'conditiondata', ['id' => $reportid]), true));
        $this->assertEquals([
            "{$entity}:lingua_code_operator" => text::IS_EQUAL_TO,
            "{$entity}:lingua_code_value" => 'pt',
        ], user_filter_manager::get($reportid, (int)$alice->id));
        $this->assertSame("{$entity}:subitem3", $DB->get_field('reportbuilder_column', 'uniqueidentifier', [
            'reportid' => $other->get('id'),
        ]));

        // The saved report runs on the new identifiers: Alice's filter keeps her "pt" set only.
        $this->setUser($alice);
        $content = $this->get_custom_report_content($reportid, 30, user_filter_manager::get($reportid));
        $this->assertSame([['alice', 1, 'pt', 'pt', 'C2', 'CEL-1']], array_map('array_values', $content));

        // On PostgreSQL the indexes follow the keys.
        if ($DB->get_dbfamily() === 'postgres') {
            $this->assertNotEmpty(\core\task\manager::get_adhoc_tasks(task\reconcile_indexes::class));
        }

        // A rerun changes nothing.
        $before = $DB->get_records('user_info_data');
        profilefield_repeatable_upgrade_to_schema_v2();
        $this->assertEquals($before, $DB->get_records('user_info_data'));
    }

    /**
     * A large field is finished by an adhoc task; until then every reader takes either payload shape.
     */
    public function test_upgrade_queues_large_fields_and_reads_both_shapes_meanwhile(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $field = $this->v1_field();
        $alice = $this->getDataGenerator()->create_user(['username' => 'alice']);
        $alicedata = $this->v1_payload($field, (int)$alice->id, self::ALICE);
        $rows = [];
        for ($i = 1; $i <= migration::INLINE_LIMIT; $i++) {
            $rows[] = ['userid' => 1000000 + $i, 'fieldid' => $field->id, 'dataformat' => 0,
                'data' => '{"@@ID#1":{"Língua":"pt","Level":"","Certificate number":"' . $i . '"}}'];
        }
        $DB->insert_records('user_info_data', $rows);

        $rbgenerator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $rbgenerator->create_report(['name' => 'Certificates', 'source' => repeatable_sets::class, 'default' => 0]);
        $identifiers = ['user:username', 'repeatable_langcerts:lingua_code', 'repeatable_langcerts:certificate_number'];
        foreach ($identifiers as $order => $id) {
            (new column(0, (object)['reportid' => $report->get('id'), 'uniqueidentifier' => $id, 'columnorder' => $order + 1]))
                ->create();
        }
        $rbgenerator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:username']);

        $this->upgrade();

        // Half-way: schema written, payloads and projection still keyed by label.
        $this->assertNotNull(schema::decode($DB->get_field('user_info_field', 'param1', ['id' => $field->id])));
        $this->assertSame(self::ALICE, $DB->get_field('user_info_data', 'data', ['id' => $alicedata]));
        $this->assertSame('pt', $this->projected($alicedata)[1]['Língua']);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(rekey_payloads::class));

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
        $usage = local\stored_values::keys_in_use((int)$field->id, schema::from_field($stored));
        $this->assertSame(['lingua', 'level', 'certificate_number'], $usage['keys']);

        // The task finishes the field.
        $this->expectOutputRegex('/re-keyed ' . (migration::INLINE_LIMIT + 1) . ' payload\(s\) of field ' . $field->id . '/');
        $this->runAdhocTasks(rekey_payloads::class);
        $this->assertSame(
            '{"@@ID#1":{"lingua":"pt","level":"","certificate_number":"7"}}',
            $DB->get_field('user_info_data', 'data', ['fieldid' => $field->id, 'userid' => 1000007])
        );
        $this->assertEquals(['lingua' => 'pt', 'level' => 'C2', 'certificate_number' => 'CEL-1'], $this->projected($alicedata)[1]);
        $this->assertFalse(get_config('profilefield_repeatable', schema::legacy_config_name((int)$field->id)));
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
        $schema = profilefield_repeatable_upgrade_convert_field($field);
        $this->assertNotEmpty(get_config('profilefield_repeatable', schema::legacy_config_name((int)$field->id)));

        // A save of the new shape lands between the read and the write: the write leaves it alone.
        $saved = '{"@@ID#1":{"lingua":"fr","level":"","certificate_number":""}}';
        $DB->set_field('user_info_data', 'data', $saved, ['id' => $dataid]);
        migration::write_if_unchanged($dataid, self::ALICE, '{"stale":"rewrite"}');
        $this->assertSame($saved, $DB->get_field('user_info_data', 'data', ['id' => $dataid]));
        // Control: written when the stored text is the one read.
        migration::write_if_unchanged($dataid, $saved, '{}');
        $this->assertSame('{}', $DB->get_field('user_info_data', 'data', ['id' => $dataid]));
        // A payload already in the new shape is not rewritten.
        $DB->set_field('user_info_data', 'data', $saved, ['id' => $dataid]);
        $this->assertSame(0, migration::rekey_payloads((int)$field->id, $schema));

        $DB->delete_records('user_info_field', ['id' => $field->id]);
        $this->assertSame(0, migration::finish_field((int)$field->id));
        $this->assertFalse(get_config('profilefield_repeatable', schema::legacy_config_name((int)$field->id)));
    }

    /**
     * Version 1 identifiers map by sub-item position onto key-based ones.
     */
    public function test_report_identifier_map(): void {
        $schema = schema::from_v1("A\nB", '', 'B|dom');

        $this->assertSame([
            'repeatable_x:subitem1' => 'repeatable_x:a',
            'repeatable_x:subitemname2' => 'repeatable_x:b_name',
            'repeatable_x:subitemcode2' => 'repeatable_x:b_code',
        ], profilefield_repeatable_upgrade_report_identifier_map('repeatable_x', $schema));
    }

    /**
     * Value keys are renamed by whole identifier, never by a prefix of a longer one.
     */
    public function test_rename_value_keys(): void {
        $map = ['e:subitem1' => 'e:a', 'e:subitem10' => 'e:j'];

        $this->assertSame(
            ['e:a_operator' => 1, 'e:j_value' => 'x', 'user:fullname_value' => 'y'],
            profilefield_repeatable_upgrade_rename_value_keys(
                ['e:subitem1_operator' => 1, 'e:subitem10_value' => 'x', 'user:fullname_value' => 'y'],
                $map
            )
        );
        $this->assertNull(profilefield_repeatable_upgrade_rename_value_keys(['user:fullname_value' => 'y'], $map));
    }
}
