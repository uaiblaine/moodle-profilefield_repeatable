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
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Class profile_field_repeatable.
 *
 * @package    profilefield_repeatable
 */
class profile_field_repeatable extends profile_field_base {
    /** @var string wrapper identifier used by AMD init. */
    protected $wrapperid = '';

    /** @var string hidden element identifier used by AMD init. */
    protected $hiddenid = '';

    /**
     * Adds elements for this field type to the edit form.
     *
     * @param moodleform $mform
     */
    public function edit_field_add($mform) {
        $mform->addElement('hidden', $this->inputname, '', ['id' => $this->get_hidden_id()]);
        $mform->setType($this->inputname, PARAM_RAW_TRIMMED);

        $html = html_writer::tag('label', format_string($this->field->name), [
            'class' => 'd-block mb-2',
        ]);

        $html .= html_writer::start_tag('div', [
            'id' => $this->get_wrapper_id(),
            'class' => 'profilefield-repeatable-wrapper',
            'data-region' => 'repeatable-wrapper',
        ]);
        $html .= html_writer::tag('div', '', [
            'class' => 'profilefield-repeatable-sets',
            'data-region' => 'sets',
        ]);
        $html .= html_writer::tag('button', get_string('addnewset', 'profilefield_repeatable'), [
            'type' => 'button',
            'class' => 'btn btn-secondary mt-2',
            'data-action' => 'addset',
        ]);
        $html .= html_writer::end_tag('div');

        $mform->addElement('html', $html);
    }

    /**
     * Load user data for this profile field, ready for editing.
     *
     * @param stdClass $user
     */
    public function edit_load_user_data($user) {
        $user->{$this->inputname} = $this->encode_payload($this->normalise_payload($this->data));
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
     * Process the data before it gets saved in database.
     *
     * @param string|null $data
     * @param stdClass $datarecord
     * @return string
     */
    public function edit_save_data_preprocess($data, $datarecord) {
        $payload = $this->normalise_payload($data);
        return $this->encode_payload($payload);
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

        $payload = $this->normalise_payload($rawpayload);
        if ($this->is_required() && empty($payload)) {
            return [$this->inputname => get_string('required')];
        }

        $usernew->{$this->inputname} = empty($payload) ? '' : $this->encode_payload($payload);

        return parent::edit_validate_field($usernew);
    }

    /**
     * Display the data for this field.
     *
     * @return string
     */
    public function display_data() {
        $payload = $this->normalise_payload($this->data);
        if (empty($payload)) {
            return '';
        }

        $sets = [];
        $setnumber = 1;

        foreach ($payload as $set) {
            $parts = [];
            foreach ($set as $subitem => $value) {
                if (trim($value) === '') {
                    continue;
                }

                $parts[] = html_writer::span(
                    format_string($subitem, true, ['context' => context_system::instance()]) . ': ',
                    'profilefield-repeatable-display-key'
                ) . html_writer::span(s($value), 'profilefield-repeatable-display-value');
            }

            if (empty($parts)) {
                continue;
            }

            $label = html_writer::span(
                s(get_string('repeatableset', 'profilefield_repeatable', $setnumber)) . ': ',
                'profilefield-repeatable-display-set'
            );
            $sets[] = html_writer::tag('li', $label . implode(' | ', $parts));
            $setnumber++;
        }

        if (empty($sets)) {
            return '';
        }

        return html_writer::tag('ul', implode('', $sets), ['class' => 'profilefield-repeatable-display']);
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
     * Parse and normalise repeatable payload.
     *
     * @param mixed $rawpayload
     * @return array
     */
    protected function normalise_payload($rawpayload): array {
        if (is_string($rawpayload)) {
            $rawpayload = trim($rawpayload);
            if ($rawpayload === '') {
                return [];
            }

            $rawdecoded = json_decode($rawpayload);
            if (json_last_error() !== JSON_ERROR_NONE || !is_object($rawdecoded)) {
                return [];
            }
            $decoded = json_decode($rawpayload, true);
        } else if (is_object($rawpayload)) {
            $decoded = json_decode(json_encode($rawpayload), true);
        } else if (is_array($rawpayload)) {
            $decoded = $rawpayload;
        } else {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $subitems = $this->get_subitems();
        if (empty($subitems)) {
            return [];
        }

        $normalised = [];
        $counter = 1;
        foreach ($decoded as $setdata) {
            if (is_object($setdata)) {
                $setdata = json_decode(json_encode($setdata), true);
            }
            if (!is_array($setdata)) {
                continue;
            }

            $set = [];
            $hasvalue = false;
            foreach ($subitems as $subitem) {
                $value = $setdata[$subitem] ?? '';
                if (!is_scalar($value) && $value !== null) {
                    $value = '';
                }
                $value = (string)$value;
                if (trim($value) !== '') {
                    $hasvalue = true;
                }
                $set[$subitem] = $value;
            }

            if ($hasvalue) {
                $normalised['@@ID#' . $counter] = $set;
                $counter++;
            }
        }

        return $normalised;
    }

    /**
     * Get configured sub-items.
     *
     * @return string[]
     */
    protected function get_subitems(): array {
        $rawsubitems = (string)($this->field->param1 ?? '');
        return $this->parse_subitems($rawsubitems);
    }

    /**
     * Parse textarea content into unique, non-empty sub-item labels.
     *
     * @param string $rawsubitems
     * @return string[]
     */
    protected function parse_subitems(string $rawsubitems): array {
        $lines = preg_split('/\R/u', $rawsubitems) ?: [];
        $subitems = [];
        $seen = [];

        foreach ($lines as $line) {
            $subitem = trim($line);
            if ($subitem === '') {
                continue;
            }

            $normalised = core_text::strtolower($subitem);
            if (isset($seen[$normalised])) {
                continue;
            }

            $seen[$normalised] = true;
            $subitems[] = $subitem;
        }

        return $subitems;
    }

    /**
     * Encode payload as strict JSON object.
     *
     * @param array $payload
     * @return string
     */
    protected function encode_payload(array $payload): string {
        if (empty($payload)) {
            return '{}';
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return '{}';
        }

        return $json;
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
