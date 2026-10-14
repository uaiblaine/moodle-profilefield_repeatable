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
 * Repeatable profile field.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Profile field holding repeatable sets of sub-item values.
 *
 * The canonical value is one JSON object in user_info_data.data, keyed by column key
 * ({@see \profilefield_repeatable\local\schema}), e.g. {"@@ID#1": {"language": "pt", "level": "B2"}};
 * the per-set rows in profilefield_repeatable_data are a projection for Report Builder
 * ({@see \profilefield_repeatable\local\projection\projector}).
 *
 * @package    profilefield_repeatable
 */
class profile_field_repeatable extends profile_field_base {
    /** @var string Wrapper identifier used by AMD init. */
    protected $wrapperid = '';

    /** @var string Hidden element identifier used by AMD init. */
    protected $hiddenid = '';

    /** @var \profilefield_repeatable\local\schema|null Column schema, read once from the field record. */
    protected $schema = null;

    /**
     * Adds elements for this field type to the edit form.
     *
     * The value travels in a hidden input that the AMD module reads on load and rewrites from
     * the widget on submit. QuickForm prints no error for a hidden element, so the hidden input
     * is wrapped in a group carrying the field's input name: the group is a visible form row
     * placed under the widget, and a validation error keyed by the input name renders there.
     * The widget points at that error with aria-describedby.
     *
     * The columns reach the AMD module in a data attribute, not in the js_call_amd() arguments,
     * which core warns about past 1024 characters.
     *
     * @param moodleform $mform
     */
    public function edit_field_add($mform) {
        global $OUTPUT;

        $context = \core\context\system::instance();
        $schema = $this->get_schema();
        $columns = [];
        foreach ($schema->columns() as $column) {
            // The template and the module escape: format_string() must hand them the plain spelling.
            $entry = [
                'key' => $column['key'],
                'label' => format_string($schema->label($column['key']), true, ['context' => $context, 'escape' => false]),
                'mode' => $column['mode'],
                'required' => !empty($column['required']),
            ];
            $columns[] = $entry + $this->dictionary_widget_properties($column) + $this->pattern_widget_properties($column);
        }

        $html = $OUTPUT->render_from_template('profilefield_repeatable/edit_form', [
            'fieldlabel' => format_string($this->field->name, true, ['context' => $context, 'escape' => false]),
            'labelid' => $this->get_wrapper_id() . '-label',
            'errorid' => $this->get_error_id(),
            'wrapperid' => $this->get_wrapper_id(),
            'columns' => $columns,
            // The hex flags keep markup characters of a label out of the attribute, which the template escapes.
            'columnsjson' => json_encode(
                $columns,
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            ),
            'setcolumn' => get_string('setcolumn', 'profilefield_repeatable'),
            'actioncolumn' => get_string('actioncolumn', 'profilefield_repeatable'),
            'addnewset' => get_string('addnewset', 'profilefield_repeatable'),
        ]);

        $mform->addElement('html', $html);
        $mform->addGroup(
            [$mform->createElement('hidden', $this->inputname, '', ['id' => $this->get_hidden_id()])],
            $this->inputname,
            '',
            '',
            false
        );
        $mform->setType($this->inputname, PARAM_RAW_TRIMMED);
    }

    /**
     * Whether customfield_pattern offers the API pattern columns need.
     *
     * The one seam between this field and {@see \profilefield_repeatable\local\pattern_columns::available()},
     * so tests can run the field as if the plugin were absent: a pattern column is then plain text,
     * validated by nothing and stored as typed.
     *
     * @return bool
     */
    protected function pattern_available(): bool {
        return \profilefield_repeatable\local\pattern_columns::available();
    }

    /**
     * What the widget needs to offer a pattern column: the attributes of its text input.
     *
     * The attributes ({@see \customfield_pattern\api::input_attributes()}: the mask the
     * formatter reads, placeholder, inputmode, maxlength) ride in the columns JSON, because the
     * widget builds its inputs in JavaScript; the formatter listens on the document, so inputs
     * added with a new set are covered too ({@see self::edit_after_data()}).
     *
     * @param array $column Normalised schema column.
     * @return array Empty for a column of another mode, or without the plugin.
     */
    protected function pattern_widget_properties(array $column): array {
        $config = $this->get_schema()->pattern_config($column['key']);
        if ($config === null || !$this->pattern_available()) {
            return [];
        }
        return ['inputattributes' => \profilefield_repeatable\local\pattern_columns::input_attributes($config)];
    }

    /**
     * Whether the companion offers the API dictionary columns need on the edit form.
     *
     * The one seam between this field and {@see \profilefield_repeatable\helper::companion_lookup_available()},
     * so tests can run the field as if the companion were absent.
     *
     * @return bool
     */
    protected function companion_available(): bool {
        return \profilefield_repeatable\helper::companion_lookup_available();
    }

    /**
     * What the widget needs to offer a dictionary column through the companion's autocomplete.
     *
     * A dictionary column gets its domain, whether it accepts new codes ('open') and whether it
     * is searched ('autocomplete'): only when the current user may read the domain, and the
     * parent column's domain too for a dependent column ({@see self::can_read_domain()}), because
     * the search service refuses everyone else; the column is then typed as a code, as without
     * the companion. A column with a parent also gets the parent's key, domain and plain label.
     *
     * @param array $column Normalised schema column.
     * @return array Empty for a free column.
     */
    protected function dictionary_widget_properties(array $column): array {
        $domains = $this->get_schema()->domain_map();
        if (!isset($domains[$column['key']])) {
            return [];
        }

        $domain = $domains[$column['key']];
        $parent = $column['parent'] ?? '';
        $hasparent = $parent !== '' && isset($domains[$parent]);
        $properties = [
            'domain' => $domain,
            'open' => $column['mode'] === \profilefield_repeatable\local\schema::MODE_DICTIONARY_OPEN,
            'autocomplete' => $this->can_read_domain($domain) && (!$hasparent || $this->can_read_domain($domains[$parent])),
        ];
        if ($hasparent) {
            $properties['parent'] = $parent;
            $properties['parentdomain'] = $domains[$parent];
            $properties['parentlabel'] = format_string($this->get_schema()->label($parent), true, [
                'context' => \core\context\system::instance(),
                'escape' => false,
            ]);
        }

        return $properties;
    }

    /**
     * Whether the current (editing) user may search a domain, as the companion's search service decides.
     *
     * False without the companion's lookup-era API. Every code check and label that could tell a
     * user something about a dictionary they cannot read is decided on this.
     *
     * @param string $domain
     * @return bool
     */
    protected function can_read_domain(string $domain): bool {
        return $this->companion_available() && \local_dictionaries\api::can_read($domain);
    }

    /**
     * Labels of the codes a payload holds in the dictionary columns, for the widget.
     *
     * The widget shows each preloaded value as "label (code)", as the search does, without a
     * request. The payload is the stored one or, after a failed validation, the submitted one:
     * in a domain the editing user cannot read, only codes stored for the profile owner under
     * that key get a label, or submitting codes would read a restricted dictionary through the
     * ungated resolver. Labels are formatted and left unescaped; the widget escapes.
     *
     * @param string $payload Raw payload the form holds.
     * @return array Domain => [code => plain label]; codes without a label are absent.
     */
    protected function widget_labels(string $payload): array {
        if (!$this->companion_available()) {
            return [];
        }

        $schema = $this->get_schema();
        $domains = $schema->domain_map();
        $readable = [];
        foreach (array_unique($domains) as $domain) {
            $readable[$domain] = $this->can_read_domain($domain);
        }
        $stored = $this->get_validator()->stored_codes();
        $codes = [];
        foreach (\profilefield_repeatable\helper::normalise_payload($payload, $schema) as $set) {
            foreach ($domains as $key => $domain) {
                $code = trim((string)($set[$key] ?? ''));
                if ($code === '') {
                    continue;
                }
                if ($readable[$domain] || isset($stored[$key][\profilefield_repeatable\helper::fold_code($code)])) {
                    $codes[$domain][$code] = true;
                }
            }
        }

        $context = \core\context\system::instance();
        $labels = [];
        foreach ($codes as $domain => $list) {
            foreach (\local_dictionaries\resolver::resolve_bulk($domain, array_keys($list)) as $code => $label) {
                $labels[$domain][(string)$code] = format_string($label, true, ['context' => $context, 'escape' => false]);
            }
        }

        return $labels;
    }

    /**
     * Load user data for this profile field, ready for editing.
     *
     * Reads the canonical payload in user_info_data.data, never the per-set projection:
     * core's profile_save_custom_fields() can change the payload without touching it. Pattern
     * values are put in their displayed (masked) shape.
     *
     * @param stdClass $user
     */
    public function edit_load_user_data($user) {
        $payload = \profilefield_repeatable\helper::normalise_payload($this->data, $this->get_schema());
        $payload = $this->get_validator()->for_editing($payload);

        $user->{$this->inputname} = \profilefield_repeatable\helper::encode_payload($payload);
    }

    /**
     * Tweaks the edit form after data is set.
     *
     * @param MoodleQuickForm $mform
     * @return bool
     */
    public function edit_after_data($mform) {
        if (!parent::edit_after_data($mform)) {
            return false;
        }

        if (!$mform->elementExists($this->inputname)) {
            return false;
        }

        global $PAGE;

        // The labels of the values the form holds (stored, or submitted after a failed validation)
        // ride on the hidden input: js_call_amd() warns past 1024 characters.
        $group = $mform->getElement($this->inputname);
        if ($group instanceof \MoodleQuickForm_group) {
            foreach ($group->getElements() as $element) {
                if ($element->getName() === $this->inputname) {
                    $labels = $this->widget_labels((string)$element->getValue());
                    $element->updateAttributes([
                        'data-labels' => json_encode((object)$labels, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                    ]);
                }
            }
        }

        $config = [
            'wrapperid' => $this->get_wrapper_id(),
            'hiddenid' => $this->get_hidden_id(),
            'readonly' => $this->is_locked() && !has_capability('moodle/user:update', \core\context\system::instance()),
            'strings' => [
                'addnewset' => get_string('addnewset', 'profilefield_repeatable'),
                'removeset' => get_string('removeset', 'profilefield_repeatable'),
                'repeatableset' => get_string('repeatableset', 'profilefield_repeatable', '{no}'),
                'newcode' => get_string('newcode', 'profilefield_repeatable'),
                'searchplaceholder' => get_string('searchplaceholder', 'profilefield_repeatable'),
            ],
        ];

        $PAGE->requires->js_call_amd('profilefield_repeatable/repeatable', 'init', [$config]);
        if (!$config['readonly'] && $this->get_schema()->pattern_objects() && $this->pattern_available()) {
            // The formatter listens on the document, so it also formats the inputs of sets added later.
            \profilefield_repeatable\local\pattern_columns::js_init();
        }

        return true;
    }

    /**
     * Saves data coming from form and synchronises JSON-per-set rows.
     *
     * Replaces core's save so that user_info_data and the projection rows change in one delegated
     * transaction ({@see \profilefield_repeatable\local\projection\projector::sync()}).
     *
     * @param stdClass $usernew
     */
    public function edit_save_data($usernew) {
        global $DB;

        if (!isset($usernew->{$this->inputname})) {
            // Field not present in form, probably locked and invisible - skip it.
            return;
        }
        if ($this->is_locked() && !has_capability('moodle/user:update', \core\context\system::instance())) {
            // Defensive guard: ignore tampered submissions for locked fields.
            return;
        }

        $data = new stdClass();
        $processed = $this->edit_save_data_preprocess($usernew->{$this->inputname}, $data);
        if (!isset($processed)) {
            $processed = $this->field->defaultdata ?? '{}';
        }

        $payload = \profilefield_repeatable\helper::normalise_payload($processed, $this->get_schema());
        $fallbackjson = \profilefield_repeatable\helper::encode_payload($payload);

        $data->userid = (int)$usernew->id;
        $data->fieldid = (int)$this->field->id;
        $data->data = $fallbackjson;

        $transaction = $DB->start_delegated_transaction();

        $existingid = $DB->get_field('user_info_data', 'id', [
            'userid' => $data->userid,
            'fieldid' => $data->fieldid,
        ]);

        if ($existingid) {
            $data->id = (int)$existingid;
            $DB->update_record('user_info_data', $data);
            $dataid = (int)$existingid;
        } else {
            $dataid = (int)$DB->insert_record('user_info_data', $data);
        }

        \profilefield_repeatable\local\projection\projector::sync(
            new \profilefield_repeatable\local\source(),
            $dataid,
            (int)$data->fieldid,
            (int)$data->userid,
            $payload
        );

        $transaction->allow_commit();

        // Keep local instance data consistent for callers in same request.
        $this->data = $fallbackjson;
    }

    /**
     * Process incoming data before save: the canonical payload, pattern values normalised.
     *
     * Pattern values are stored as customfield_pattern stores them (fitted into the mask, masked
     * or raw per the column's "Store as"), also when a web service or CSV upload skips the
     * validation; a value that does not fit is stored trimmed, as typed.
     *
     * @param string|null $data
     * @param stdClass $datarecord
     * @return string
     */
    public function edit_save_data_preprocess($data, $datarecord) {
        $payload = \profilefield_repeatable\helper::normalise_payload($data, $this->get_schema());
        $payload = $this->get_validator()->for_storage($payload);
        return \profilefield_repeatable\helper::encode_payload($payload);
    }

    /**
     * Validate the form field from profile page.
     *
     * @param stdClass $usernew
     * @return array
     */
    public function edit_validate_field($usernew) {
        if (!property_exists($usernew, $this->inputname)) {
            return parent::edit_validate_field($usernew);
        }

        $validator = $this->get_validator();
        $rawpayload = $usernew->{$this->inputname};
        if (is_string($rawpayload)) {
            $rawerror = $validator->raw_error($rawpayload);
            if ($rawerror !== null) {
                return [$this->inputname => $rawerror];
            }
        }

        $payload = \profilefield_repeatable\helper::normalise_payload($rawpayload, $this->get_schema());
        $error = $validator->error($payload, (bool)$this->is_required());
        if ($error !== null) {
            return [$this->inputname => $error];
        }

        // The parent's uniqueness check compares this value with stored data: give it the canonical
        // JSON as it would be stored, or '' so that an empty optional value is not checked.
        $payload = $validator->for_storage($payload);
        $usernew->{$this->inputname} = empty($payload) ? '' : \profilefield_repeatable\helper::encode_payload($payload);

        return parent::edit_validate_field($usernew);
    }

    /**
     * Check if the field data is considered empty.
     *
     * @return bool
     */
    public function is_empty() {
        return empty(\profilefield_repeatable\helper::normalise_payload($this->data, $this->get_schema()));
    }

    /**
     * Prevent core from rendering this field inline in the "contact" category.
     *
     * Repeatable fields are rendered as a dedicated widget via
     * {@see profilefield_repeatable_myprofile_navigation()} instead. Returning false also leaves
     * the field out of the customfields that user_get_user_details() returns.
     *
     * @param \context|null $context
     * @return bool Always false.
     */
    public function show_field_content(?\context $context = null): bool {
        return false;
    }

    /**
     * Display the data for this field.
     *
     * @return string
     */
    public function display_data() {
        $renderer = new \profilefield_repeatable\output\display_renderer(
            $this->field,
            (int)($this->userid ?? 0),
            (string)$this->data
        );
        return $renderer->render();
    }

    /**
     * Return the field type and null properties.
     *
     * @return array
     */
    public function get_field_properties() {
        return [PARAM_RAW, NULL_NOT_ALLOWED];
    }

    /**
     * The field's column schema, version 2 or a version 1 field read through its labels.
     *
     * @return \profilefield_repeatable\local\schema
     */
    protected function get_schema(): \profilefield_repeatable\local\schema {
        if ($this->schema === null) {
            $this->schema = \profilefield_repeatable\local\field_schema::from_field($this->field ?? new stdClass());
        }

        return $this->schema;
    }

    /**
     * The engine validator of this field, deciding with what the profile adapter knows.
     *
     * Built on every call, so it always holds the payload this instance has as stored: the one the
     * constructor loaded for the user being edited, never a submission. Who may read a dictionary
     * is {@see self::can_read_domain()}; the companion and customfield_pattern count as present
     * per the test seams {@see self::companion_available()} and {@see self::pattern_available()}.
     *
     * @return \profilefield_repeatable\local\validator
     */
    protected function get_validator(): \profilefield_repeatable\local\validator {
        return new \profilefield_repeatable\local\validator(
            $this->get_schema(),
            (string)($this->data ?? ''),
            fn(string $domain): bool => $this->can_read_domain($domain),
            $this->companion_available(),
            $this->pattern_available()
        );
    }

    /**
     * Get wrapper identifier.
     *
     * @return string
     */
    protected function get_wrapper_id(): string {
        if ($this->wrapperid === '') {
            $shortname = clean_param($this->field->shortname, PARAM_ALPHANUMEXT);
            $fieldid = (int)$this->field->id;
            $userid = (int)($this->userid ?? 0);
            $this->wrapperid = 'profilefield-repeatable-' . $fieldid . '-' . $userid . '-' . $shortname;
        }

        return $this->wrapperid;
    }

    /**
     * Get the id of the error region core renders for the group carrying the input name.
     *
     * Mirrors the renderer: the group's id is "fgroup_id_<name>" and its error region swaps
     * "_id_" for "_id_error_" ({@see \templatable_form_element}).
     *
     * @return string
     */
    protected function get_error_id(): string {
        return 'fgroup_id_error_' . $this->inputname;
    }

    /**
     * Get hidden element identifier.
     *
     * @return string
     */
    protected function get_hidden_id(): string {
        if ($this->hiddenid === '') {
            $this->hiddenid = 'id_' . $this->inputname;
        }

        return $this->hiddenid;
    }
}
