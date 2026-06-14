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
use core_reportbuilder\tests\core_reportbuilder_testcase;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->dirroot . '/user/profile/field/repeatable/field.class.php');

/**
 * Tests for the repeatable_sets Report Builder datasource.
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
     * @param array<string, string> $codetolabel Code to label map.
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
}
