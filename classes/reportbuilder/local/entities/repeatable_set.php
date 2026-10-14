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

namespace profilefield_repeatable\reportbuilder\local\entities;

use profilefield_repeatable\local\field_schema;
use profilefield_repeatable\local\schema;
use profilefield_repeatable\local\source;

/**
 * Report Builder entity exposing one repeatable profile field as one row per set.
 *
 * Profile adapter: the projection is {profilefield_repeatable_data}, the schema is read from the
 * {user_info_field} record, and the columns and filters are available as core decides for its
 * own profile-field columns ({@see self::is_available()}). The columns and filters themselves
 * are the engine's ({@see sets}).
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repeatable_set extends sets {
    /** @var \stdClass The user_info_field record this entity represents. */
    private \stdClass $field;

    /**
     * Constructor.
     *
     * @param \stdClass $field The user_info_field record (datatype repeatable).
     */
    public function __construct(\stdClass $field) {
        parent::__construct(source::TABLE, (int)($field->id ?? 0));
        $this->field = $field;
    }

    /**
     * The field's column schema, version 2 or version 1.
     *
     * @return schema
     */
    protected function get_schema(): schema {
        return field_schema::from_field($this->field);
    }

    /**
     * Whether the current user may see this profile field in reports.
     *
     * Same gate as core's own profile-field columns and filters
     * ({@see \core_reportbuilder\local\helpers\user_profile_fields}): the field's visibility
     * setting checked at system level, for a field instance bound to a user id that matches nobody.
     * Core passes {@see \core\user::SUPPORT_USER} for the same reason: with user id 0 the TEACHERS
     * branch checks moodle/site:viewuseridentity in the global course instead of
     * moodle/user:viewalldetails, and TEACHERS and PRIVATE fields are visible when $USER->id is 0.
     *
     * @return bool
     */
    protected function is_available(): bool {
        global $CFG;

        require_once($CFG->dirroot . '/user/profile/lib.php');

        $profilefield = profile_get_user_field('repeatable', (int)$this->field->id, \core\user::SUPPORT_USER, $this->field);
        return $profilefield->is_visible(\core\context\system::instance());
    }
}
