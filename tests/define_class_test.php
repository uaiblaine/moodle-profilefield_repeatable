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
require_once($CFG->dirroot . '/user/profile/definelib.php');
require_once($CFG->dirroot . '/user/profile/field/repeatable/define.class.php');

/**
 * Tests for profile_define_repeatable.
 *
 * @package    profilefield_repeatable
 * @covers     \profile_define_repeatable
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class define_class_test extends \advanced_testcase {
    /**
     * Validate empty sub-item configuration.
     */
    public function test_define_validate_specific_requires_subitems(): void {
        $define = new \profile_define_repeatable();

        $errors = $define->define_validate_specific((object) [
            'param1' => "\n   \n",
        ], []);

        $this->assertArrayHasKey('param1', $errors);
        $this->assertEquals(get_string('errorsubitemsrequired', 'profilefield_repeatable'), $errors['param1']);
    }

    /**
     * Validate duplicate sub-items in configuration.
     */
    public function test_define_validate_specific_rejects_duplicate_subitems(): void {
        $define = new \profile_define_repeatable();

        $errors = $define->define_validate_specific((object) [
            'param1' => "Name\nRole\nname",
        ], []);

        $this->assertArrayHasKey('param1', $errors);
        $this->assertEquals(get_string('errorsubitemsduplicate', 'profilefield_repeatable'), $errors['param1']);
    }
}
