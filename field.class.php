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
 * @copyright  2026 Anderson Blaine (anderson@blaine.com.br)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Class profile_field_repeatable.
 *
 * @package    profilefield_repeatable
 */
class profile_field_repeatable extends profile_field_base {
    /** @var string Wrapper identifier used by AMD init. */
    protected $wrapperid = '';

    /** @var string Hidden element identifier used by AMD init. */
    protected $hiddenid = '';

    /**
     * Adds elements for this field type to the edit form.
     *
     * The value travels in a hidden input that the AMD module keeps in step with the widget.
     * QuickForm prints no error for a hidden element, so the hidden input is wrapped in a
     * group carrying the field's input name: the group is a visible form row placed under
     * the widget, and a validation error keyed by the input name renders there. The widget
     * points at that error with aria-describedby.
     *
     * @param moodleform $mform
     */
    public function edit_field_add($mform) {
        global $OUTPUT;

        $context = \core\context\system::instance();
        $subitems = $this->get_subitems();
        $subitemdata = [];
        foreach ($subitems as $subitem) {
            $subitemdata[] = ['name' => format_string($subitem, true, ['context' => $context])];
        }

        $html = $OUTPUT->render_from_template('profilefield_repeatable/edit_form', [
            'fieldlabel' => format_string($this->field->name),
            'labelid' => $this->get_wrapper_id() . '-label',
            'errorid' => $this->get_error_id(),
            'wrapperid' => $this->get_wrapper_id(),
            'subitems' => $subitemdata,
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
     * Load user data for this profile field, ready for editing.
     *
     * Reads the canonical payload in user_info_data.data, never the per-set projection:
     * core's profile_save_custom_fields() can change the payload without touching it.
     *
     * @param stdClass $user
     */
    public function edit_load_user_data($user) {
        $payload = \profilefield_repeatable\helper::normalise_payload($this->data, $this->get_subitems());

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

        $config = [
            'wrapperid' => $this->get_wrapper_id(),
            'hiddenid' => $this->get_hidden_id(),
            'subitems' => $this->get_subitems(),
            'readonly' => $this->is_locked() && !has_capability('moodle/user:update', \core\context\system::instance()),
            'strings' => [
                'addnewset' => get_string('addnewset', 'profilefield_repeatable'),
                'removeset' => get_string('removeset', 'profilefield_repeatable'),
                'repeatableset' => get_string('repeatableset', 'profilefield_repeatable', '{no}'),
            ],
        ];

        $PAGE->requires->js_call_amd('profilefield_repeatable/repeatable', 'init', [$config]);

        return true;
    }

    /**
     * Saves data coming from form and synchronises JSON-per-set rows.
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

        $payload = \profilefield_repeatable\helper::normalise_payload($processed, $this->get_subitems());
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

        \profilefield_repeatable\local\projection::sync($dataid, (int)$data->fieldid, (int)$data->userid, $payload);

        $transaction->allow_commit();

        // Keep local instance data consistent for callers in same request.
        $this->data = $fallbackjson;
    }

    /**
     * Process incoming data before save.
     *
     * @param string|null $data
     * @param stdClass $datarecord
     * @return string
     */
    public function edit_save_data_preprocess($data, $datarecord) {
        $payload = \profilefield_repeatable\helper::normalise_payload($data, $this->get_subitems());
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

        $rawpayload = $usernew->{$this->inputname};
        if (is_string($rawpayload)) {
            $rawpayload = trim($rawpayload);
            if ($rawpayload === '') {
                $rawpayload = '{}';
            }
            if (!$this->is_valid_json_object($rawpayload)) {
                return [$this->inputname => get_string('errorinvalidjson', 'profilefield_repeatable')];
            }

            $limiterror = $this->validate_payload_limits(json_decode($rawpayload, true));
            if ($limiterror !== null) {
                return [$this->inputname => $limiterror];
            }
        }

        $payload = \profilefield_repeatable\helper::normalise_payload($rawpayload, $this->get_subitems());
        if ($this->is_required() && empty($payload)) {
            return [$this->inputname => get_string('required')];
        }

        // Keep parent uniqueness check semantics.
        $usernew->{$this->inputname} = empty($payload) ? '' : \profilefield_repeatable\helper::encode_payload($payload);

        return parent::edit_validate_field($usernew);
    }

    /**
     * Check raw payload against the configured set-count and value-length limits.
     *
     * Runs on the raw decoded JSON because normalisation silently enforces the
     * same caps; validating after normalising could never see the excess.
     *
     * @param mixed $decoded Raw decoded JSON payload.
     * @return string|null Error message, or null when within limits.
     */
    protected function validate_payload_limits($decoded): ?string {
        if (!is_array($decoded)) {
            return null;
        }

        if (count($decoded) > \profilefield_repeatable\helper::MAX_SETS) {
            return get_string(
                'errortoomanysets',
                'profilefield_repeatable',
                \profilefield_repeatable\helper::MAX_SETS
            );
        }

        $subitems = array_fill_keys($this->get_subitems(), true);
        foreach ($decoded as $setdata) {
            if (!is_array($setdata)) {
                continue;
            }
            foreach ($setdata as $subitem => $value) {
                if (!isset($subitems[$subitem]) || !is_scalar($value)) {
                    continue;
                }
                if (core_text::strlen((string)$value) > \profilefield_repeatable\helper::MAX_VALUE_LENGTH) {
                    return get_string(
                        'errorvaluetoolong',
                        'profilefield_repeatable',
                        \profilefield_repeatable\helper::MAX_VALUE_LENGTH
                    );
                }
            }
        }

        return null;
    }

    /**
     * Check if the field data is considered empty.
     *
     * @return bool
     */
    public function is_empty() {
        return empty(\profilefield_repeatable\helper::normalise_payload($this->data, $this->get_subitems()));
    }

    /**
     * Prevent core from rendering this field inline in the "contact" category.
     *
     * Repeatable fields are rendered as a dedicated widget via
     * {@see profilefield_repeatable_myprofile_navigation()} instead.
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
     * Normalise payload for the current field configuration.
     *
     * @param mixed $rawpayload
     * @return array
     */
    private function normalise_payload($rawpayload): array {
        return \profilefield_repeatable\helper::normalise_payload($rawpayload, $this->get_subitems());
    }

    /**
     * Get configured sub-items.
     *
     * @return string[]
     */
    protected function get_subitems(): array {
        $rawsubitems = (string)($this->field->param1 ?? '');
        return \profilefield_repeatable\helper::parse_subitems($rawsubitems);
    }

    /**
     * Validate if provided value is a JSON object.
     *
     * @param string $rawpayload
     * @return bool
     */
    protected function is_valid_json_object(string $rawpayload): bool {
        $decoded = json_decode($rawpayload);
        return json_last_error() === JSON_ERROR_NONE && is_object($decoded);
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
