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
 * Repeatable profile field definition.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Admin definition of a repeatable profile field.
 *
 * param1 holds the column schema as JSON ({@see \profilefield_repeatable\local\schema});
 * param2..param4 are unused since version 2 and cleared on save. defaultdata is always '{}'.
 *
 * @package    profilefield_repeatable
 */
class profile_define_repeatable extends profile_define_base {
    /**
     * Add elements for creating/editing a repeatable profile field.
     *
     * @param MoodleQuickForm $form
     */
    public function define_form_specific($form) {
        $form->addElement('textarea', 'param1', get_string('columns', 'profilefield_repeatable'), [
            'rows' => 12,
            'cols' => 60,
            'data-region' => 'profilefield-repeatable-schema',
            'class' => 'profilefield-repeatable-schema-json',
        ]);
        $form->setType('param1', PARAM_RAW);
        $form->addHelpButton('param1', 'columns', 'profilefield_repeatable');

        $form->addElement('hidden', 'defaultdata', '{}');
        $form->setType('defaultdata', PARAM_RAW_TRIMMED);
    }

    /**
     * Validate the submitted column schema.
     *
     * Every broken rule is listed under the schema ({@see \profilefield_repeatable\local\schema::validate()}).
     *
     * @param stdClass|array $data
     * @param array $files
     * @return array
     */
    public function define_validate_specific($data, $files) {
        $data = (object)$data;
        $document = \profilefield_repeatable\local\schema::decode((string)($data->param1 ?? ''));
        $domainexists = null;
        if (class_exists('\local_dictionaries\resolver')) {
            $domainexists = static fn(string $domain): bool => \local_dictionaries\resolver::domain_exists($domain);
        }

        $errors = \profilefield_repeatable\local\schema::validate($document, $domainexists);
        if (empty($errors)) {
            return [];
        }

        return ['param1' => implode('<br>', $errors)];
    }

    /**
     * Preprocess field configuration before save.
     *
     * Rewrites param1 as the canonical schema JSON, clears the version 1 parameters
     * param2..param4 and forces defaultdata to '{}'.
     *
     * @param array|stdClass $data
     * @return array|stdClass
     */
    public function define_save_preprocess($data) {
        $schema = \profilefield_repeatable\local\schema::from_json((string)($data->param1 ?? ''));
        if ($schema !== null) {
            $data->param1 = $schema->to_json();
        }
        $data->param2 = '';
        $data->param3 = '';
        $data->param4 = '';
        $data->defaultdata = '{}';

        return $data;
    }

    /**
     * Save repeatable field configuration and, on PostgreSQL, enqueue index reconciliation.
     *
     * @param array|stdClass $data
     */
    public function define_save($data) {
        global $DB;

        parent::define_save($data);
        if ($DB->get_dbfamily() !== 'postgres' || empty($data->id)) {
            return;
        }

        \profilefield_repeatable\task\reconcile_indexes::schedule_for_field((int)$data->id);
    }

    /**
     * Clean up PostgreSQL indexes after a repeatable field is deleted.
     *
     * Core never calls this: on a normal deletion the observer queues
     * {@see \profilefield_repeatable\task\reconcile_indexes}, which drops them. Call it for
     * immediate removal from a custom deletion workflow, outside any transaction.
     *
     * @param int $fieldid
     */
    public static function define_after_delete(int $fieldid): void {
        \profilefield_repeatable\task\reconcile_indexes::drop_all_for_field($fieldid);
    }
}
