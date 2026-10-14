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
 * ({@see \local_repeatable\local\schema}), e.g. {"@@ID#1": {"language": "pt", "level": "B2"}};
 * the per-set rows in profilefield_repeatable_data are a projection for Report Builder
 * ({@see \local_repeatable\local\projection\projector}).
 *
 * @package    profilefield_repeatable
 */
class profile_field_repeatable extends profile_field_base {
    /** @var string Wrapper identifier used by AMD init. */
    protected $wrapperid = '';

    /** @var string Hidden element identifier used by AMD init. */
    protected $hiddenid = '';

    /** @var \local_repeatable\local\schema|null Column schema, read once from the field record. */
    protected $schema = null;

    /**
     * Adds elements for this field type to the edit form.
     *
     * The value travels in a hidden input that the AMD module reads on load and rewrites from
     * the widget on submit. QuickForm prints no error for a hidden element, so the hidden input
     * is wrapped in a group carrying the field's input name: the group is a visible form row
     * placed under the widget, and a validation error keyed by the input name renders there.
     * The widget points at that error with aria-describedby. The widget itself is the engine's
     * ({@see \local_repeatable\form\widget}).
     *
     * @param moodleform $mform
     */
    public function edit_field_add($mform) {
        $html = $this->get_widget()->render((string)$this->field->name, $this->widget_ids());

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
     * The one seam between this field and {@see \local_repeatable\local\bridge\pattern::available()},
     * so tests can run the field as if the plugin were absent: a pattern column is then plain text,
     * validated by nothing and stored as typed.
     *
     * @return bool
     */
    protected function pattern_available(): bool {
        return \local_repeatable\local\bridge\pattern::available();
    }

    /**
     * Whether the companion offers the API dictionary columns need on the edit form.
     *
     * The one seam between this field and {@see \local_repeatable\local\bridge\dictionaries::lookup_available()},
     * so tests can run the field as if the companion were absent.
     *
     * @return bool
     */
    protected function companion_available(): bool {
        return \local_repeatable\local\bridge\dictionaries::lookup_available();
    }

    /**
     * Whether the current (editing) user may search a domain, as the companion's search service decides.
     *
     * False without the companion's lookup-era API. Every code check and label that could tell a
     * user something about a dictionary they cannot read is decided on this: the engine's
     * validator and widget get it as their "can read" callable.
     *
     * @param string $domain
     * @return bool
     */
    protected function can_read_domain(string $domain): bool {
        return $this->companion_available() && \local_dictionaries\api::can_read($domain);
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
        $payload = \local_repeatable\local\payload::normalise($this->data, $this->get_schema());
        $payload = $this->get_validator()->for_editing($payload);

        $user->{$this->inputname} = \local_repeatable\local\payload::encode($payload);
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

        // The labels of the values the form holds (stored, or submitted after a failed validation)
        // ride on the hidden input: js_call_amd() warns past 1024 characters.
        $group = $mform->getElement($this->inputname);
        if ($group instanceof \MoodleQuickForm_group) {
            foreach ($group->getElements() as $element) {
                if ($element->getName() === $this->inputname) {
                    $labels = $this->get_widget()->labels((string)$element->getValue(), $this->get_validator()->stored_codes());
                    $element->updateAttributes([
                        'data-labels' => json_encode((object)$labels, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                    ]);
                }
            }
        }

        $readonly = $this->is_locked() && !has_capability('moodle/user:update', \core\context\system::instance());
        $this->get_widget()->init_js($this->widget_ids(), $readonly);

        return true;
    }

    /**
     * Saves data coming from form and synchronises JSON-per-set rows.
     *
     * Replaces core's save so that user_info_data and the projection rows change in one delegated
     * transaction ({@see \local_repeatable\local\projection\projector::sync()}).
     *
     * Web services (core_user_create_users, core_user_update_users) reach this method without
     * {@see self::edit_validate_field()}: on such a request the form's checks run here too
     * ({@see self::submitted_error()}, see {@see self::save_checks_apply()}), with the payload stored
     * for the user and the current user's dictionary rights, so no user-driven path stores a code the
     * editing user could not have submitted. A submission that fails them is refused with one message
     * that names the field and nothing submitted. Login-time account creation and updates
     * (create_user_record(), update_user_record_by_id(), auth_oauth2) and the auth_db and auth_ldap
     * account creation reach this method too, with the value of the external source an administrator
     * configured: it is normalised and stored without the checks, so a value from the provider can
     * never lock a user out or abort a sync. The auth_db and auth_ldap attribute update step calls
     * core's profile_save_custom_fields(), which does not reach this class at all.
     *
     * @param stdClass $usernew
     * @throws \moodle_exception When the submitted value fails the field's checks.
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

        $submitted = [];
        if ($this->save_checks_apply() && $this->submitted_error($usernew->{$this->inputname}, $submitted) !== null) {
            throw new \moodle_exception('errorsaverejected', 'profilefield_repeatable', '', format_string(
                (string)$this->field->name,
                true,
                ['context' => \core\context\system::instance()]
            ));
        }

        $data = new stdClass();
        $processed = $this->edit_save_data_preprocess($usernew->{$this->inputname}, $data);
        if (!isset($processed)) {
            $processed = $this->field->defaultdata ?? '{}';
        }

        $payload = \local_repeatable\local\payload::normalise($processed, $this->get_schema());
        $fallbackjson = \local_repeatable\local\payload::encode($payload);

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

        \local_repeatable\local\projection\projector::sync(
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
     * Whether edit_save_data() runs the form's checks: on a web service request or an AJAX call of a logged-in user.
     *
     * Those paths (core_user_create_users, core_user_update_users, which is also an AJAX service) reach
     * edit_save_data() without edit_validate_field(), and their caller is a user whose dictionary rights
     * count. The other callers of profile_save_data() are non-interactive and never ran the form: first
     * login and on-login updates of an external-auth user, auth_oauth2, and the auth_db and auth_ldap
     * account creation. Their value comes from the identity provider an administrator configured, and
     * login has no logged-in user (login/token.php is an AJAX script too), so refusing there would only
     * lock the user out or abort the sync for every remaining user. The form validated the same inputs.
     *
     * @return bool
     */
    protected function save_checks_apply(): bool {
        $request = (defined('WS_SERVER') && WS_SERVER) || (defined('AJAX_SCRIPT') && AJAX_SCRIPT);
        return $request && isloggedin() && !isguestuser();
    }

    /**
     * Process incoming data before save: the canonical payload, pattern values normalised.
     *
     * Pattern values are stored as customfield_pattern stores them (fitted into the mask, masked
     * or raw per the column's "Store as"), also when a web service, login or sync skips the
     * validation; a value that does not fit is stored trimmed, as typed.
     *
     * @param string|null $data
     * @param stdClass $datarecord
     * @return string
     */
    public function edit_save_data_preprocess($data, $datarecord) {
        $payload = \local_repeatable\local\payload::normalise($data, $this->get_schema());
        $payload = $this->get_validator()->for_storage($payload);
        return \local_repeatable\local\payload::encode($payload);
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

        $payload = [];
        $error = $this->submitted_error($usernew->{$this->inputname}, $payload);
        if ($error !== null) {
            return [$this->inputname => $error];
        }

        // The parent's uniqueness check compares this value with stored data: give it the canonical
        // JSON as it would be stored, or '' so that an empty optional value is not checked.
        $payload = $this->get_validator()->for_storage($payload);
        $usernew->{$this->inputname} = empty($payload) ? '' : \local_repeatable\local\payload::encode($payload);

        return parent::edit_validate_field($usernew);
    }

    /**
     * The checks the form and the web service path run on a submitted value: the raw JSON caps, then the validator.
     *
     * Shared by {@see self::edit_validate_field()} and {@see self::edit_save_data()} so the form and
     * the web service path that skips it cannot drift. The validator holds the
     * payload stored for the user this object was built for ({@see self::get_validator()}), never the
     * submission, and decides dictionary codes on {@see self::can_read_domain()} of the current user.
     *
     * @param mixed $raw The submitted value.
     * @param array $payload Set to the normalised payload.
     * @return string|null The error text, null when the value passes.
     */
    private function submitted_error($raw, array &$payload): ?string {
        $validator = $this->get_validator();
        if (is_string($raw)) {
            $rawerror = $validator->raw_error($raw);
            if ($rawerror !== null) {
                return $rawerror;
            }
        }

        $payload = \local_repeatable\local\payload::normalise($raw, $this->get_schema());
        return $validator->error($payload, (bool)$this->is_required());
    }

    /**
     * Check if the field data is considered empty.
     *
     * @return bool
     */
    public function is_empty() {
        return empty(\local_repeatable\local\payload::normalise($this->data, $this->get_schema()));
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
     * @return \local_repeatable\local\schema
     */
    protected function get_schema(): \local_repeatable\local\schema {
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
     * @return \local_repeatable\local\validator
     */
    protected function get_validator(): \local_repeatable\local\validator {
        return new \local_repeatable\local\validator(
            $this->get_schema(),
            (string)($this->data ?? ''),
            fn(string $domain): bool => $this->can_read_domain($domain),
            $this->companion_available(),
            $this->pattern_available()
        );
    }

    /**
     * The engine widget of this field, deciding with what the profile adapter knows.
     *
     * @return \local_repeatable\form\widget
     */
    protected function get_widget(): \local_repeatable\form\widget {
        return new \local_repeatable\form\widget(
            $this->get_schema(),
            fn(string $domain): bool => $this->can_read_domain($domain),
            $this->companion_available(),
            $this->pattern_available()
        );
    }

    /**
     * The ids the widget needs: its wrapper, the hidden input and the error region of its group.
     *
     * @return array
     */
    protected function widget_ids(): array {
        return [
            'wrapperid' => $this->get_wrapper_id(),
            'hiddenid' => $this->get_hidden_id(),
            'errorid' => $this->get_error_id(),
        ];
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
