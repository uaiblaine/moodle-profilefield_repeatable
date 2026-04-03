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

namespace profilefield_repeatable\output;

/**
 * Display renderer for repeatable profile fields.
 *
 * Extracted from profile_field_repeatable to reduce class complexity.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine (anderson@blaine.com.br)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class display_renderer {
    /** @var string Domain shortname pattern for reference mapping. */
    private const DOMAIN_PATTERN = '/^[a-z0-9_]+$/';

    /** @var object Field configuration object. */
    private object $field;

    /** @var int User ID. */
    private int $userid;

    /** @var string Raw field data. */
    private string $data;

    /** @var \Closure Callback to check storage table availability. */
    private \Closure $hasstoragetablefn;

    /** @var array|null Cached subitem/domain mapping. */
    private ?array $subitemdomainmap = null;

    /** @var array Cached resolved labels by domain+code. */
    private array $resolveddisplaycache = [];

    /** @var core_renderer */
    private $output;

    /** @var moodle_page */
    private $page;

    /**
     * Constructor.
     *
     * @param object $field Field configuration object.
     * @param int $userid User ID.
     * @param string $data Raw field data.
     * @param \Closure $hasstoragetablefn Callback to check storage table availability.
     * @param \core_renderer|null $output Core renderer (optional, uses global if null).
     * @param \moodle_page|null $page Moodle page (optional, uses global if null).
     */
    public function __construct(
        object $field,
        int $userid,
        string $data,
        \Closure $hasstoragetablefn,
        $output = null,
        $page = null
    ) {
        $this->field = $field;
        $this->userid = $userid;
        $this->data = $data;
        $this->hasstoragetablefn = $hasstoragetablefn;
        $this->output = $output ?? ($GLOBALS['OUTPUT'] ?? null);
        $this->page = $page ?? ($GLOBALS['PAGE'] ?? null);
    }

    /**
     * Render field display output.
     *
     * @return string
     */
    public function render(): string {
        $displaysets = $this->get_display_sets();
        if (empty($displaysets)) {
            return '';
        }

        if ($this->is_profile_page_context()) {
            $output = $this->render_display_data_accordion($displaysets);
            if ($output !== '') {
                $this->initialise_profile_accordion_assets();
            }
            return $output;
        }

        return $this->render_display_data_plain($displaysets);
    }

    /**
     * Render legacy plain display format.
     *
     * @param array $displaysets
     * @return string
     */
    private function render_display_data_plain(array $displaysets): string {
        $context = \core\context\system::instance();
        $subitems = $this->get_subitems();
        $sets = [];

        foreach ($displaysets as $displayset) {
            $setnumber = (int)($displayset['setnumber'] ?? 0);
            $values = $displayset['values'] ?? [];
            if ($setnumber <= 0 || !is_array($values)) {
                continue;
            }

            $parts = [];
            foreach ($subitems as $subitem) {
                $value = (string)($values[$subitem] ?? '');
                if (trim($value) === '') {
                    continue;
                }

                $parts[] = [
                    'hasseparator' => !empty($parts),
                    'key' => format_string($subitem, true, ['context' => $context]),
                    'value' => $this->resolve_reference_display_value($subitem, $value),
                ];
            }

            if (empty($parts)) {
                continue;
            }

            $sets[] = [
                'setlabel' => get_string('repeatableset', 'profilefield_repeatable', $setnumber),
                'parts' => $parts,
            ];
        }

        if (empty($sets)) {
            return '';
        }

        return $this->output->render_from_template('profilefield_repeatable/display_plain', ['sets' => $sets]);
    }

    /**
     * Render accordion display format for profile pages.
     *
     * @param array $displaysets
     * @return string
     */
    private function render_display_data_accordion(array $displaysets): string {
        $context = $this->build_accordion_template_context($displaysets);
        if (empty($context['items'])) {
            return '';
        }

        return $this->output->render_from_template('profilefield_repeatable/display_accordion', $context);
    }

    /**
     * Initialise profile accordion assets.
     *
     * @return void
     */
    private function initialise_profile_accordion_assets(): void {
        $this->page->requires->js_call_amd('profilefield_repeatable/displayaccordion', 'init');
    }

    /**
     * Build accordion title data for one set.
     *
     * @param int $setnumber
     * @param string $primarysubitem
     * @param array $values
     * @return array
     */
    private function build_accordion_title_data(int $setnumber, string $primarysubitem, array $values): array {
        $primaryvalue = $this->resolve_reference_display_value(
            $primarysubitem,
            (string)($values[$primarysubitem] ?? '')
        );
        $primaryvalue = trim($primaryvalue);
        if ($primaryvalue === '') {
            return [
                'hasprimaryvalue' => false,
                'settitle' => get_string('repeatableset', 'profilefield_repeatable', $setnumber),
                'titlelabel' => '',
                'titlevalue' => '',
            ];
        }

        return [
            'hasprimaryvalue' => true,
            'settitle' => '',
            'titlelabel' => format_string($primarysubitem, true, ['context' => \core\context\system::instance()]),
            'titlevalue' => $primaryvalue,
        ];
    }

    /**
     * Build accordion row data excluding the first sub-item.
     *
     * @param string[] $subitems
     * @param array $values
     * @return array
     */
    private function build_accordion_rows(array $subitems, array $values): array {
        $rows = [];
        foreach ($subitems as $subitem) {
            $rows[] = [
                'label' => format_string($subitem, true, ['context' => \core\context\system::instance()]),
                'value' => $this->resolve_reference_display_value($subitem, (string)($values[$subitem] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * Build context consumed by the profile accordion Mustache template.
     *
     * @param array $displaysets
     * @return array
     */
    private function build_accordion_template_context(array $displaysets): array {
        $subitems = $this->get_subitems();
        if (empty($subitems)) {
            return [];
        }

        $primarysubitem = $subitems[0];
        $othersubitems = array_slice($subitems, 1);
        $accordionid = $this->get_display_accordion_id();
        $items = [];

        foreach ($displaysets as $index => $displayset) {
            $setnumber = (int)($displayset['setnumber'] ?? 0);
            $values = $displayset['values'] ?? [];
            $timemodified = $displayset['timemodified'] ?? null;

            if ($setnumber <= 0 || !is_array($values)) {
                continue;
            }

            $headingid = $accordionid . '-heading-' . $setnumber;
            $collapseid = $accordionid . '-collapse-' . $setnumber;
            $isopen = ($index === 0);
            $titledata = $this->build_accordion_title_data($setnumber, $primarysubitem, $values);
            $rows = $this->build_accordion_rows($othersubitems, $values);

            $item = [
                'setnumber' => $setnumber,
                'headingid' => $headingid,
                'collapseid' => $collapseid,
                'isopen' => $isopen,
                'isclosed' => !$isopen,
                'ariaexpanded' => $isopen ? 'true' : 'false',
                'rows' => $rows,
                'hastable' => !empty($rows),
                'hassync' => !empty($timemodified),
                'syncvalue' => null,
            ] + $titledata;

            if (!empty($timemodified)) {
                $formatted = userdate((int)$timemodified, '%d/%m/%y - %H:%M');
                $item['syncvalue'] = get_string('synchronisedat', 'profilefield_repeatable', $formatted);
            }

            $items[] = $item;
        }

        return [
            'accordionid' => $accordionid,
            'items' => $items,
            'tableinfo' => get_string('displaytableinfo', 'profilefield_repeatable'),
            'tablevalue' => get_string('displaytablevalue', 'profilefield_repeatable'),
        ];
    }

    /**
     * Build sets for display with optional metadata.
     *
     * @return array
     */
    private function get_display_sets(): array {
        $displaysets = $this->get_display_sets_from_storage();
        if (!empty($displaysets)) {
            return $displaysets;
        }

        $payload = \profilefield_repeatable\helper::normalise_payload($this->data, $this->get_subitems());
        return $this->convert_payload_to_display_sets($payload);
    }

    /**
     * Resolve display sets from storage table.
     *
     * @return array
     */
    private function get_display_sets_from_storage(): array {
        global $DB;

        if (!$this->has_storage_table() || empty($this->userid) || empty($this->field) || empty($this->field->id)) {
            return [];
        }

        $dataid = $DB->get_field('user_info_data', 'id', [
            'userid' => $this->userid,
            'fieldid' => (int)$this->field->id,
        ]);
        $records = $dataid ? $DB->get_records(
            'profilefield_repeatable_data',
            ['dataid' => (int)$dataid],
            'set_id ASC, id ASC',
            'id, set_id, data, timemodified'
        ) : [];

        $subitems = $this->get_subitems();
        if (empty($records) || empty($subitems)) {
            return [];
        }

        $rawsets = [];
        foreach ($records as $record) {
            $result = $this->decode_record_to_set($record, $subitems);
            if ($result !== null) {
                $rawsets[$result['setid']] = $result;
            }
        }

        ksort($rawsets);
        $displaysets = [];
        $setnumber = 1;
        foreach ($rawsets as $rawset) {
            $displaysets[] = [
                'setnumber' => $setnumber,
                'values' => $rawset['values'],
                'timemodified' => $rawset['timemodified'],
            ];
            $setnumber++;
        }

        return $displaysets;
    }

    /**
     * Decode a single storage record into a display set.
     *
     * @param object $record
     * @param string[] $subitems
     * @return array|null Null when the record should be skipped.
     */
    private function decode_record_to_set(object $record, array $subitems): ?array {
        $setid = (int)$record->set_id;
        $decoded = ($setid > 0) ? json_decode((string)$record->data, true) : null;
        if (!is_array($decoded)) {
            return null;
        }

        $set = [];
        $hasvalue = false;
        foreach ($subitems as $subitem) {
            $value = $decoded[$subitem] ?? '';
            if (!is_scalar($value) && $value !== null) {
                $value = '';
            }

            $value = (string)$value;
            if (trim($value) !== '') {
                $hasvalue = true;
            }
            $set[$subitem] = $value;
        }

        $timemodified = empty($record->timemodified) ? null : (int)$record->timemodified;

        return $hasvalue ? [
            'setid' => $setid,
            'values' => $set,
            'timemodified' => $timemodified,
        ] : null;
    }

    /**
     * Convert normalised payload to display set format.
     *
     * @param array $payload
     * @return array
     */
    private function convert_payload_to_display_sets(array $payload): array {
        if (empty($payload)) {
            return [];
        }

        $displaysets = [];
        $setnumber = 1;
        foreach ($payload as $setdata) {
            if (!is_array($setdata)) {
                continue;
            }

            $displaysets[] = [
                'setnumber' => $setnumber,
                'values' => $setdata,
                'timemodified' => null,
            ];
            $setnumber++;
        }

        return $displaysets;
    }

    /**
     * Return unique accordion identifier.
     *
     * @return string
     */
    private function get_display_accordion_id(): string {
        $shortname = clean_param($this->field->shortname, PARAM_ALPHANUMEXT);
        return 'profilefield-repeatable-display-' . (int)$this->field->id . '-' . $this->userid . '-' . $shortname;
    }

    /**
     * Check whether current request is a profile page.
     *
     * @return bool
     */
    private function is_profile_page_context(): bool {
        // Check script from globals if needed.
        $script = (string)($GLOBALS['SCRIPT'] ?? '');
        if ($script !== '') {
            return in_array($script, ['/user/profile.php', '/user/view.php'], true);
        }

        if ($this->page && $this->page->has_set_url()) {
            $path = $this->page->url->get_path();
            return in_array($path, ['/user/profile.php', '/user/view.php'], true);
        }

        return false;
    }

    /**
     * Resolve a display value via local reference dictionary when mapped.
     *
     * @param string $subitem
     * @param string $rawvalue
     * @return string
     */
    private function resolve_reference_display_value(string $subitem, string $rawvalue): string {
        if (trim($rawvalue) === '') {
            return '';
        }

        $domainmap = $this->get_subitem_domain_map();
        if (!isset($domainmap[$subitem])) {
            return $rawvalue;
        }

        $resolved = $this->try_resolve_from_domain($domainmap[$subitem], trim($rawvalue));
        return $resolved ?? $rawvalue;
    }

    /**
     * Attempt to resolve a label from the local reference dictionary.
     *
     * @param string $domain
     * @param string $code
     * @return string|null Resolved label or null when unavailable.
     */
    private function try_resolve_from_domain(string $domain, string $code): ?string {
        $cachekey = $domain . "\n" . $code;
        if (isset($this->resolveddisplaycache[$cachekey])) {
            return $this->resolveddisplaycache[$cachekey];
        }

        if (!class_exists('\local_profilefield_repeatable\Resolver')) {
            $this->resolveddisplaycache[$cachekey] = null;
            return null;
        }

        try {
            $label = \local_profilefield_repeatable\Resolver::resolve($domain, $code);
        } catch (\Throwable $e) {
            $label = null;
        }

        $resolved = (is_string($label) && trim($label) !== '') ? $label : null;
        $this->resolveddisplaycache[$cachekey] = $resolved;
        return $resolved;
    }

    /**
     * Get configured subitem/domain mapping from param3.
     *
     * @return array
     */
    private function get_subitem_domain_map(): array {
        if ($this->subitemdomainmap !== null) {
            return $this->subitemdomainmap;
        }

        $rawmappings = (string)($this->field->param3 ?? '');
        $this->subitemdomainmap = $this->parse_subitem_domain_map($rawmappings, $this->get_subitems());
        return $this->subitemdomainmap;
    }

    /**
     * Parse subitem/domain mapping lines with format subitem|domain.
     *
     * @param string $rawmappings
     * @param string[] $subitems
     * @return array
     */
    private function parse_subitem_domain_map(string $rawmappings, array $subitems): array {
        if (trim($rawmappings) === '' || empty($subitems)) {
            return [];
        }

        $canonicalsubitems = [];
        foreach ($subitems as $subitem) {
            $canonicalsubitems[\core_text::strtolower($subitem)] = $subitem;
        }

        $mappings = [];
        $lines = preg_split('/\R/u', $rawmappings) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || substr_count($line, '|') !== 1) {
                continue;
            }

            [$rawsubitem, $rawdomain] = array_map('trim', explode('|', $line, 2));
            if ($rawsubitem === '' || $rawdomain === '') {
                continue;
            }

            $subitemkey = \core_text::strtolower($rawsubitem);
            if (!isset($canonicalsubitems[$subitemkey])) {
                continue;
            }

            $canonicalsubitem = $canonicalsubitems[$subitemkey];
            if (isset($mappings[$canonicalsubitem])) {
                continue;
            }

            $domain = \core_text::strtolower($rawdomain);
            if (!preg_match(self::DOMAIN_PATTERN, $domain)) {
                continue;
            }

            $mappings[$canonicalsubitem] = $domain;
        }

        return $mappings;
    }

    /**
     * Get configured sub-items.
     *
     * @return string[]
     */
    private function get_subitems(): array {
        return \profilefield_repeatable\helper::parse_subitems((string)($this->field->param1 ?? ''));
    }

    /**
     * Check if the storage table is available.
     *
     * @return bool
     */
    private function has_storage_table(): bool {
        return ($this->hasstoragetablefn)();
    }
}
