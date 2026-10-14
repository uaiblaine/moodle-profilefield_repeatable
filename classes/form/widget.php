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

namespace profilefield_repeatable\form;

use local_repeatable\local\schema;

/**
 * The repeatable-sets edit widget: its table, the labels of the codes it holds and its JavaScript.
 *
 * Engine side: it knows no form API and no profile field. The adapter places the HTML in its
 * form, holds the value in a hidden input inside a form group named after the element (so the
 * form prints its error under the widget), and hands over the ids, the field's name and the
 * decisions that live with it: who may read a dictionary (the editing user), whether the
 * companion's lookup API and customfield_pattern count as present, and whether the value is
 * read-only.
 *
 * The columns reach the AMD module in a data attribute, not in the js_call_amd() arguments,
 * which core warns about past 1024 characters.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class widget {
    /** @var schema The field's column schema. */
    private schema $schema;

    /** @var \Closure fn(string $domain): bool, whether the editing user may read a dictionary. */
    private \Closure $canread;

    /** @var bool Whether the companion offers api::lookup() and api::can_read(). */
    private bool $lookupavailable;

    /** @var bool Whether customfield_pattern offers the API pattern columns need. */
    private bool $patternavailable;

    /**
     * Constructor.
     *
     * @param schema $schema The field's column schema.
     * @param callable $canread fn(string $domain): bool, whether the editing user may read the domain;
     *                          false for every domain when the companion is absent.
     * @param bool $lookupavailable Whether the companion offers what dictionary columns need.
     * @param bool $patternavailable Whether customfield_pattern offers what pattern columns need.
     */
    public function __construct(schema $schema, callable $canread, bool $lookupavailable, bool $patternavailable) {
        $this->schema = $schema;
        $this->canread = \Closure::fromCallable($canread);
        $this->lookupavailable = $lookupavailable;
        $this->patternavailable = $patternavailable;
    }

    /**
     * The widget's HTML: its label and its table of sets, which the AMD module fills.
     *
     * @param string $fieldname The field's name as stored; formatted here.
     * @param array $ids 'wrapperid' (unique per form), 'hiddenid' and 'errorid' (the error region
     *                   of the group holding the hidden input).
     * @return string
     */
    public function render(string $fieldname, array $ids): string {
        global $OUTPUT;

        $context = \core\context\system::instance();
        $columns = $this->columns();

        return $OUTPUT->render_from_template('profilefield_repeatable/edit_form', [
            'fieldlabel' => format_string($fieldname, true, ['context' => $context, 'escape' => false]),
            'labelid' => $ids['wrapperid'] . '-label',
            'errorid' => $ids['errorid'],
            'wrapperid' => $ids['wrapperid'],
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
    }

    /**
     * The columns as the template and the AMD module read them.
     *
     * @return array[]
     */
    private function columns(): array {
        $context = \core\context\system::instance();
        $schema = $this->schema;
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
        return $columns;
    }

    /**
     * Start the widget's AMD module, and customfield_pattern's formatter when a column needs it.
     *
     * @param array $ids 'wrapperid' and 'hiddenid', as given to {@see self::render()}.
     * @param bool $readonly Whether the value may not be changed (the adapter decides).
     * @return void
     */
    public function init_js(array $ids, bool $readonly): void {
        global $PAGE;

        $config = $this->js_config($ids, $readonly);
        $PAGE->requires->js_call_amd('profilefield_repeatable/repeatable', 'init', [$config]);
        if (!$readonly && $this->schema->pattern_objects() && $this->patternavailable) {
            // The formatter listens on the document, so it also formats the inputs of sets added later.
            \local_repeatable\local\bridge\pattern::js_init();
        }
    }

    /**
     * The configuration the AMD module's init() takes.
     *
     * @param array $ids 'wrapperid' and 'hiddenid'.
     * @param bool $readonly
     * @return array
     */
    public function js_config(array $ids, bool $readonly): array {
        return [
            'wrapperid' => $ids['wrapperid'],
            'hiddenid' => $ids['hiddenid'],
            'readonly' => $readonly,
            'strings' => [
                'addnewset' => get_string('addnewset', 'profilefield_repeatable'),
                'removeset' => get_string('removeset', 'profilefield_repeatable'),
                'repeatableset' => get_string('repeatableset', 'profilefield_repeatable', '{no}'),
                'newcode' => get_string('newcode', 'profilefield_repeatable'),
                'searchplaceholder' => get_string('searchplaceholder', 'profilefield_repeatable'),
            ],
        ];
    }

    /**
     * What the widget needs to offer a pattern column: the attributes of its text input.
     *
     * The attributes ({@see \customfield_pattern\api::input_attributes()}: the mask the
     * formatter reads, placeholder, inputmode, maxlength) ride in the columns JSON, because the
     * widget builds its inputs in JavaScript; the formatter listens on the document, so inputs
     * added with a new set are covered too ({@see self::init_js()}).
     *
     * @param array $column Normalised schema column.
     * @return array Empty for a column of another mode, or without the plugin.
     */
    private function pattern_widget_properties(array $column): array {
        $config = $this->schema->pattern_config($column['key']);
        if ($config === null || !$this->patternavailable) {
            return [];
        }
        return ['inputattributes' => \local_repeatable\local\bridge\pattern::input_attributes($config)];
    }

    /**
     * What the widget needs to offer a dictionary column through the companion's autocomplete.
     *
     * A dictionary column gets its domain, whether it accepts new codes ('open') and whether it
     * is searched ('autocomplete'): only when the editing user may read the domain, and the
     * parent column's domain too for a dependent column ({@see self::can_read_domain()}), because
     * the search service refuses everyone else; the column is then typed as a code, as without
     * the companion. A column with a parent also gets the parent's key, domain and plain label.
     *
     * @param array $column Normalised schema column.
     * @return array Empty for a free column.
     */
    private function dictionary_widget_properties(array $column): array {
        $domains = $this->schema->domain_map();
        if (!isset($domains[$column['key']])) {
            return [];
        }

        $domain = $domains[$column['key']];
        $parent = $column['parent'] ?? '';
        $hasparent = $parent !== '' && isset($domains[$parent]);
        $properties = [
            'domain' => $domain,
            'open' => $column['mode'] === \local_repeatable\local\schema::MODE_DICTIONARY_OPEN,
            'autocomplete' => $this->can_read_domain($domain) && (!$hasparent || $this->can_read_domain($domains[$parent])),
        ];
        if ($hasparent) {
            $properties['parent'] = $parent;
            $properties['parentdomain'] = $domains[$parent];
            $properties['parentlabel'] = format_string($this->schema->label($parent), true, [
                'context' => \core\context\system::instance(),
                'escape' => false,
            ]);
        }

        return $properties;
    }

    /**
     * Whether the editing user may read a domain, as the adapter decided.
     *
     * @param string $domain
     * @return bool
     */
    private function can_read_domain(string $domain): bool {
        return ($this->canread)($domain);
    }

    /**
     * Labels of the codes a payload holds in the dictionary columns, for the widget.
     *
     * The widget shows each preloaded value as "label (code)", as the search does, without a
     * request. The payload is the stored one or, after a failed validation, the submitted one:
     * in a domain the editing user cannot read, only codes stored for the owner under
     * that key get a label, or submitting codes would read a restricted dictionary through the
     * ungated resolver. Labels are formatted and left unescaped; the widget escapes.
     *
     * @param string $payload Raw payload the form holds.
     * @param array $stored Key => [folded code => true] stored for the owner
     *                      ({@see \local_repeatable\local\validator::stored_codes()}).
     * @return array Domain => [code => plain label]; codes without a label are absent.
     */
    public function labels(string $payload, array $stored): array {
        if (!$this->lookupavailable) {
            return [];
        }

        $schema = $this->schema;
        $domains = $schema->domain_map();
        $readable = [];
        foreach (array_unique($domains) as $domain) {
            $readable[$domain] = $this->can_read_domain($domain);
        }
        $codes = [];
        foreach (\local_repeatable\local\payload::normalise($payload, $schema) as $set) {
            foreach ($domains as $key => $domain) {
                $code = trim((string)($set[$key] ?? ''));
                if ($code === '') {
                    continue;
                }
                if ($readable[$domain] || isset($stored[$key][\local_repeatable\local\bridge\dictionaries::fold_code($code)])) {
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
}
