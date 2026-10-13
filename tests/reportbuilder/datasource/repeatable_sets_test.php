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

namespace profilefield_repeatable\reportbuilder\datasource;

use core_reportbuilder\local\filters\text;
use core_reportbuilder\manager;
use core_reportbuilder\tests\core_reportbuilder_testcase;
use profilefield_repeatable\reportbuilder\local\entities\repeatable_set;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->dirroot . '/user/profile/field/repeatable/field.class.php');

/**
 * Tests for the repeatable_sets Report Builder datasource.
 *
 * Metadata stays in docblock annotations: the plugin supports Moodle 4.5, whose moodle-cs
 * cannot read PHPUnit attributes.
 *
 * @package    profilefield_repeatable
 * @covers     \profilefield_repeatable\reportbuilder\datasource\repeatable_sets
 * @covers     \profilefield_repeatable\reportbuilder\local\entities\repeatable_set
 * @copyright  2026 Anderson Blaine (anderson@blaine.com.br)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class repeatable_sets_test extends core_reportbuilder_testcase {
    /**
     * Create a repeatable profile field definition.
     *
     * @param array $extra Extra field configuration to merge.
     * @return \stdClass The user_info_field record.
     */
    private function create_repeatable_field(array $extra = []): \stdClass {
        return $this->getDataGenerator()->create_custom_profile_field(array_merge([
            'datatype' => 'repeatable',
            'name' => 'Lotacao',
            'shortname' => 'lotacao',
            'param1' => "diretoria\nescola",
        ], $extra));
    }

    /**
     * Persist a full payload of sets for one user.
     *
     * @param \stdClass $fielddef The field record.
     * @param \stdClass $user The user.
     * @param string $json Full payload JSON.
     */
    private function save_sets(\stdClass $fielddef, \stdClass $user, string $json): void {
        $field = new \profile_field_repeatable(0, 0, $fielddef);
        $field->edit_save_data((object) [
            'id' => $user->id,
            $field->inputname => $json,
        ]);
    }

    /**
     * Report Builder entity name for a given field.
     *
     * @param \stdClass $fielddef The field record.
     * @return string
     */
    private function entity_name(\stdClass $fielddef): string {
        return 'repeatable_' . $fielddef->shortname;
    }

    /**
     * Whether the companion local plugin tables are installed.
     *
     * @return bool
     */
    private function local_plugin_available(): bool {
        global $DB;

        $dbman = $DB->get_manager();
        return class_exists('\local_profilefield_repeatable\resolver')
            && $dbman->table_exists('local_profilefield_repeatable_domain')
            && $dbman->table_exists('local_profilefield_repeatable_item');
    }

    /**
     * Seed a reference domain with code/label items.
     *
     * @param string $shortname Domain shortname.
     * @param array $codetolabel Code to label map (code => label).
     */
    private function seed_domain(string $shortname, array $codetolabel): void {
        global $DB;

        $now = time();
        $domainid = $DB->insert_record('local_profilefield_repeatable_domain', (object) [
            'shortname' => $shortname,
            'name' => ucfirst($shortname),
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        foreach ($codetolabel as $code => $label) {
            $DB->insert_record('local_profilefield_repeatable_item', (object) [
                'domainid' => $domainid,
                'code' => (string) $code,
                'label' => $label,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
    }

    /**
     * The datasource is only offered once a repeatable field exists.
     */
    public function test_is_available_reflects_field_existence(): void {
        $this->resetAfterTest();

        $this->assertFalse(repeatable_sets::is_available());
        $this->create_repeatable_field();
        $this->assertTrue(repeatable_sets::is_available());
    }

    /**
     * Each stored set becomes its own report row with its sub-item values.
     */
    public function test_row_per_set(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $fielddef = $this->create_repeatable_field();
        $user = $this->getDataGenerator()->create_user();
        $this->save_sets(
            $fielddef,
            $user,
            '{"@@ID#1":{"diretoria":"16","escola":"45"},"@@ID#2":{"diretoria":"99","escola":"88"}}'
        );

        $entity = $this->entity_name($fielddef);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'Sets', 'source' => repeatable_sets::class, 'default' => 0]);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:fullname']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => $entity . ':setnumber']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => $entity . ':subitem1']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => $entity . ':subitem2']);
        $generator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:username']);

        $content = $this->get_custom_report_content($report->get('id'), 0, [
            'user:username_operator' => text::IS_EQUAL_TO,
            'user:username_value' => $user->username,
        ]);

        $this->assertCount(2, $content);

        $rows = array_map('array_values', $content);
        $this->assertEqualsCanonicalizing([1, 2], array_map('intval', array_column($rows, 1)));
        $this->assertEqualsCanonicalizing(['16', '99'], array_column($rows, 2));
        $this->assertEqualsCanonicalizing(['45', '88'], array_column($rows, 3));
    }

    /**
     * A sub-item filter restricts rows by the stored value.
     */
    public function test_code_filter(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $fielddef = $this->create_repeatable_field();
        $user = $this->getDataGenerator()->create_user();
        $this->save_sets(
            $fielddef,
            $user,
            '{"@@ID#1":{"diretoria":"16","escola":"45"},"@@ID#2":{"diretoria":"99","escola":"88"}}'
        );

        $entity = $this->entity_name($fielddef);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'Sets', 'source' => repeatable_sets::class, 'default' => 0]);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => $entity . ':subitem1']);
        $generator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:username']);
        $generator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => $entity . ':subitem1']);

        $content = $this->get_custom_report_content($report->get('id'), 0, [
            'user:username_operator' => text::IS_EQUAL_TO,
            'user:username_value' => $user->username,
            $entity . ':subitem1_operator' => text::IS_EQUAL_TO,
            $entity . ':subitem1_value' => '16',
        ]);

        $this->assertCount(1, $content);
        $this->assertSame('16', array_values($content[0])[0]);
    }

    /**
     * Mapped sub-items expose a raw-code column independent of the companion plugin.
     */
    public function test_mapped_code_column(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $fielddef = $this->create_repeatable_field(['param3' => 'diretoria|diretorias']);
        $user = $this->getDataGenerator()->create_user();
        $this->save_sets($fielddef, $user, '{"@@ID#1":{"diretoria":"16","escola":"45"}}');

        $entity = $this->entity_name($fielddef);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'Sets', 'source' => repeatable_sets::class, 'default' => 0]);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => $entity . ':subitemcode1']);
        $generator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:username']);

        $content = $this->get_custom_report_content($report->get('id'), 0, [
            'user:username_operator' => text::IS_EQUAL_TO,
            'user:username_value' => $user->username,
        ]);

        $this->assertCount(1, $content);
        $this->assertSame('16', array_values($content[0])[0]);
    }

    /**
     * Mapped sub-items resolve to labels in the column, and the name filter filters by code.
     */
    public function test_mapped_name_resolution_and_filter(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        if (!$this->local_plugin_available()) {
            $this->markTestSkipped('local_profilefield_repeatable is not installed.');
        }

        $this->seed_domain('diretorias', ['16' => 'Diretoria Centro', '99' => 'Diretoria Norte']);

        $fielddef = $this->create_repeatable_field(['param3' => 'diretoria|diretorias']);
        $user = $this->getDataGenerator()->create_user();
        $this->save_sets(
            $fielddef,
            $user,
            '{"@@ID#1":{"diretoria":"16","escola":"45"},"@@ID#2":{"diretoria":"99","escola":"88"}}'
        );

        $entity = $this->entity_name($fielddef);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'Sets', 'source' => repeatable_sets::class, 'default' => 0]);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => $entity . ':subitemname1']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => $entity . ':subitemcode1']);
        $generator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:username']);
        $generator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => $entity . ':subitemname1']);

        $content = $this->get_custom_report_content($report->get('id'), 0, [
            'user:username_operator' => text::IS_EQUAL_TO,
            'user:username_value' => $user->username,
        ]);

        $rows = array_map('array_values', $content);
        $this->assertEqualsCanonicalizing(['Diretoria Centro', 'Diretoria Norte'], array_column($rows, 0));
        $this->assertEqualsCanonicalizing(['16', '99'], array_column($rows, 1));

        $filtered = $this->get_custom_report_content($report->get('id'), 0, [
            'user:username_operator' => text::IS_EQUAL_TO,
            'user:username_value' => $user->username,
            $entity . ':subitemname1_values' => ['16'],
        ]);

        $this->assertCount(1, $filtered);
        $this->assertSame('Diretoria Centro', array_values($filtered[0])[0]);
    }
    /**
     * The source is hidden on databases its sub-item SQL has no form for.
     */
    public function test_is_available_only_on_supported_db_families(): void {
        global $DB;

        $this->resetAfterTest();
        $this->create_repeatable_field();

        $realdb = $DB;
        try {
            foreach (['mssql' => false, 'oracle' => false, 'postgres' => true, 'mysql' => true] as $family => $expected) {
                $mock = $this->createMock(\moodle_database::class);
                $mock->method('get_dbfamily')->willReturn($family);
                $mock->method('record_exists')->willReturn(true);
                $DB = $mock;
                $this->assertSame($expected, repeatable_sets::is_available(), "Database family {$family}");
            }
        } finally {
            $DB = $realdb;
        }
    }

    /**
     * Value, code and resolved-name columns escape stored text.
     */
    public function test_value_columns_escape_stored_text(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $fielddef = $this->create_repeatable_field(['param3' => 'diretoria|diretorias']);
        $user = $this->getDataGenerator()->create_user();
        $this->save_sets($fielddef, $user, json_encode([
            '@@ID#1' => ['diretoria' => '<img src=x onerror=alert(1)>', 'escola' => '<script>alert(2)</script>'],
        ]));

        $entity = $this->entity_name($fielddef);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'Sets', 'source' => repeatable_sets::class, 'default' => 0]);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => $entity . ':subitemname1']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => $entity . ':subitemcode1']);
        $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => $entity . ':subitem2']);
        $generator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => 'user:username']);

        $content = $this->get_custom_report_content($report->get('id'), 0, [
            'user:username_operator' => text::IS_EQUAL_TO,
            'user:username_value' => $user->username,
        ]);

        $this->assertCount(1, $content);
        [$name, $code, $value] = array_values($content[0]);
        $this->assertSame('&lt;img src=x onerror=alert(1)&gt;', $name, 'Resolved-name column');
        $this->assertSame('&lt;img src=x onerror=alert(1)&gt;', $code, 'Code column');
        $this->assertSame('&lt;script&gt;alert(2)&lt;/script&gt;', $value, 'Value column');
    }

    /**
     * Name filter options keep the raw code as key and escape the label core prints unescaped.
     */
    public function test_name_filter_options_escape_labels(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $fielddef = $this->create_repeatable_field(['param3' => 'diretoria|diretorias']);
        $user = $this->getDataGenerator()->create_user();
        $code = '<img src=x onerror=alert(1)>';
        $this->save_sets($fielddef, $user, json_encode(['@@ID#1' => ['diretoria' => $code, 'escola' => '1']]));

        $method = new \ReflectionMethod(repeatable_set::class, 'name_filter_options');
        $options = $method->invoke(null, (int)$fielddef->id, 'diretoria', 'diretorias');

        $this->assertSame([$code => s($code)], $options);
    }

    /**
     * Visibility cases: field visibility, whether the viewer is an admin, expected availability.
     *
     * @return array
     */
    public static function visibility_provider(): array {
        return [
            'private, plain user' => [PROFILE_VISIBLE_PRIVATE, false, false],
            'teachers, plain user' => [PROFILE_VISIBLE_TEACHERS, false, false],
            'nobody, plain user' => [PROFILE_VISIBLE_NONE, false, false],
            'everyone, plain user' => [PROFILE_VISIBLE_ALL, false, true],
            'private, admin' => [PROFILE_VISIBLE_PRIVATE, true, true],
            'teachers, admin' => [PROFILE_VISIBLE_TEACHERS, true, true],
        ];
    }

    /**
     * Every column and filter of a field follows the field's visibility, as core's own do.
     *
     * @dataProvider visibility_provider
     * @param string $visible The field's visibility setting (PROFILE_VISIBLE_*).
     * @param bool $asadmin Whether the report is viewed by an admin.
     * @param bool $expected Whether the columns and filters are available.
     */
    public function test_columns_and_filters_follow_field_visibility(string $visible, bool $asadmin, bool $expected): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $fielddef = $this->create_repeatable_field(['param3' => 'diretoria|diretorias', 'visible' => $visible]);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'Sets', 'source' => repeatable_sets::class, 'default' => 0]);

        if (!$asadmin) {
            $this->setUser($this->getDataGenerator()->create_user());
        }
        manager::reset_caches();
        $instance = manager::get_report_from_persistent($report);

        $entity = $this->entity_name($fielddef);
        foreach (['setnumber', 'subitemname1', 'subitemcode1', 'subitem2'] as $name) {
            $column = $instance->get_column("{$entity}:{$name}");
            $this->assertNotNull($column, "Column {$name}");
            $this->assertSame($expected, $column->get_is_available(), "Column {$name}");

            $filter = $instance->get_filter("{$entity}:{$name}");
            $this->assertNotNull($filter, "Filter {$name}");
            $this->assertSame($expected, $filter->get_is_available(), "Filter {$name}");
        }

        // Control: the user entity of the same report is always available.
        $this->assertTrue($instance->get_column('user:fullname')->get_is_available());
    }

    /**
     * Availability of the columns and filters of a field's entity as seen by the current user.
     *
     * @param \stdClass $fielddef The field record.
     * @param \stdClass $report The report persistent.
     * @return bool
     */
    private function sets_available(\stdClass $fielddef, $report): bool {
        manager::reset_caches();
        $instance = manager::get_report_from_persistent($report);
        $entity = $this->entity_name($fielddef);

        $column = $instance->get_column("{$entity}:setnumber");
        $filter = $instance->get_filter("{$entity}:setnumber");
        $this->assertNotNull($column);
        $this->assertNotNull($filter);
        $this->assertSame($column->get_is_available(), $filter->get_is_available());

        return $column->get_is_available();
    }

    /**
     * Visibility is decided with the same capabilities as core's profile-field columns.
     *
     * The field instance has no user, so a TEACHERS field follows moodle/site:viewuseridentity in
     * the global course (moodle/user:viewalldetails opens only PRIVATE and NONE fields), and
     * without a user (cron, CLI) a PRIVATE field is not available.
     */
    public function test_visibility_uses_the_capabilities_core_checks(): void {
        global $COURSE;

        $this->resetAfterTest();
        $this->setAdminUser();

        $teachers = $this->create_repeatable_field([
            'param3' => 'diretoria|diretorias',
            'visible' => PROFILE_VISIBLE_TEACHERS,
            'shortname' => 'teachersfield',
            'name' => 'Teachers field',
        ]);
        $private = $this->create_repeatable_field([
            'param3' => 'diretoria|diretorias',
            'visible' => PROFILE_VISIBLE_PRIVATE,
            'shortname' => 'privatefield',
            'name' => 'Private field',
        ]);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'Sets', 'source' => repeatable_sets::class, 'default' => 0]);

        // Only moodle/user:viewalldetails, assigned at system level: PRIVATE yes, TEACHERS no (as in core).
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/user:viewalldetails', CAP_ALLOW, $roleid, \core\context\system::instance());
        $viewer = $this->getDataGenerator()->create_user();
        role_assign($roleid, $viewer->id, \core\context\system::instance());
        $this->setUser($viewer);
        $this->assertFalse(has_capability('moodle/site:viewuseridentity', \core\context\course::instance(SITEID)));
        $this->assertTrue($this->sets_available($private, $report), 'viewalldetails sees PRIVATE');
        $this->assertFalse($this->sets_available($teachers, $report), 'viewalldetails alone does not open TEACHERS');

        // Only moodle/site:viewuseridentity, through an editing teacher role in a course that is the global course.
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);
        $this->assertTrue(has_capability('moodle/site:viewuseridentity', \core\context\course::instance($course->id)));
        $this->assertFalse(has_capability('moodle/user:viewalldetails', \core\context\system::instance()));
        $this->assertFalse($this->sets_available($teachers, $report), 'site course: other course teacher, no TEACHERS');
        $COURSE = $course;
        $this->assertTrue($this->sets_available($teachers, $report), 'global course: viewuseridentity there sees TEACHERS');
        $this->assertFalse($this->sets_available($private, $report), 'course teacher does not see PRIVATE');

        // No user at all: a PRIVATE field is not available.
        $this->setUser(0);
        $this->assertFalse($this->sets_available($private, $report), 'no user does not see PRIVATE');
    }
}
