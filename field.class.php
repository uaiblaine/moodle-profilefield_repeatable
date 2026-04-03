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

defined('MOODLE_INTERNAL') || die();

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
     * @param moodleform $mform
     */
    public function edit_field_add($mform) {
        global $OUTPUT;

        $mform->addElement('hidden', $this->inputname, '', ['id' => $this->get_hidden_id()]);
        $mform->setType($this->inputname, PARAM_RAW_TRIMMED);

        $context = \core\context\system::instance();
        $subitems = $this->get_subitems();
        $subitemdata = [];
        foreach ($subitems as $subitem) {
            $subitemdata[] = ['name' => format_string($subitem, true, ['context' => $context])];
        }

        $html = $OUTPUT->render_from_template('profilefield_repeatable/edit_form', [
            'fieldlabel' => format_string($this->field->name),
            'wrapperid' => $this->get_wrapper_id(),
            'subitems' => $subitemdata,
            'setcolumn' => get_string('setcolumn', 'profilefield_repeatable'),
            'actioncolumn' => get_string('actioncolumn', 'profilefield_repeatable'),
            'addnewset' => get_string('addnewset', 'profilefield_repeatable'),
        ]);

        $mform->addElement('html', $html);
    }

    /**
     * Load user data for this profile field, ready for editing.
     *
     * Source of truth: profilefield_repeatable_data; fallback: user_info_data.data.
     *
     * @param stdClass $user
     */
    public function edit_load_user_data($user) {
        $payload = $this->get_payload_from_storage();
        if (empty($payload)) {
            $payload = \profilefield_repeatable\helper::normalise_payload($this->data, $this->get_subitems());
        }

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

        $this->sync_repeat_data($dataid, (int)$data->userid, $payload);

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
     * Check if the field data is considered empty.
     *
     * @return bool
     */
    public function is_empty() {
        $payload = $this->get_payload_from_storage();
        if (empty($payload)) {
            $payload = \profilefield_repeatable\helper::normalise_payload($this->data, $this->get_subitems());
        }

        return empty($payload);
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
            (string)$this->data,
            fn(): bool => $this->has_storage_table()
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
     * Resolve payload from storage table for the current user.
     *
     * @return array
     */
    protected function get_payload_from_storage(): array {
        global $DB;

        if (!$this->has_storage_table() || empty($this->userid) || empty($this->field) || empty($this->field->id)) {
            return [];
        }

        $dataid = $DB->get_field('user_info_data', 'id', [
            'userid' => (int)$this->userid,
            'fieldid' => (int)$this->field->id,
        ]);

        return $dataid ? $this->get_payload_from_dataid((int)$dataid) : [];
    }

    /**
     * Resolve payload from storage table by data id.
     *
     * @param int $dataid
     * @return array
     */
    protected function get_payload_from_dataid(int $dataid): array {
        global $DB;

        $records = $DB->get_records(
            'profilefield_repeatable_data',
            ['dataid' => $dataid],
            'set_id ASC, id ASC',
            'id, set_id, data'
        );

        if (empty($records)) {
            return [];
        }

        $allowedsubitems = array_fill_keys($this->get_subitems(), true);
        $rawsets = [];

        foreach ($records as $record) {
            $setid = (int)$record->set_id;
            if ($setid <= 0) {
                continue;
            }

            $decoded = json_decode((string)$record->data, true);
            if (!is_array($decoded)) {
                continue;
            }

            if (!empty($allowedsubitems)) {
                $decoded = array_intersect_key($decoded, $allowedsubitems);
            }

            $rawsets[$setid] = $decoded;
        }

        if (empty($rawsets)) {
            return [];
        }

        ksort($rawsets);
        return \profilefield_repeatable\helper::normalise_payload($rawsets, $this->get_subitems());
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
     * Synchronise JSON-per-set rows for one user_info_data.id.
     *
     * @param int $dataid
     * @param int $userid
     * @param array $payload
     */
    protected function sync_repeat_data(int $dataid, int $userid, array $payload): void {
        global $DB;

        if (!$this->has_storage_table()) {
            return;
        }

        $fieldid = (int)$this->field->id;
        $now = time();
        $persistedsetids = [];
        $setid = 1;

        foreach ($payload as $setdata) {
            if (!is_array($setdata)) {
                $setid++;
                continue;
            }

            $jsonset = json_encode($setdata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($jsonset === false) {
                debugging(
                    'profilefield_repeatable: JSON encode failed for field ' . $fieldid .
                    ', user ' . $userid . ', set ' . $setid . ': ' . json_last_error_msg(),
                    DEBUG_DEVELOPER
                );
                $setid++;
                continue;
            }

            $this->upsert_set_row($dataid, $fieldid, $userid, $setid, $jsonset, $now);
            $persistedsetids[] = $setid;
            $setid++;
        }

        if (empty($persistedsetids)) {
            $DB->delete_records('profilefield_repeatable_data', ['dataid' => $dataid]);
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($persistedsetids, SQL_PARAMS_NAMED, 'setid', false);
        $params['dataid'] = $dataid;
        $DB->delete_records_select('profilefield_repeatable_data', "dataid = :dataid AND set_id $insql", $params);
    }

    /**
     * Insert or update one set row, preserving timecreated on update.
     *
     * @param int $dataid
     * @param int $fieldid
     * @param int $userid
     * @param int $setid
     * @param string $jsonset
     * @param int $now
     */
    protected function upsert_set_row(
        int $dataid,
        int $fieldid,
        int $userid,
        int $setid,
        string $jsonset,
        int $now
    ): void {
        global $DB;

        $sql = "INSERT INTO {profilefield_repeatable_data}
                    (dataid, fieldid, userid, set_id, data, timecreated, timemodified)
                VALUES
                    (:dataid, :fieldid, :userid, :setid, :data, :timecreated, :timemodified)
                ON CONFLICT (dataid, set_id)
                DO UPDATE SET
                    fieldid = EXCLUDED.fieldid,
                    userid = EXCLUDED.userid,
                    data = EXCLUDED.data,
                    timemodified = EXCLUDED.timemodified";

        $DB->execute($sql, [
            'dataid' => $dataid,
            'fieldid' => $fieldid,
            'userid' => $userid,
            'setid' => $setid,
            'data' => $jsonset,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Check if the storage table is available.
     *
     * @return bool
     */
    protected function has_storage_table(): bool {
        static $hastable = null;

        if ($hastable === null) {
            global $DB;
            $hastable = $DB->get_manager()->table_exists(new xmldb_table('profilefield_repeatable_data'));
        }

        return $hastable;
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
