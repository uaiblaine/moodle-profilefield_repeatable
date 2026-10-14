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
 * param1 holds the column schema as JSON ({@see \local_repeatable\local\schema}), edited
 * through the engine's column editor (AMD local_repeatable/columneditor) or, without JavaScript, as text;
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
        $form->addElement(
            'textarea',
            'param1',
            get_string('columns', 'profilefield_repeatable'),
            \local_repeatable\form\column_editor::element_attributes()
        );
        $form->setType('param1', PARAM_RAW);
        $form->addHelpButton('param1', 'columns', 'profilefield_repeatable');

        $form->addElement('hidden', 'defaultdata', '{}');
        $form->setType('defaultdata', PARAM_RAW_TRIMMED);
    }

    /**
     * The stored repeatable field a submitted id names, or null.
     *
     * @param int $fieldid
     * @return stdClass|null
     */
    private function stored_field(int $fieldid): ?stdClass {
        global $DB;

        if ($fieldid <= 0) {
            return null;
        }
        return $DB->get_record('user_info_field', ['id' => $fieldid, 'datatype' => 'repeatable']) ?: null;
    }

    /**
     * Hand the column editor what it needs: locked keys, the users with data and the domains.
     *
     * The engine's {@see \local_repeatable\form\column_editor} builds the textarea's data
     * attributes; this adapter reads the locked keys from the stored field, never from the
     * submitted schema, and {@see self::define_validate_specific()} enforces them. A field still on
     * the version 1 parameters is shown as its version 2 schema.
     *
     * @param MoodleQuickForm $mform
     */
    public function define_after_data(&$mform) {
        if (!$mform->elementExists('param1')) {
            return;
        }

        $element = $mform->getElement('param1');
        $usage = ['users' => 0, 'keys' => []];
        $stored = $this->stored_field((int)$mform->getElementValue('id'));
        if ($stored) {
            $usage = \profilefield_repeatable\local\stored_values::usage(
                (int)$stored->id,
                \profilefield_repeatable\local\field_schema::from_field($stored)
            );
            $isv1 = \local_repeatable\local\schema::decode((string)$stored->param1) === null;
            if ($isv1 && trim((string)$element->getValue()) === trim((string)$stored->param1)) {
                $element->setValue(\profilefield_repeatable\local\field_schema::from_field($stored)->to_json());
            }
        }

        $pretty = \local_repeatable\form\column_editor::pretty((string)$element->getValue());
        if ($pretty !== null) {
            $element->setValue($pretty);
        }

        $element->updateAttributes(\local_repeatable\form\column_editor::attributes($usage, $this->pattern_available()));
        \local_repeatable\form\column_editor::init_js();
    }

    /**
     * Whether pattern columns can be configured: customfield_pattern offers its API.
     *
     * The one seam between the definition and {@see \local_repeatable\local\bridge\pattern::available()},
     * so tests can run it as if the plugin were absent.
     *
     * @return bool
     */
    protected function pattern_available(): bool {
        return \local_repeatable\local\bridge\pattern::available();
    }

    /**
     * Validate the submitted column schema.
     *
     * Every broken rule is listed under the schema, which the column editor shows with the
     * error region of the form row ({@see \local_repeatable\form\column_editor::errors()}).
     * The keys holding values are read from the stored field's payloads, whatever the editor
     * sent: a schema that drops one is rejected. Pattern columns are checked by customfield_pattern;
     * without it, only the stored field's pattern columns may be submitted, unchanged.
     *
     * @param stdClass|array $data
     * @param array $files
     * @return array
     */
    public function define_validate_specific($data, $files) {
        $data = (object)$data;
        $document = \local_repeatable\local\schema::decode((string)($data->param1 ?? ''));

        $lockedlabels = [];
        $storedpatterns = [];
        $stored = $this->stored_field((int)($data->id ?? 0));
        if ($stored) {
            $lockedlabels = \profilefield_repeatable\local\stored_values::locked_labels($stored);
            $storedpatterns = \profilefield_repeatable\local\field_schema::from_field($stored)->pattern_objects();
        }

        $errors = \local_repeatable\form\column_editor::errors(
            $document,
            $lockedlabels,
            $storedpatterns,
            $this->pattern_available()
        );
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
        $canonical = \local_repeatable\form\column_editor::canonical((string)($data->param1 ?? ''));
        if ($canonical !== null) {
            $data->param1 = $canonical;
        }
        $data->param2 = '';
        $data->param3 = '';
        $data->param4 = '';
        $data->defaultdata = '{}';

        return $data;
    }

    /**
     * Save repeatable field configuration.
     *
     * Saving a field still on the version 1 parameters converts it: its key => label map is
     * written before param1 changes, so the label-keyed payloads keep reading, and
     * {@see \profilefield_repeatable\task\rekey_payloads} re-keys them.
     *
     * @param array|stdClass $data
     */
    public function define_save($data) {
        $stored = $this->stored_field((int)($data->id ?? 0));
        $convert = $stored && \local_repeatable\local\schema::decode((string)$stored->param1) === null;
        if ($convert) {
            $legacy = \profilefield_repeatable\local\field_schema::from_field($stored)->legacy_labels();
            set_config(
                \profilefield_repeatable\local\field_schema::legacy_config_name((int)$stored->id),
                json_encode((object)$legacy, JSON_UNESCAPED_UNICODE),
                'profilefield_repeatable'
            );
        }

        parent::define_save($data);
        if ($convert) {
            \profilefield_repeatable\task\rekey_payloads::queue_for_field((int)$stored->id);
        }
    }
}
