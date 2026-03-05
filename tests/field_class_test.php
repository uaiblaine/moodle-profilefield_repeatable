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

/**
 * Tests for profile_field_repeatable.
 *
 * @package    profilefield_repeatable
 * @covers     \profile_field_repeatable
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class field_class_test extends \advanced_testcase {
    /**
     * Create repeatable profile field instance.
     *
     * @param array $extradata
     * @return \profile_field_repeatable
     */
    protected function create_field(array $extradata = []): \profile_field_repeatable {
        $fielddata = $this->getDataGenerator()->create_custom_profile_field(array_merge([
            'datatype' => 'repeatable',
            'name' => 'Repeatable test',
            'shortname' => 'repeatable_test',
            'required' => 0,
            'forceunique' => 0,
            'defaultdata' => '{}',
            'param1' => "name\nrole",
        ], $extradata));

        return new \profile_field_repeatable(0, 0, $fielddata);
    }

    /**
     * Validate strict JSON object requirement.
     */
    public function test_edit_validate_field_invalid_json(): void {
        $this->resetAfterTest();

        $field = $this->create_field();
        $inputname = $field->inputname;

        $errors = $field->edit_validate_field((object) [
            'id' => 0,
            $inputname => '[]',
        ]);

        $this->assertArrayHasKey($inputname, $errors);
        $this->assertSame(get_string('errorinvalidjson', 'profilefield_repeatable'), $errors[$inputname]);
    }

    /**
     * Validate required behaviour for repeatable profile field.
     */
    public function test_edit_validate_field_required_rule(): void {
        $this->resetAfterTest();

        $field = $this->create_field(['required' => 1]);
        $inputname = $field->inputname;

        $errors = $field->edit_validate_field((object) [
            'id' => 0,
            $inputname => '{}',
        ]);
        $this->assertArrayHasKey($inputname, $errors);

        $errors = $field->edit_validate_field((object) [
            'id' => 0,
            $inputname => '{"@@ID#1":{"name":"Alice","role":""}}',
        ]);
        $this->assertArrayNotHasKey($inputname, $errors);
    }

    /**
     * Ensure preprocessing persists normalised strict JSON.
     */
    public function test_edit_save_data_preprocess_normalises_payload(): void {
        $this->resetAfterTest();

        $field = $this->create_field();

        $processed = $field->edit_save_data_preprocess(
            '{"@@ID#10":{"name":"","role":""},"@@ID#20":{"name":"Alice","role":"Manager"},"@@ID#30":{"name":"Bob","role":""}}',
            new \stdClass()
        );

        $this->assertSame(
            '{"@@ID#1":{"name":"Alice","role":"Manager"},"@@ID#2":{"name":"Bob","role":""}}',
            $processed
        );
    }
}
