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
 * param1 holds the column schema as JSON ({@see \profilefield_repeatable\local\schema}), edited
 * through the column editor (amd/src/columneditor.js) or, without JavaScript, as text;
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
     * The submitted schema, normalised but with every column kept, so errors name what was typed.
     *
     * @param string $json
     * @return \profilefield_repeatable\local\schema|null Null when the text is not a version 2 schema.
     */
    private function submitted_schema(string $json): ?\profilefield_repeatable\local\schema {
        $document = \profilefield_repeatable\local\schema::decode($json);
        if ($document === null || !is_array($document['columns'] ?? null)) {
            return null;
        }
        return \profilefield_repeatable\local\schema::from_columns(array_values($document['columns']));
    }

    /**
     * Hand the column editor what it needs: locked keys, the users with data and the domains.
     *
     * Everything travels in data attributes of the schema textarea, not in the js_call_amd()
     * arguments, which core warns about past 1024 characters. The locked keys come from the
     * stored field, never from the submitted schema; {@see self::define_validate_specific()}
     * enforces them. A field still on the version 1 parameters is shown as its version 2 schema.
     *
     * @param MoodleQuickForm $mform
     */
    public function define_after_data(&$mform) {
        global $PAGE;

        if (!$mform->elementExists('param1')) {
            return;
        }

        $element = $mform->getElement('param1');
        $usage = ['users' => 0, 'keys' => []];
        $stored = $this->stored_field((int)$mform->getElementValue('id'));
        if ($stored) {
            $usage = \profilefield_repeatable\local\stored_values::keys_in_use(
                (int)$stored->id,
                \profilefield_repeatable\local\schema::from_field($stored)
            );
            $isv1 = \profilefield_repeatable\local\schema::decode((string)$stored->param1) === null;
            if ($isv1 && trim((string)$element->getValue()) === trim((string)$stored->param1)) {
                $element->setValue(\profilefield_repeatable\local\schema::from_field($stored)->to_json());
            }
        }

        // Shown pretty-printed, so the schema stays editable without JavaScript.
        $schema = $this->submitted_schema((string)$element->getValue());
        if ($schema !== null) {
            $element->setValue(json_encode(
                $schema->to_document(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ));
        }

        $domains = \profilefield_repeatable\helper::companion_domains();
        $pattern = $this->pattern_available();
        $element->updateAttributes([
            'data-lockedkeys' => json_encode($usage['keys']),
            'data-users' => $usage['users'],
            'data-domains' => json_encode($domains ?? [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'data-companion' => $domains === null ? 0 : 1,
            // The pattern mode is offered only with customfield_pattern; its presets come from there.
            'data-pattern' => $pattern ? 1 : 0,
            'data-presets' => json_encode(
                $pattern ? \profilefield_repeatable\local\pattern_columns::presets() : [],
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            ),
        ]);

        $PAGE->requires->js_call_amd('profilefield_repeatable/columneditor', 'init');
    }

    /**
     * Whether pattern columns can be configured: customfield_pattern offers its API.
     *
     * The one seam between the definition and {@see \profilefield_repeatable\local\pattern_columns::available()},
     * so tests can run it as if the plugin were absent.
     *
     * @return bool
     */
    protected function pattern_available(): bool {
        return \profilefield_repeatable\local\pattern_columns::available();
    }

    /**
     * Validate the submitted column schema.
     *
     * Every broken rule is listed under the schema, which the column editor shows with the
     * error region of the form row ({@see \profilefield_repeatable\local\schema::validate()}).
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
        $document = \profilefield_repeatable\local\schema::decode((string)($data->param1 ?? ''));
        $domainexists = null;
        if (class_exists('\local_dictionaries\resolver')) {
            $domainexists = static fn(string $domain): bool => \local_dictionaries\resolver::domain_exists($domain);
        }

        $lockedlabels = [];
        $storedpatterns = [];
        $stored = $this->stored_field((int)($data->id ?? 0));
        if ($stored) {
            $context = \core\context\system::instance();
            foreach (\profilefield_repeatable\local\stored_values::locked_labels($stored) as $key => $label) {
                $lockedlabels[$key] = format_string($label, true, ['context' => $context, 'escape' => false]);
            }
            $storedpatterns = \profilefield_repeatable\local\schema::from_field($stored)->pattern_objects();
        }
        $patternerrors = null;
        if ($this->pattern_available()) {
            $patternerrors = [\profilefield_repeatable\local\pattern_columns::class, 'config_errors'];
        }

        // Domain => the domain holding its parents, where the companion knows it.
        $parentdomains = [];
        foreach (\profilefield_repeatable\helper::companion_domains() ?? [] as $domain) {
            if ($domain['parentdomain'] !== null && $domain['parentdomain'] !== '') {
                $parentdomains[$domain['shortname']] = $domain['parentdomain'];
            }
        }

        $errors = \profilefield_repeatable\local\schema::validate(
            $document,
            $domainexists,
            $lockedlabels,
            $parentdomains,
            $patternerrors,
            $storedpatterns
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
        $schema = $this->submitted_schema((string)($data->param1 ?? ''));
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
     * Saving a field still on the version 1 parameters converts it as the upgrade does: its
     * key => label map is written before param1 changes, so the label-keyed payloads keep
     * reading, and {@see \profilefield_repeatable\task\rekey_payloads} re-keys them.
     *
     * @param array|stdClass $data
     */
    public function define_save($data) {
        global $DB;

        $stored = $this->stored_field((int)($data->id ?? 0));
        $convert = $stored && \profilefield_repeatable\local\schema::decode((string)$stored->param1) === null;
        if ($convert) {
            $legacy = \profilefield_repeatable\local\schema::from_field($stored)->legacy_labels();
            set_config(
                \profilefield_repeatable\local\schema::legacy_config_name((int)$stored->id),
                json_encode((object)$legacy, JSON_UNESCAPED_UNICODE),
                'profilefield_repeatable'
            );
        }

        parent::define_save($data);
        if ($convert) {
            \profilefield_repeatable\task\rekey_payloads::queue_for_field((int)$stored->id);
        }
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
