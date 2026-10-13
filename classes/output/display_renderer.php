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
 * Renders an accordion on /user/profile.php and /user/view.php and a plain list elsewhere. Values
 * of a dictionary column show the companion dictionary plugin's label when that plugin is
 * installed, and the raw code otherwise. Columns are read by key and shown under their label
 * ({@see \profilefield_repeatable\local\schema}).
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class display_renderer {
    /** @var object Field configuration object. */
    private object $field;

    /** @var int User ID. */
    private int $userid;

    /** @var string Raw field data. */
    private string $data;

    /** @var \profilefield_repeatable\local\schema|null Column schema, read once. */
    private ?\profilefield_repeatable\local\schema $schema = null;

    /** @var array Resolved labels keyed "<domain>\n<code>"; null caches a code with no label. */
    private array $resolveddisplaycache = [];

    /** @var \core_renderer */
    private $output;

    /** @var \moodle_page */
    private $page;

    /** @var \stdClass[]|null Projection rows already fetched by the caller; null fetches them on demand. */
    private ?array $preloadedrecords;

    /**
     * Constructor.
     *
     * @param object $field Field configuration object.
     * @param int $userid User ID.
     * @param string $data Raw field data.
     * @param \core_renderer|null $output Core renderer (optional, uses global if null).
     * @param \moodle_page|null $page Moodle page (optional, uses global if null).
     * @param \stdClass[]|null $preloadedrecords Projection rows (set_id, data, timemodified) already
     *                                           fetched by the caller; null fetches them on demand.
     *                                           They only supply each set's last-sync time.
     */
    public function __construct(
        object $field,
        int $userid,
        string $data,
        $output = null,
        $page = null,
        ?array $preloadedrecords = null
    ) {
        $this->field = $field;
        $this->userid = $userid;
        $this->data = $data;
        $this->output = $output ?? ($GLOBALS['OUTPUT'] ?? null);
        $this->page = $page ?? ($GLOBALS['PAGE'] ?? null);
        $this->preloadedrecords = $preloadedrecords;
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

        // Pre-resolve all reference labels in one bulk call per domain to avoid N+1 queries.
        $this->prefetch_reference_labels($displaysets);

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
     * Render the plain list used outside the profile pages.
     *
     * @param array $displaysets
     * @return string
     */
    private function render_display_data_plain(array $displaysets): string {
        $context = \core\context\system::instance();
        $schema = $this->get_schema();
        $sets = [];

        foreach ($displaysets as $displayset) {
            $setnumber = (int)($displayset['setnumber'] ?? 0);
            $values = $displayset['values'] ?? [];
            if ($setnumber <= 0 || !is_array($values)) {
                continue;
            }

            $parts = [];
            foreach ($schema->keys() as $key) {
                $value = (string)($values[$key] ?? '');
                if (trim($value) === '') {
                    continue;
                }

                $parts[] = [
                    'hasseparator' => !empty($parts),
                    'key' => format_string($schema->label($key), true, ['context' => $context, 'escape' => false]),
                    'value' => $this->resolve_reference_display_value($key, $value),
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
     * @param string $titlekey Key of the title column.
     * @param array $values
     * @return array
     */
    private function build_accordion_title_data(int $setnumber, string $titlekey, array $values): array {
        $primaryvalue = $this->resolve_reference_display_value(
            $titlekey,
            (string)($values[$titlekey] ?? '')
        );
        $primaryvalue = trim($primaryvalue);
        if ($primaryvalue === '') {
            return [
                'hasprimaryvalue' => false,
                'titlevalue' => get_string('repeatableset', 'profilefield_repeatable', $setnumber),
            ];
        }

        return [
            'hasprimaryvalue' => true,
            'titlevalue' => $primaryvalue,
        ];
    }

    /**
     * Build subline parts (collapsed-only secondary line).
     *
     * @param string[] $sublinekeys
     * @param array $values
     * @return array{hassubline: bool, parts: array}
     */
    private function build_accordion_subline(array $sublinekeys, array $values): array {
        $parts = [];
        foreach ($sublinekeys as $key) {
            $value = $this->resolve_reference_display_value($key, (string)($values[$key] ?? ''));
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            $parts[] = [
                'value' => $value,
                'isfirst' => empty($parts),
            ];
        }

        return [
            'hassubline' => !empty($parts),
            'parts' => $parts,
        ];
    }

    /**
     * Build accordion row data, one row per given column (the caller leaves out the title one).
     *
     * @param string[] $keys
     * @param array $values
     * @return array
     */
    private function build_accordion_rows(array $keys, array $values): array {
        $schema = $this->get_schema();
        $rows = [];
        foreach ($keys as $key) {
            $rows[] = [
                'label' => format_string($schema->label($key), true, [
                    'context' => \core\context\system::instance(),
                    'escape' => false,
                ]),
                'value' => $this->resolve_reference_display_value($key, (string)($values[$key] ?? '')),
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
        $schema = $this->get_schema();
        $titlekey = $schema->title_key();
        if ($titlekey === null) {
            return [];
        }

        $otherkeys = array_values(array_diff($schema->keys(), [$titlekey]));
        $sublinekeys = $schema->subline_keys();
        $accordionid = $this->get_display_accordion_id();
        $items = [];

        foreach ($displaysets as $index => $displayset) {
            $setnumber = (int)($displayset['setnumber'] ?? 0);
            $values = $displayset['values'] ?? [];
            $timemodified = $displayset['timemodified'] ?? null;

            if ($setnumber <= 0 || !is_array($values)) {
                continue;
            }

            $bodyid = $accordionid . '-body-' . $setnumber;
            $isopen = ($index === 0);
            $titledata = $this->build_accordion_title_data($setnumber, $titlekey, $values);
            $sublinedata = $this->build_accordion_subline($sublinekeys, $values);
            $rows = $this->build_accordion_rows($otherkeys, $values);

            $item = [
                'setnumber' => $setnumber,
                'bodyid' => $bodyid,
                'isopen' => $isopen,
                'rows' => $rows,
                'hasrows' => !empty($rows),
                'hassync' => !empty($timemodified),
                'syncvalue' => null,
            ] + $titledata + $sublinedata;

            if (!empty($timemodified)) {
                $formatted = userdate((int)$timemodified, get_string('strftimedatetimeshort', 'langconfig'));
                $item['syncvalue'] = get_string('synchronisedat', 'profilefield_repeatable', $formatted);
            }

            $items[] = $item;
        }

        // The templates escape, so the plain spelling goes in.
        $fieldname = format_string(
            (string)($this->field->name ?? ''),
            true,
            ['context' => \core\context\system::instance(), 'escape' => false]
        );

        return [
            'accordionid' => $accordionid,
            'fieldname' => $fieldname,
            'hasfieldname' => $fieldname !== '',
            'items' => $items,
        ];
    }

    /**
     * Build sets for display from the canonical payload.
     *
     * The sets always come from the user_info_data payload. A projection row only lends its
     * timemodified to the set it still mirrors: a row left stale by a direct write to
     * user_info_data ({@see \profilefield_repeatable\local\projection}) is ignored.
     *
     * @return array
     */
    private function get_display_sets(): array {
        $payload = \profilefield_repeatable\helper::normalise_payload($this->data, $this->get_schema());
        $displaysets = $this->convert_payload_to_display_sets($payload);
        if (empty($displaysets)) {
            return [];
        }

        $timemodifiedbyset = [];
        foreach ($this->get_projection_records() as $record) {
            $canonical = \profilefield_repeatable\local\projection::canonical_set(json_decode((string)$record->data, true));
            if ($canonical !== null && !empty($record->timemodified)) {
                $timemodifiedbyset[(int)$record->set_id] = [$canonical, (int)$record->timemodified];
            }
        }

        foreach ($displaysets as &$displayset) {
            $stored = $timemodifiedbyset[$displayset['setnumber']] ?? null;
            $canonical = \profilefield_repeatable\local\projection::canonical_set($displayset['values']);
            if ($stored !== null && $stored[0] === $canonical) {
                $displayset['timemodified'] = $stored[1];
            }
        }
        unset($displayset);

        return $displaysets;
    }

    /**
     * Projection rows of this user and field, preloaded or fetched once.
     *
     * @return \stdClass[]
     */
    private function get_projection_records(): array {
        global $DB;

        if ($this->preloadedrecords !== null) {
            return $this->preloadedrecords;
        }

        $this->preloadedrecords = [];
        if ($this->userid > 0 && !empty($this->field->id)) {
            $this->preloadedrecords = array_values($DB->get_records(
                'profilefield_repeatable_data',
                ['fieldid' => (int)$this->field->id, 'userid' => $this->userid],
                'set_id ASC, id ASC',
                'id, set_id, data, timemodified'
            ));
        }

        return $this->preloadedrecords;
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
     * Resolve a display value via local reference dictionary for a dictionary column.
     *
     * @param string $key Column key.
     * @param string $rawvalue
     * @return string
     */
    private function resolve_reference_display_value(string $key, string $rawvalue): string {
        if (trim($rawvalue) === '') {
            return '';
        }

        $domainmap = $this->get_schema()->domain_map();
        if (!isset($domainmap[$key])) {
            return $rawvalue;
        }

        $resolved = $this->try_resolve_from_domain($domainmap[$key], trim($rawvalue));
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
        if (array_key_exists($cachekey, $this->resolveddisplaycache)) {
            return $this->resolveddisplaycache[$cachekey];
        }

        if (!class_exists('\local_dictionaries\resolver')) {
            $this->resolveddisplaycache[$cachekey] = null;
            return null;
        }

        try {
            $label = \local_dictionaries\resolver::resolve($domain, $code);
        } catch (\Throwable $e) {
            $label = null;
        }

        $resolved = (is_string($label) && trim($label) !== '') ? $label : null;
        $this->resolveddisplaycache[$cachekey] = $resolved;
        return $resolved;
    }

    /**
     * Pre-resolve labels for every (domain, code) pair used by the current display sets.
     *
     * Issues one bulk query per distinct domain instead of one query per code,
     * and seeds the per-instance resolution cache used by try_resolve_from_domain().
     *
     * @param array $displaysets
     */
    private function prefetch_reference_labels(array $displaysets): void {
        if (!class_exists('\local_dictionaries\resolver')) {
            return;
        }

        $domainmap = $this->get_schema()->domain_map();
        if (empty($domainmap)) {
            return;
        }

        $codesbydomain = [];
        foreach ($displaysets as $set) {
            $values = (array)($set['values'] ?? []);
            foreach ($domainmap as $key => $domain) {
                $rawvalue = trim((string)($values[$key] ?? ''));
                if ($rawvalue === '') {
                    continue;
                }
                $cachekey = $domain . "\n" . $rawvalue;
                if (array_key_exists($cachekey, $this->resolveddisplaycache)) {
                    continue;
                }
                $codesbydomain[$domain][$rawvalue] = $rawvalue;
            }
        }

        foreach ($codesbydomain as $domain => $codes) {
            try {
                $labels = \local_dictionaries\resolver::resolve_bulk($domain, array_values($codes));
            } catch (\Throwable $e) {
                $labels = [];
            }
            foreach ($codes as $code) {
                $cachekey = $domain . "\n" . $code;
                $label = $labels[$code] ?? null;
                $this->resolveddisplaycache[$cachekey] =
                    (is_string($label) && trim($label) !== '') ? $label : null;
            }
        }
    }

    /**
     * The field's column schema, read once.
     *
     * @return \profilefield_repeatable\local\schema
     */
    private function get_schema(): \profilefield_repeatable\local\schema {
        if ($this->schema === null) {
            $this->schema = \profilefield_repeatable\local\schema::from_field((object)(array)$this->field);
        }
        return $this->schema;
    }
}
