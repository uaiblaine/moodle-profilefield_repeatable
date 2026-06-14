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

use core_reportbuilder\datasource;
use core_reportbuilder\local\entities\user;
use core_reportbuilder\local\helpers\database;
use lang_string;
use profilefield_repeatable\reportbuilder\local\entities\repeatable_set;

/**
 * Report Builder datasource yielding one row per repeatable profile field set.
 *
 * The core user entity provides the user columns/filters; each repeatable profile
 * field is joined as its own entity so a user with N sets produces N rows, enabling
 * native aggregation. Note: selecting columns from two different repeatable fields in
 * one report cross-joins their sets, so a report should target one repeatable field.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine (anderson@blaine.com.br)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repeatable_sets extends datasource {
    /**
     * Return the user-friendly name of the datasource.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('datasourcerepeatablesets', 'profilefield_repeatable');
    }

    /**
     * Only offer this datasource when at least one repeatable profile field exists.
     *
     * @return bool
     */
    public static function is_available(): bool {
        global $DB;

        return $DB->record_exists('user_info_field', ['datatype' => 'repeatable']);
    }

    /**
     * Initialise the datasource: user entity plus one set entity per repeatable field.
     */
    protected function initialise(): void {
        global $CFG, $DB;

        $userentity = new user();
        $useralias = $userentity->get_table_alias('user');

        $this->set_main_table('user', $useralias);
        $this->add_entity($userentity);

        $guestparam = database::generate_param_name();
        $this->add_base_condition_sql(
            "{$useralias}.id != :{$guestparam} AND {$useralias}.deleted = 0",
            [$guestparam => $CFG->siteguest]
        );

        $this->add_all_from_entity($userentity->get_entity_name());

        $fields = $DB->get_records('user_info_field', ['datatype' => 'repeatable'], 'id ASC');
        foreach ($fields as $field) {
            $fieldname = format_string($field->name, true, ['context' => \context_system::instance()]);

            $entity = new repeatable_set($field);
            $entity->set_entity_name('repeatable_' . $field->shortname);
            $entity->set_entity_title(
                new lang_string('entityrepeatablesetnamed', 'profilefield_repeatable', $fieldname)
            );

            $setalias = $entity->get_table_alias('profilefield_repeatable_data');
            $join = "LEFT JOIN {profilefield_repeatable_data} {$setalias} "
                . "ON {$setalias}.userid = {$useralias}.id "
                . "AND {$setalias}.fieldid = " . (int)$field->id;
            $entity->add_joins([$join]);

            $this->add_entity($entity);
            $this->add_all_from_entity($entity->get_entity_name());
        }
    }

    /**
     * Return the columns added to the report when it is created.
     *
     * @return string[]
     */
    public function get_default_columns(): array {
        return ['user:fullname'];
    }

    /**
     * Return the filters added to the report when it is created.
     *
     * @return string[]
     */
    public function get_default_filters(): array {
        return ['user:fullname'];
    }

    /**
     * Return the conditions added to the report when it is created.
     *
     * @return string[]
     */
    public function get_default_conditions(): array {
        return [];
    }
}
