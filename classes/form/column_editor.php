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
 * The column editor of a field's definition: its textarea, the data it needs and its validation.
 *
 * Engine side: it knows no field-definition API. The adapter adds the textarea with
 * {@see self::element_attributes()}, reads what only it can read from the STORED field (the keys
 * holding values and their labels, the stored pattern columns, never the submission) and passes
 * them in, together with whether customfield_pattern counts as present (its test seam).
 *
 * Everything the editor needs travels in data attributes of the textarea, not in the
 * js_call_amd() arguments, which core warns about past 1024 characters.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class column_editor {
    /**
     * The attributes of the schema textarea; amd/src/columneditor.js enhances the region it names.
     *
     * @return array
     */
    public static function element_attributes(): array {
        return [
            'rows' => 12,
            'cols' => 60,
            'data-region' => 'profilefield-repeatable-schema',
            'class' => 'profilefield-repeatable-schema-json',
        ];
    }

    /**
     * The submitted schema, normalised but with every column kept, so errors name what was typed.
     *
     * @param string $json
     * @return schema|null Null when the text is not a version 2 schema.
     */
    public static function submitted(string $json): ?schema {
        $document = schema::decode($json);
        if ($document === null || !is_array($document['columns'] ?? null)) {
            return null;
        }
        return schema::from_columns(array_values($document['columns']));
    }

    /**
     * A submitted schema pretty-printed, so it stays editable without JavaScript.
     *
     * @param string $json
     * @return string|null Null when the text is not a version 2 schema: it is shown as typed.
     */
    public static function pretty(string $json): ?string {
        $schema = self::submitted($json);
        if ($schema === null) {
            return null;
        }
        return json_encode(
            $schema->to_document(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /**
     * The canonical JSON a submitted schema is saved as.
     *
     * @param string $json
     * @return string|null Null when the text is not a version 2 schema.
     */
    public static function canonical(string $json): ?string {
        $schema = self::submitted($json);
        return $schema === null ? null : $schema->to_json();
    }

    /**
     * The data attributes of the textarea: locked keys, users, the companion's domains and patterns.
     *
     * @param array $usage ['users' => int, 'keys' => string[]], read by the adapter from the STORED
     *                     field's payloads ({@see \profilefield_repeatable\local\stored_values::usage()}).
     * @param bool $patternavailable Whether pattern columns can be configured.
     * @return array
     */
    public static function attributes(array $usage, bool $patternavailable): array {
        $domains = \local_repeatable\local\bridge\dictionaries::domains();
        return [
            'data-lockedkeys' => json_encode($usage['keys']),
            'data-users' => $usage['users'],
            'data-domains' => json_encode($domains ?? [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'data-companion' => $domains === null ? 0 : 1,
            // The pattern mode is offered only with customfield_pattern; its presets come from there.
            'data-pattern' => $patternavailable ? 1 : 0,
            'data-presets' => json_encode(
                $patternavailable ? \local_repeatable\local\bridge\pattern::presets() : [],
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            ),
        ];
    }

    /**
     * Start the column editor's AMD module.
     *
     * @return void
     */
    public static function init_js(): void {
        global $PAGE;

        $PAGE->requires->js_call_amd('profilefield_repeatable/columneditor', 'init');
    }

    /**
     * Every rule a submitted schema breaks ({@see schema::validate()}), with what the companions say.
     *
     * Domains are checked with the companion dictionary plugin and a parent column against the
     * domain holding its items' parents, where the companion knows it; pattern columns are checked
     * by customfield_pattern, and without it only the stored field's pattern columns may be
     * submitted, unchanged.
     *
     * @param array|null $document The decoded submission ({@see schema::decode()}).
     * @param array $lockedlabels Key => stored label of every key holding values in the STORED field.
     * @param array $storedpatterns Key => pattern object of the STORED field's pattern columns.
     * @param bool $patternavailable Whether customfield_pattern counts as present.
     * @return string[] Error messages, empty when the schema may be saved.
     */
    public static function errors(?array $document, array $lockedlabels, array $storedpatterns, bool $patternavailable): array {
        $domainexists = null;
        if (class_exists('\local_dictionaries\resolver')) {
            $domainexists = static fn(string $domain): bool => \local_dictionaries\resolver::domain_exists($domain);
        }

        $context = \core\context\system::instance();
        foreach ($lockedlabels as $key => $label) {
            $lockedlabels[$key] = format_string($label, true, ['context' => $context, 'escape' => false]);
        }
        $patternerrors = null;
        if ($patternavailable) {
            $patternerrors = [\local_repeatable\local\bridge\pattern::class, 'config_errors'];
        }

        // Domain => the domain holding its parents, where the companion knows it.
        $parentdomains = [];
        foreach (\local_repeatable\local\bridge\dictionaries::domains() ?? [] as $domain) {
            if ($domain['parentdomain'] !== null && $domain['parentdomain'] !== '') {
                $parentdomains[$domain['shortname']] = $domain['parentdomain'];
            }
        }

        return schema::validate(
            $document,
            $domainexists,
            $lockedlabels,
            $parentdomains,
            $patternerrors,
            $storedpatterns
        );
    }
}
