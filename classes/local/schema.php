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

namespace profilefield_repeatable\local;

use profilefield_repeatable\helper;

/**
 * Column schema of a repeatable field (version 2), stored as JSON by the adapter that owns the field.
 *
 * Shape: {"version": 2, "columns": [{"key", "label", "mode", "domain"?, "parent"?, "pattern"?,
 * "title"?, "subline"?, "required"?}, ...]}. Payload sets are keyed by column key, so a
 * label can change without orphaning stored values; the key is generated once from the label.
 * A stored "indexed" flag (the r1 releases wrote one) is not one of {@see self::FLAGS}: reading
 * drops it, and the next save writes the schema without it.
 *
 * Engine side: nothing here reads a profile field. A field still on the version 1 parameters is
 * read by the profile adapter ({@see field_schema::from_v1()}), whose payloads are keyed by label:
 * {@see self::legacy_labels()} tells the payload readers which label each key used to be stored
 * under, until the payloads are re-keyed ({@see \profilefield_repeatable\local\migration}).
 *
 * The constructor normalises types and never rejects: {@see self::validate()} is the one place
 * that decides whether a submitted schema may be saved.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class schema {
    /** @var int Schema version written to param1. */
    public const VERSION = 2;

    /** @var int Maximum number of columns per field. */
    public const MAX_COLUMNS = 20;

    /** @var int Maximum number of subline columns; the title is never one of them. */
    public const MAX_SUBLINE = 3;

    /** @var int Maximum length of a key. */
    public const KEY_MAXLENGTH = 40;

    /** @var string Pattern of a key; amd/src/columneditor.js generates keys under the same rule. */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/';

    /** @var string Free text. */
    public const MODE_FREE = 'free';

    /** @var string Codes of a local_dictionaries domain only. */
    public const MODE_DICTIONARY = 'dictionary';

    /** @var string Suggestions from a domain, any code accepted. */
    public const MODE_DICTIONARY_OPEN = 'dictionary_open';

    /** @var string Mask and/or regular expression, from customfield_pattern ({@see pattern_columns}). */
    public const MODE_PATTERN = 'pattern';

    /**
     * @var string[] Modes a column may always be saved with; {@see self::MODE_PATTERN} only as
     *               {@see self::validate()} allows it.
     */
    public const ENABLED_MODES = [self::MODE_FREE, self::MODE_DICTIONARY, self::MODE_DICTIONARY_OPEN];

    /** @var string Report Builder identifier of the set number, which no column may produce. */
    public const SETNUMBER_IDENTIFIER = 'setnumber';

    /** @var string[] Boolean column flags, in output order. */
    private const FLAGS = ['title', 'subline', 'required'];

    /** @var array[] Normalised columns, in display order. */
    private array $columns;

    /** @var string[] Key => label the key's values may still be stored under (version 1 payloads). */
    private array $legacylabels = [];

    /** @var string[]|null Key => domain, computed on first use. */
    private ?array $domainmap = null;

    /**
     * Constructor.
     *
     * @param array $columns Raw columns; each is normalised ({@see self::normalise_column()}).
     */
    private function __construct(array $columns) {
        $this->columns = array_values(array_map(static fn($column): array => self::normalise_column($column), $columns));
    }

    /**
     * Build a schema from raw column arrays.
     *
     * @param array $columns List of column arrays.
     * @return self
     */
    public static function from_columns(array $columns): self {
        return new self($columns);
    }

    /**
     * Decode a version 2 document.
     *
     * @param string $json
     * @return array|null The decoded document, or null when the text is not a version 2 schema.
     */
    public static function decode(string $json): ?array {
        $json = trim($json);
        if ($json === '' || $json[0] !== '{') {
            return null;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || ($decoded['version'] ?? null) !== self::VERSION) {
            return null;
        }
        return $decoded;
    }

    /**
     * Build a schema from a version 2 document.
     *
     * @param string $json
     * @return self|null Null when the text is not a version 2 schema.
     */
    public static function from_json(string $json): ?self {
        $decoded = self::decode($json);
        if ($decoded === null) {
            return null;
        }
        $columns = $decoded['columns'] ?? [];
        return (new self(is_array($columns) ? array_values($columns) : []))->without_unsafe_columns();
    }

    /**
     * A copy without the columns a stored schema must not hand to the readers.
     *
     * The definition form validates before saving, but param1 can be written behind it (a
     * restore, a direct update). A column whose key breaks {@see self::KEY_PATTERN}, repeats an
     * earlier key or produces a Report Builder identifier an earlier column already has is
     * dropped, so no reader or SQL expression ever sees it; debugging() reports
     * each dropped column once per request.
     *
     * @return self
     */
    private function without_unsafe_columns(): self {
        static $reported = [];

        $kept = [];
        $seen = [self::SETNUMBER_IDENTIFIER => true];
        foreach ($this->columns as $column) {
            $identifiers = self::column_identifiers($column);
            $safe = (bool)preg_match(self::KEY_PATTERN, $column['key']) && !isset($seen[$column['key']]);
            foreach ($identifiers as $identifier) {
                $safe = $safe && !isset($seen[$identifier]);
            }
            if (!$safe) {
                $message = 'profilefield_repeatable: ignoring the stored column with key "' . $column['key'] . '"';
                if (!isset($reported[$message])) {
                    $reported[$message] = true;
                    debugging($message, DEBUG_DEVELOPER);
                }
                continue;
            }
            $kept[] = $column;
            $seen[$column['key']] = true;
            foreach ($identifiers as $identifier) {
                $seen[$identifier] = true;
            }
        }

        $copy = clone $this;
        $copy->columns = $kept;
        $copy->domainmap = null;
        return $copy;
    }

    /**
     * A copy reading each key's values also under a legacy label.
     *
     * @param array $legacylabels Key => label; keys the schema does not have are ignored.
     * @return self
     */
    public function with_legacy_labels(array $legacylabels): self {
        $copy = clone $this;
        $copy->legacylabels = [];
        foreach ($this->keys() as $key) {
            if (isset($legacylabels[$key]) && is_string($legacylabels[$key]) && $legacylabels[$key] !== $key) {
                $copy->legacylabels[$key] = $legacylabels[$key];
            }
        }
        return $copy;
    }

    /**
     * Key => label for every key whose values may still be stored under a version 1 label.
     *
     * @return string[]
     */
    public function legacy_labels(): array {
        return $this->legacylabels;
    }

    /**
     * Normalise one raw column: trimmed strings, lower-case domain, true-only flags.
     *
     * Settings that do not belong to the mode (a domain on a free column) are dropped.
     *
     * @param mixed $raw
     * @return array
     */
    private static function normalise_column($raw): array {
        $raw = is_array($raw) ? $raw : [];
        $mode = self::string_value($raw['mode'] ?? null);
        $column = [
            'key' => self::string_value($raw['key'] ?? null),
            'label' => self::string_value($raw['label'] ?? null),
            'mode' => $mode === '' ? self::MODE_FREE : $mode,
        ];

        if (self::is_dictionary_mode($column['mode'])) {
            $column['domain'] = \core_text::strtolower(self::string_value($raw['domain'] ?? null));
            $parent = self::string_value($raw['parent'] ?? null);
            if ($parent !== '') {
                $column['parent'] = $parent;
            }
        }
        if ($column['mode'] === self::MODE_PATTERN && is_array($raw['pattern'] ?? null)) {
            $column['pattern'] = $raw['pattern'];
        }
        foreach (self::FLAGS as $flag) {
            if (!empty($raw[$flag])) {
                $column[$flag] = true;
            }
        }

        return $column;
    }

    /**
     * A trimmed string, or '' for anything that is not a scalar.
     *
     * @param mixed $value
     * @return string
     */
    private static function string_value($value): string {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * Whether a mode reads its codes from a dictionary domain.
     *
     * @param string $mode
     * @return bool
     */
    public static function is_dictionary_mode(string $mode): bool {
        return $mode === self::MODE_DICTIONARY || $mode === self::MODE_DICTIONARY_OPEN;
    }

    /**
     * Validate a submitted version 2 document.
     *
     * One message per broken rule, in the order the rules are listed in the code; values are
     * escaped, so the messages can be printed as form errors.
     *
     * @param mixed $document Decoded document ({@see self::decode()}), or anything else.
     * @param callable|null $domainexists fn(string $domain): bool, asked only for well-formed
     *                                    domains; null skips the check (companion absent).
     * @param array $lockedlabels Key => label of the stored columns holding values: each of
     *                            these keys must still be in the document.
     * @param array $parentdomains Domain => the domain holding the parents of its items, for the
     *                             domains whose parent domain the companion knows
     *                             (\local_dictionaries\api::list_domains()): a column whose
     *                             parent column uses another domain is rejected. Empty skips the check.
     * @param callable|null $patternerrors fn(array $config): array, the problems of a pattern
     *                                     column's configuration ({@see pattern_columns::config_errors()});
     *                                     null when customfield_pattern is absent, which refuses every
     *                                     pattern column but the stored ones.
     * @param array $storedpatterns Key => raw "pattern" object of the stored field's pattern
     *                              columns ({@see self::pattern_objects()}). Without
     *                              customfield_pattern such a column may be saved again, unchanged:
     *                              its configuration cannot be checked, only kept.
     * @return string[] Error messages; empty when the schema may be saved.
     */
    public static function validate(
        $document,
        ?callable $domainexists = null,
        array $lockedlabels = [],
        array $parentdomains = [],
        ?callable $patternerrors = null,
        array $storedpatterns = []
    ): array {
        $rawcolumns = is_array($document) ? ($document['columns'] ?? null) : null;
        if (!is_array($rawcolumns) || ($document['version'] ?? null) !== self::VERSION || !array_is_list($rawcolumns)) {
            return [self::error('errorschemainvalid')];
        }
        foreach ($rawcolumns as $rawcolumn) {
            if (!is_array($rawcolumn)) {
                return [self::error('errorschemainvalid')];
            }
        }
        if (empty($rawcolumns)) {
            return [self::error('errorcolumnsrequired')];
        }
        if (count($rawcolumns) > self::MAX_COLUMNS) {
            return [self::error('errorcolumnslimit', self::MAX_COLUMNS)];
        }

        $columns = (new self($rawcolumns))->columns();
        $errors = array_merge(
            self::validate_columns($columns, $domainexists, $parentdomains, $patternerrors, $storedpatterns),
            self::validate_roles($columns),
            self::validate_identifiers($columns)
        );

        // A key holding stored values may be neither removed nor renamed: either hides the values.
        $removed = array_diff_key($lockedlabels, array_flip(array_column($columns, 'key')));
        if (!empty($removed)) {
            $errors[] = self::error('errorkeylocked', implode(', ', array_map('strval', $removed)));
        }

        return array_values(array_unique($errors));
    }

    /**
     * Per-column rules: label, key, mode, domain and parent, pattern configuration.
     *
     * @param array[] $columns Normalised columns.
     * @param callable|null $domainexists
     * @param array $parentdomains Domain => parent domain ({@see self::validate()}).
     * @param callable|null $patternerrors ({@see self::validate()}).
     * @param array $storedpatterns ({@see self::validate()}).
     * @return string[]
     */
    private static function validate_columns(
        array $columns,
        ?callable $domainexists,
        array $parentdomains,
        ?callable $patternerrors,
        array $storedpatterns
    ): array {
        $errors = [];
        $keys = [];
        $labels = [];
        // Key => domain of the dictionary columns seen so far.
        $dictionarykeys = [];

        foreach ($columns as $position => $column) {
            $key = $column['key'];
            $label = $column['label'];
            if ($label === '') {
                $errors[] = self::error('errorlabelrequired', $position + 1);
            } else if (isset($labels[\core_text::strtolower($label)])) {
                $errors[] = self::error('errorlabelduplicate', $label);
            }
            $labels[\core_text::strtolower($label)] = true;

            if (!preg_match(self::KEY_PATTERN, $key)) {
                $errors[] = self::error('errorkeyinvalid', $key);
            } else if (isset($keys[$key])) {
                $errors[] = self::error('errorkeyduplicate', $key);
            }
            $keys[$key] = true;

            $name = $label === '' ? $key : $label;
            if ($column['mode'] === self::MODE_PATTERN) {
                $errors = array_merge($errors, self::validate_pattern($column, $name, $patternerrors, $storedpatterns));
                continue;
            }
            if (!in_array($column['mode'], self::ENABLED_MODES, true)) {
                $errors[] = self::error('errormodeinvalid', $name);
                continue;
            }
            if (!self::is_dictionary_mode($column['mode'])) {
                continue;
            }

            $domain = $column['domain'];
            if ($domain === '' || !preg_match(helper::domain_pattern(), $domain)) {
                $errors[] = self::error('errordomaininvalid', $name);
            } else if ($domainexists !== null && !$domainexists($domain)) {
                $errors[] = self::error('errordomainmissing', $domain);
            }

            // The parent must come earlier, so a set is always filled parent first.
            if (isset($column['parent']) && !isset($dictionarykeys[$column['parent']])) {
                $errors[] = self::error('errorparentinvalid', $name);
            } else if (
                isset($column['parent'], $parentdomains[$domain])
                && $parentdomains[$domain] !== $dictionarykeys[$column['parent']]
            ) {
                // The child's suggestions are filtered by a parent code from another dictionary: none would match.
                $errors[] = get_string('errorparentmismatch', 'profilefield_repeatable', (object)[
                    'column' => s($name),
                    'domain' => s($domain),
                    'parentdomain' => s($parentdomains[$domain]),
                ]);
            }
            $dictionarykeys[$key] = $domain;
        }

        return $errors;
    }

    /**
     * The rules of one pattern column.
     *
     * With customfield_pattern, the configuration must pass its checks; each problem is reported
     * with the column's name. Without it, only a column the stored field already has, under the
     * same key and with the same configuration, may be saved: nothing could check a new or changed
     * one, and the stored one is kept so that its rules apply again once the plugin is back.
     *
     * @param array $column Normalised column.
     * @param string $name The column's label, or its key when it has none.
     * @param callable|null $patternerrors ({@see self::validate()}).
     * @param array $storedpatterns ({@see self::validate()}).
     * @return string[]
     */
    private static function validate_pattern(
        array $column,
        string $name,
        ?callable $patternerrors,
        array $storedpatterns
    ): array {
        $object = $column['pattern'] ?? [];
        if ($patternerrors === null) {
            $key = $column['key'];
            // Strict, on both sides' settings in key order: the order is not part of the configuration,
            // but a loose == would take "1000" for "1e3" and let a changed setting through.
            if (
                !array_key_exists($key, $storedpatterns)
                || self::sorted_by_key($storedpatterns[$key]) !== self::sorted_by_key($object)
            ) {
                return [self::error('errorpatternunavailable', $name)];
            }
            return [];
        }

        $errors = [];
        foreach ($patternerrors(pattern_columns::config($column)) as $message) {
            $errors[] = get_string('errorpatternconfig', 'profilefield_repeatable', (object)[
                'column' => s($name),
                'error' => $message,
            ]);
        }
        return $errors;
    }

    /**
     * A value with the keys of every array in it sorted, recursively, so that two decoded JSON
     * objects that differ only in key order compare identical with ===.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function sorted_by_key($value) {
        if (!is_array($value)) {
            return $value;
        }
        ksort($value, SORT_STRING);
        foreach ($value as $name => $item) {
            $value[$name] = self::sorted_by_key($item);
        }
        return $value;
    }

    /**
     * Role rules: exactly one title, the subline limit.
     *
     * @param array[] $columns Normalised columns.
     * @return string[]
     */
    private static function validate_roles(array $columns): array {
        $errors = [];
        $titles = array_filter($columns, static fn(array $column): bool => !empty($column['title']));
        if (count($titles) !== 1) {
            $errors[] = self::error('errortitlecount');
        }

        $subline = array_filter($columns, static fn(array $column): bool => !empty($column['subline']));
        foreach ($subline as $column) {
            if (!empty($column['title'])) {
                $errors[] = self::error('errorsublinetitle', $column['label']);
            }
        }
        if (count($subline) > self::MAX_SUBLINE) {
            $errors[] = self::error('errorsublinelimit', self::MAX_SUBLINE);
        }

        return $errors;
    }

    /**
     * Report Builder rule: no two columns produce the same identifier.
     *
     * A dictionary column "lang" produces "lang_name" and "lang_code", which a column keyed
     * "lang_name" would also claim.
     *
     * @param array[] $columns Normalised columns.
     * @return string[]
     */
    private static function validate_identifiers(array $columns): array {
        $errors = [];
        $seen = [self::SETNUMBER_IDENTIFIER => true];
        foreach ($columns as $column) {
            foreach (self::column_identifiers($column) as $identifier) {
                if (isset($seen[$identifier])) {
                    $errors[] = self::error('errorkeyconflict', $identifier);
                }
                $seen[$identifier] = true;
            }
        }
        return $errors;
    }

    /**
     * One escaped validation message.
     *
     * @param string $identifier Lang string identifier.
     * @param mixed $a Value inserted into the string; strings are escaped.
     * @return string
     */
    private static function error(string $identifier, $a = null): string {
        return get_string($identifier, 'profilefield_repeatable', is_string($a) ? s($a) : $a);
    }

    /**
     * Generate a key from a label: ASCII-folded, lower case, other runs of characters as "_".
     *
     * The key starts with a letter, fits {@see self::KEY_MAXLENGTH}, and neither it nor the
     * Report Builder identifiers it would produce in any mode are in $taken; a numeric suffix
     * makes it unique. amd/src/columneditor.js mirrors this function.
     *
     * @param string $label
     * @param string[] $taken Identifiers in use ({@see self::reserved_identifiers()} of the other keys).
     * @return string
     */
    public static function generate_key(string $label, array $taken = []): string {
        $slug = \core_text::strtolower(\core_text::specialtoascii($label));
        $slug = trim(preg_replace('/[^a-z0-9]+/', '_', $slug), '_');
        if ($slug === '') {
            $slug = 'column';
        } else if (!preg_match('/^[a-z]/', $slug)) {
            $slug = 'column_' . $slug;
        }

        $taken = array_flip($taken);
        $taken[self::SETNUMBER_IDENTIFIER] = true;
        $suffix = '';
        $counter = 1;
        do {
            $base = rtrim(substr($slug, 0, self::KEY_MAXLENGTH - strlen($suffix)), '_');
            $candidate = $base . $suffix;
            $free = true;
            foreach (self::reserved_identifiers($candidate) as $identifier) {
                $free = $free && !isset($taken[$identifier]);
            }
            $counter++;
            $suffix = '_' . $counter;
        } while (!$free);

        return $candidate;
    }

    /**
     * Identifiers a key may produce in any mode, reserved against later keys.
     *
     * @param string $key
     * @return string[]
     */
    public static function reserved_identifiers(string $key): array {
        return [$key, $key . '_name', $key . '_code'];
    }

    /**
     * The Report Builder identifiers of one column in its mode.
     *
     * @param array $column Normalised column.
     * @return string[] Kind ('value', 'name' or 'code') => identifier.
     */
    private static function column_identifiers(array $column): array {
        if (self::is_dictionary_mode($column['mode'])) {
            return ['name' => $column['key'] . '_name', 'code' => $column['key'] . '_code'];
        }
        return ['value' => $column['key']];
    }

    /**
     * The Report Builder identifiers of a column, by kind.
     *
     * @param string $key
     * @return string[] Kind ('value', 'name' or 'code') => identifier; empty for an unknown key.
     */
    public function report_identifiers(string $key): array {
        $column = $this->column($key);
        return $column === null ? [] : self::column_identifiers($column);
    }

    /**
     * The normalised columns, in display order.
     *
     * @return array[]
     */
    public function columns(): array {
        return $this->columns;
    }

    /**
     * Whether the schema has no column.
     *
     * @return bool
     */
    public function is_empty(): bool {
        return empty($this->columns);
    }

    /**
     * The column keys, in display order.
     *
     * @return string[]
     */
    public function keys(): array {
        return array_column($this->columns, 'key');
    }

    /**
     * One column by key.
     *
     * @param string $key
     * @return array|null
     */
    public function column(string $key): ?array {
        foreach ($this->columns as $column) {
            if ($column['key'] === $key) {
                return $column;
            }
        }
        return null;
    }

    /**
     * The raw label of a column (multilang tags included), or the key when it has none.
     *
     * @param string $key
     * @return string
     */
    public function label(string $key): string {
        $label = $this->column($key)['label'] ?? '';
        return $label === '' ? $key : $label;
    }

    /**
     * The key of the title column: the flagged one, or the first column when none is.
     *
     * @return string|null Null for an empty schema.
     */
    public function title_key(): ?string {
        foreach ($this->columns as $column) {
            if (!empty($column['title'])) {
                return $column['key'];
            }
        }
        return $this->columns[0]['key'] ?? null;
    }

    /**
     * Subline keys: flagged, never the title, at most {@see self::MAX_SUBLINE}.
     *
     * @return string[]
     */
    public function subline_keys(): array {
        $title = $this->title_key();
        $keys = [];
        foreach ($this->columns as $column) {
            if (!empty($column['subline']) && $column['key'] !== $title) {
                $keys[] = $column['key'];
            }
        }
        return array_slice($keys, 0, self::MAX_SUBLINE);
    }

    /**
     * Keys of the columns every non-empty set must fill.
     *
     * @return string[]
     */
    public function required_keys(): array {
        return $this->flagged_keys('required');
    }

    /**
     * Keys of the columns carrying one flag.
     *
     * @param string $flag
     * @return string[]
     */
    private function flagged_keys(string $flag): array {
        $keys = [];
        foreach ($this->columns as $column) {
            if (!empty($column[$flag])) {
                $keys[] = $column['key'];
            }
        }
        return $keys;
    }

    /**
     * The "pattern" object of every pattern column, as stored.
     *
     * @return array Key => the column's raw pattern object (empty when it has none).
     */
    public function pattern_objects(): array {
        $objects = [];
        foreach ($this->columns as $column) {
            if ($column['mode'] === self::MODE_PATTERN) {
                $objects[$column['key']] = $column['pattern'] ?? [];
            }
        }
        return $objects;
    }

    /**
     * The customfield_pattern configuration of a pattern column.
     *
     * @param string $key
     * @return array|null The configuration ({@see pattern_columns::config()}), or null when the
     *                    key is not a pattern column.
     */
    public function pattern_config(string $key): ?array {
        $column = $this->column($key);
        if ($column === null || $column['mode'] !== self::MODE_PATTERN) {
            return null;
        }
        return pattern_columns::config($column);
    }

    /**
     * Domain of every dictionary column that names a well-formed one.
     *
     * @return string[] Key => domain shortname.
     */
    public function domain_map(): array {
        if ($this->domainmap !== null) {
            return $this->domainmap;
        }
        $map = [];
        foreach ($this->columns as $column) {
            if (self::is_dictionary_mode($column['mode']) && preg_match(helper::domain_pattern(), $column['domain'])) {
                $map[$column['key']] = $column['domain'];
            }
        }
        $this->domainmap = $map;
        return $map;
    }

    /**
     * The document: version and columns, ready for json_encode().
     *
     * @return array
     */
    public function to_document(): array {
        return ['version' => self::VERSION, 'columns' => $this->columns];
    }

    /**
     * The document as the JSON stored in param1.
     *
     * @return string
     */
    public function to_json(): string {
        return json_encode(
            $this->to_document(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}
