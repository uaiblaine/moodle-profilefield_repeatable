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
require_once($CFG->dirroot . '/user/profile/field/repeatable/lib.php');

/**
 * Tests for the profile page callback of lib.php.
 *
 * The field's show_field_content() is always false, so core's own profile
 * callback never prints it and this callback is the only place the profile
 * page shows its values: it must apply the field's visibility setting
 * ({@see \profile_field_base::is_visible()}) itself.
 *
 * @package    profilefield_repeatable
 * @covers     ::profilefield_repeatable_myprofile_navigation
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lib_test extends \advanced_testcase {
    /** @var string The short name of the field under test. */
    private const SHORTNAME = 'repeatable_lib';

    /**
     * A repeatable field with the given visibility and one user holding a set in it.
     *
     * @param int $visible PROFILE_VISIBLE_* value.
     * @return array [the user_info_field record, the user holding the value].
     */
    private function field_with_value(int $visible): array {
        global $DB;

        $schema = $this->getDataGenerator()->get_plugin_generator('profilefield_repeatable')->schema_json(['name', 'role']);
        $field = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'repeatable',
            'name' => 'Repeatable lib',
            'shortname' => self::SHORTNAME,
            'visible' => $visible,
            'defaultdata' => '{}',
            'param1' => $schema,
        ]);
        $owner = $this->getDataGenerator()->create_user();
        $DB->insert_record('user_info_data', (object) [
            'userid' => $owner->id,
            'fieldid' => $field->id,
            'data' => '{"@@ID#1":{"name":"Alice","role":"Manager"}}',
            'dataformat' => 0,
        ]);
        return [$field, $owner];
    }

    /**
     * Whether the profile page of a user lists the field, for the current $USER.
     *
     * @param \stdClass $owner The user whose profile is built.
     * @return bool
     */
    private function profile_lists_field(\stdClass $owner): bool {
        $tree = new \core_user\output\myprofile\tree();
        profilefield_repeatable_myprofile_navigation($tree, $owner, false, null);
        return array_key_exists('repeatable_' . self::SHORTNAME, $tree->nodes);
    }

    /**
     * A private field is hidden from another user and shown once the field is visible to everyone.
     *
     * @return void
     */
    public function test_profile_page_follows_field_visibility(): void {
        global $DB;

        $this->resetAfterTest();
        [$field, $owner] = $this->field_with_value(PROFILE_VISIBLE_PRIVATE);
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertFalse($this->profile_lists_field($owner));

        // The control: the same viewer and value, with the field visible to everyone.
        $DB->set_field('user_info_field', 'visible', PROFILE_VISIBLE_ALL, ['id' => $field->id]);
        $this->assertTrue($this->profile_lists_field($owner));
    }

    /**
     * A private field is shown to its owner and to a viewer holding moodle/user:viewalldetails.
     *
     * @return void
     */
    public function test_private_field_is_shown_to_the_owner_and_to_an_administrator(): void {
        $this->resetAfterTest();
        [, $owner] = $this->field_with_value(PROFILE_VISIBLE_PRIVATE);

        $this->setUser($owner);
        $this->assertTrue($this->profile_lists_field($owner));

        $this->setAdminUser();
        $this->assertTrue($this->profile_lists_field($owner));
    }

    /**
     * A field nobody may see (PROFILE_VISIBLE_NONE) is hidden even from its owner.
     *
     * @return void
     */
    public function test_hidden_field_is_not_shown_to_its_owner(): void {
        $this->resetAfterTest();
        [, $owner] = $this->field_with_value(PROFILE_VISIBLE_NONE);

        $this->setUser($owner);
        $this->assertFalse($this->profile_lists_field($owner));

        // The control: an administrator holds moodle/user:viewalldetails at system level.
        $this->setAdminUser();
        $this->assertTrue($this->profile_lists_field($owner));
    }
}
