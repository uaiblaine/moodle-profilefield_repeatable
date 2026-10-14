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

use profilefield_repeatable\local\projection\projector;
use local_repeatable\local\schema;

/**
 * Renders the sets of one stored payload: an accordion or a plain list.
 *
 * Engine side: the adapter decides the layout (the profile field shows the accordion on its
 * profile pages), names the accordion and hands over the field's name and the projection rows
 * that date each set. Values of a dictionary column show the companion dictionary plugin's label
 * when that plugin is installed, and the raw code otherwise; labels are fetched once per domain.
 * Columns are read by key and shown under their label ({@see schema}).
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sets_renderer {
    /** @var string The accordion layout, with a sync footer per set. */
    public const LAYOUT_ACCORDION = 'accordion';

    /** @var string The plain list. */
    public const LAYOUT_PLAIN = 'plain';

    /** @var schema The field's column schema. */
    private schema $schema;

    /** @var string Raw stored payload. */
    private string $data;

    /** @var string The field's name as stored; formatted here. */
    private string $fieldname;

    /** @var string Id of the accordion, unique on the page; the ids of its set bodies extend it. */
    private string $idprefix;

    /** @var \Closure fn(): \stdClass[], the projection rows (set_id, data, timemodified) of this value. */
    private \Closure $syncrows;

    /** @var array Resolved labels keyed "<domain>\n<code>"; null caches a code with no label. */
    private array $resolveddisplaycache = [];

    /** @var \core_renderer */
    private $output;

    /** @var \moodle_page */
    private $page;

    /**
     * Constructor.
     *
     * @param schema $schema The field's column schema.
     * @param string $data Raw stored payload.
     * @param string $fieldname The field's name as stored.
     * @param string $idprefix Id of the accordion; unused by the plain list.
     * @param callable $syncrows fn(): \stdClass[], the projection rows (set_id, data, timemodified)
     *                           of this value; called only when there is a set to show, and only for
     *                           each set's last-sync time.
     * @param \core_renderer|null $output Core renderer (optional, uses global if null).
     * @param \moodle_page|null $page Moodle page (optional, uses global if null).
     */
    public function __construct(
        schema $schema,
        string $data,
        string $fieldname,
        string $idprefix,
        callable $syncrows,
        $output = null,
        $page = null
    ) {
        $this->schema = $schema;
        $this->data = $data;
        $this->fieldname = $fieldname;
        $this->idprefix = $idprefix;
        $this->syncrows = \Closure::fromCallable($syncrows);
        $this->output = $output ?? ($GLOBALS['OUTPUT'] ?? null);
        $this->page = $page ?? ($GLOBALS['PAGE'] ?? null);
    }

    /**
     * Render the sets in a layout.
     *
     * @param string $layout {@see self::LAYOUT_ACCORDION} or {@see self::LAYOUT_PLAIN}.
     * @return string HTML; '' when the payload holds no set.
     */
    public function render(string $layout): string {
        $displaysets = $this->get_display_sets();
        if (empty($displaysets)) {
            return '';
        }

        // Pre-resolve all reference labels in one bulk call per domain to avoid N+1 queries.
        $this->prefetch_reference_labels($displaysets);

        if ($layout === self::LAYOUT_ACCORDION) {
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
        $schema = $this->schema;
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
        $schema = $this->schema;
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
        $schema = $this->schema;
        $titlekey = $schema->title_key();
        if ($titlekey === null) {
            return [];
        }

        $otherkeys = array_values(array_diff($schema->keys(), [$titlekey]));
        $sublinekeys = $schema->subline_keys();
        $accordionid = $this->idprefix;
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
            $this->fieldname,
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
     * The sets always come from the canonical payload. A projection row only lends its
     * timemodified to the set it still mirrors: a row left stale by a direct write to the
     * canonical payload ({@see projector}) is ignored.
     *
     * @return array
     */
    private function get_display_sets(): array {
        $payload = \local_repeatable\local\payload::normalise($this->data, $this->schema);
        $displaysets = $this->convert_payload_to_display_sets($payload);
        if (empty($displaysets)) {
            return [];
        }

        $timemodifiedbyset = [];
        foreach (($this->syncrows)() as $record) {
            $canonical = projector::canonical_set(json_decode((string)$record->data, true));
            if ($canonical !== null && !empty($record->timemodified)) {
                $timemodifiedbyset[(int)$record->set_id] = [$canonical, (int)$record->timemodified];
            }
        }

        foreach ($displaysets as &$displayset) {
            $stored = $timemodifiedbyset[$displayset['setnumber']] ?? null;
            $canonical = projector::canonical_set($displayset['values']);
            if ($stored !== null && $stored[0] === $canonical) {
                $displayset['timemodified'] = $stored[1];
            }
        }
        unset($displayset);

        return $displaysets;
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
     * The value as shown: a dictionary code resolved to its label, a pattern value with its mask.
     *
     * Plain text: the templates escape it. A pattern value is formatted by customfield_pattern
     * (a raw-stored value gets its mask back) and shown as stored without the plugin.
     *
     * @param string $key Column key.
     * @param string $rawvalue
     * @return string
     */
    private function resolve_reference_display_value(string $key, string $rawvalue): string {
        if (trim($rawvalue) === '') {
            return '';
        }

        $patternconfig = $this->schema->pattern_config($key);
        if ($patternconfig !== null) {
            return \local_repeatable\local\bridge\pattern::format($patternconfig, $rawvalue);
        }

        $domainmap = $this->schema->domain_map();
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

        $domainmap = $this->schema->domain_map();
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
}
