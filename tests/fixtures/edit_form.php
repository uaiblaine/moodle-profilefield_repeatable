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

/**
 * Test form hosting one repeatable profile field the way core's profile forms do.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Form calling the field's edit hooks in the order user/edit.php does.
 *
 * Custom data: 'field' => profile_field_repeatable.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profilefield_repeatable_test_edit_form extends moodleform {
    /**
     * Add the field's elements.
     *
     * @return void
     */
    protected function definition() {
        $this->_customdata['field']->edit_field($this->_form);
    }

    /**
     * Apply the field's post-data tweaks.
     *
     * @return void
     */
    public function definition_after_data() {
        $this->_customdata['field']->edit_after_data($this->_form);
    }

    /**
     * Validate through the field, as profile_validation() does.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        return $this->_customdata['field']->edit_validate_field((object)$data);
    }
}
