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

use core_text;

/**
 * Validation of a submitted repeatable payload, and its pattern values put in shape.
 *
 * Engine side: it decides nothing from a profile-field API. The adapter hands it the decisions
 * that live there: the stored payload (the owner's recorded value, never the submission), who may
 * read a dictionary (a callable deciding for the editing user, on which every restricted-dictionary
 * rule rests) and whether the companion's lookup API and customfield_pattern count as present
 * (the adapters' test seams).
 *
 * Like the required and code checks of a form, web services and sync skip the checks of
 * {@see self::error()}; {@see self::for_storage()} runs on every save path.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class validator {
    /** @var schema The field's column schema. */
    private schema $schema;

    /** @var string The owner's stored payload, as recorded. */
    private string $storedpayload;

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
     * @param string $storedpayload The owner's stored payload (raw JSON, '' when none).
     * @param callable $canread fn(string $domain): bool, whether the editing user may read the domain;
     *                          false for every domain when the companion is absent.
     * @param bool $lookupavailable Whether the companion offers what code checks need.
     * @param bool $patternavailable Whether customfield_pattern offers what pattern columns need.
     */
    public function __construct(
        schema $schema,
        string $storedpayload,
        callable $canread,
        bool $lookupavailable,
        bool $patternavailable
    ) {
        $this->schema = $schema;
        $this->storedpayload = $storedpayload;
        $this->canread = \Closure::fromCallable($canread);
        $this->lookupavailable = $lookupavailable;
        $this->patternavailable = $patternavailable;
    }

    /**
     * The error of a raw submitted payload: not a JSON object, or over the caps.
     *
     * Runs on the raw text because normalisation silently enforces the caps; a blank value counts
     * as an empty object.
     *
     * @param string $rawpayload Raw submitted JSON.
     * @return string|null Error message, or null when the payload may be normalised.
     */
    public function raw_error(string $rawpayload): ?string {
        $rawpayload = trim($rawpayload);
        if ($rawpayload === '') {
            $rawpayload = '{}';
        }
        if (!$this->is_valid_json_object($rawpayload)) {
            return get_string('errorinvalidjson', 'profilefield_repeatable');
        }

        return $this->validate_payload_limits(json_decode($rawpayload, true));
    }

    /**
     * The first error of a normalised payload, in the order the edit form reports them.
     *
     * At least one set when the field is required, then the required columns of every set, the
     * dictionary codes and the pattern values.
     *
     * @param array $payload Normalised payload.
     * @param bool $fieldrequired Whether the field asks for at least one set.
     * @return string|null Error message, or null.
     */
    public function error(array $payload, bool $fieldrequired): ?string {
        if ($fieldrequired && empty($payload)) {
            return get_string('required');
        }
        $requirederror = $this->validate_required_columns($payload);
        if ($requirederror !== null) {
            return $requirederror;
        }
        $codeerror = $this->validate_dictionary_codes($payload);
        if ($codeerror !== null) {
            return $codeerror;
        }
        $patternerror = $this->validate_pattern_values($payload);
        if ($patternerror !== null) {
            return $patternerror;
        }

        return null;
    }

    /**
     * A normalised payload as it is stored: pattern values normalised.
     *
     * Pattern values are stored as customfield_pattern stores them (fitted into the mask, masked
     * or raw per the column's "Store as"), also when a web service or CSV upload skips the
     * validation; a value that does not fit is stored trimmed, as typed.
     *
     * @param array $payload Normalised payload.
     * @return array
     */
    public function for_storage(array $payload): array {
        return $this->map_pattern_values($payload, [pattern_columns::class, 'normalise']);
    }

    /**
     * A normalised payload as it is edited: pattern values formatted.
     *
     * A pattern value is edited as it is shown: a raw-stored value gets its mask back, and is
     * stored raw again on save.
     *
     * @param array $payload Normalised payload.
     * @return array
     */
    public function for_editing(array $payload): array {
        return $this->map_pattern_values($payload, [pattern_columns::class, 'format']);
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
     * Apply a function of the customfield_pattern API to every pattern value of a payload.
     *
     * @param array $payload Normalised payload.
     * @param callable $function fn(array $config, string $value): string.
     * @return array The payload with each non-empty pattern value replaced; unchanged without
     *               the plugin or without pattern columns.
     */
    private function map_pattern_values(array $payload, callable $function): array {
        if (!$this->patternavailable) {
            return $payload;
        }
        $schema = $this->schema;
        $configs = [];
        foreach ($schema->keys() as $key) {
            $config = $schema->pattern_config($key);
            if ($config !== null) {
                $configs[$key] = $config;
            }
        }
        if (empty($configs)) {
            return $payload;
        }

        foreach ($payload as $setkey => $set) {
            foreach ($configs as $key => $config) {
                $value = (string)($set[$key] ?? '');
                if (trim($value) !== '') {
                    $payload[$setkey][$key] = $function($config, $value);
                }
            }
        }
        return $payload;
    }

    /**
     * Check a raw payload against helper::MAX_SETS and helper::MAX_VALUE_LENGTH.
     *
     * Runs on the raw decoded JSON because normalisation silently enforces the
     * same caps; validating after normalising could never see the excess.
     *
     * @param mixed $decoded Raw decoded JSON payload.
     * @return string|null Error message, or null when within limits.
     */
    private function validate_payload_limits($decoded): ?string {
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

        // A value counts under every name the normaliser reads it from: the key and a legacy label.
        $schema = $this->schema;
        $sourcekeys = array_fill_keys(array_merge($schema->keys(), array_values($schema->legacy_labels())), true);
        foreach ($decoded as $setdata) {
            if (!is_array($setdata)) {
                continue;
            }
            foreach ($setdata as $sourcekey => $value) {
                if (!isset($sourcekeys[$sourcekey]) || !is_scalar($value)) {
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
     * Check that every set fills the columns flagged required.
     *
     * The field-level "required" setting asks for at least one set; this asks each set that
     * has any value for every required column.
     *
     * @param array $payload Normalised payload.
     * @return string|null Error naming the first set and column that fail, or null.
     */
    private function validate_required_columns(array $payload): ?string {
        $schema = $this->schema;
        $requiredkeys = $schema->required_keys();
        if (empty($requiredkeys)) {
            return null;
        }

        $setnumber = 0;
        foreach ($payload as $set) {
            $setnumber++;
            foreach ($requiredkeys as $key) {
                if (trim((string)($set[$key] ?? '')) === '') {
                    return get_string('errorcolumnrequired', 'profilefield_repeatable', (object)[
                        'set' => $setnumber,
                        'column' => format_string($schema->label($key), true, [
                            'context' => \core\context\system::instance(),
                        ]),
                    ]);
                }
            }
        }

        return null;
    }

    /**
     * Check every non-empty value of the pattern columns against customfield_pattern.
     *
     * Like the required and code checks, web services and sync skip it. Without the plugin the
     * values are kept as typed.
     *
     * @param array $payload Normalised payload.
     * @return string|null Error naming the first set and column that fail, with the plugin's
     *                     message, or null.
     */
    private function validate_pattern_values(array $payload): ?string {
        if (empty($payload) || !$this->patternavailable) {
            return null;
        }
        $schema = $this->schema;

        $setnumber = 0;
        foreach ($payload as $set) {
            $setnumber++;
            foreach ($schema->keys() as $key) {
                $config = $schema->pattern_config($key);
                $value = (string)($set[$key] ?? '');
                if ($config === null || trim($value) === '') {
                    continue;
                }
                $error = \profilefield_repeatable\local\pattern_columns::validate($config, $value);
                if ($error !== null) {
                    // The message is customfield_pattern's, already safe HTML like get_string()'s.
                    return get_string('errorpattern', 'profilefield_repeatable', (object)[
                        'set' => $setnumber,
                        'column' => format_string($schema->label($key), true, [
                            'context' => \core\context\system::instance(),
                        ]),
                        'error' => $error,
                    ]);
                }
            }
        }

        return null;
    }

    /**
     * Check the codes of the dictionary columns against the companion.
     *
     * In a domain the editing user may read ({@see self::can_read_domain()}):
     * - a "codes only" column accepts a code that exists and is active, or the code the profile
     *   owner already has stored under that column (a code deactivated or removed since stays
     *   until it is changed); an "any code" column accepts every code, its length capped like any
     *   value;
     * - a column with a parent, when the parent column's domain is readable too and the item has
     *   a parent: the parent column must be filled, with the item's parent code (folded like the
     *   companion's code key) in the item's parent domain.
     *
     * In a domain the editing user may not read, nothing derived from the dictionary reaches them:
     * a "codes only" column accepts the stored codes and refuses every other code with one message,
     * whether it exists or not; an "any code" column takes the code as text. The dictionary is not
     * even looked up.
     *
     * Runs only when the companion offers \local_dictionaries\api::lookup(): without it the codes
     * are kept as typed text. One lookup per readable domain, however many sets.
     *
     * @param array $payload Normalised payload.
     * @return string|null Error naming the first set and column that fail, or null.
     */
    private function validate_dictionary_codes(array $payload): ?string {
        if (empty($payload) || !$this->lookupavailable) {
            return null;
        }
        $schema = $this->schema;
        $domains = $schema->domain_map();
        if (empty($domains)) {
            return null;
        }
        $readable = [];
        foreach (array_unique($domains) as $domain) {
            $readable[$domain] = $this->can_read_domain($domain);
        }

        $codes = [];
        foreach ($payload as $set) {
            foreach ($domains as $key => $domain) {
                $code = trim((string)($set[$key] ?? ''));
                if ($code !== '' && $readable[$domain]) {
                    $codes[$domain][$code] = true;
                }
            }
        }
        $items = [];
        foreach ($codes as $domain => $list) {
            $items[$domain] = \local_dictionaries\api::lookup($domain, array_keys($list));
        }
        $stored = $this->stored_codes();

        $setnumber = 0;
        foreach ($payload as $set) {
            $setnumber++;
            foreach ($schema->columns() as $column) {
                $error = $this->column_code_error($column, $set, $setnumber, $domains, $readable, $items, $stored);
                if ($error !== null) {
                    return $error;
                }
            }
        }

        return null;
    }

    /**
     * The error of one column in one set, or null ({@see self::validate_dictionary_codes()}).
     *
     * @param array $column Normalised schema column.
     * @param array $set Normalised set: key => value.
     * @param int $setnumber
     * @param array $domains Key => domain of the dictionary columns.
     * @param array $readable Domain => whether the editing user may read it.
     * @param array $items Domain => caller code => item, from \local_dictionaries\api::lookup().
     * @param array $stored Key => [folded code => true] stored for the profile owner.
     * @return string|null
     */
    private function column_code_error(
        array $column,
        array $set,
        int $setnumber,
        array $domains,
        array $readable,
        array $items,
        array $stored
    ): ?string {
        $key = $column['key'];
        $code = trim((string)($set[$key] ?? ''));
        if ($code === '' || !isset($domains[$key])) {
            return null;
        }
        $strict = $column['mode'] === \profilefield_repeatable\local\schema::MODE_DICTIONARY;
        $isstored = isset($stored[$key][\profilefield_repeatable\helper::fold_code($code)]);

        if (!$readable[$domains[$key]]) {
            if ($strict && !$isstored) {
                return $this->code_error('errorcoderestricted', $setnumber, $key, $code);
            }
            return null;
        }

        $item = $items[$domains[$key]][$code] ?? null;
        if ($strict && ($item === null || !$item['active']) && !$isstored) {
            return $this->code_error($item === null ? 'errorcodeunknown' : 'errorcodeinactive', $setnumber, $key, $code);
        }

        $parentkey = $column['parent'] ?? '';
        if (
            $item === null || $item['parentcode'] === null || $parentkey === '' || !isset($domains[$parentkey])
            || !$readable[$domains[$parentkey]]
        ) {
            return null;
        }
        $parentcode = trim((string)($set[$parentkey] ?? ''));
        if ($parentcode === '') {
            return $this->code_error('errorcodeparentmissing', $setnumber, $key, $code, $parentkey);
        }
        $sameparent = \profilefield_repeatable\helper::fold_code($item['parentcode'])
            === \profilefield_repeatable\helper::fold_code($parentcode);
        $samedomain = \core_text::strtolower(trim((string)$item['parentdomain'])) === $domains[$parentkey];
        if (!$sameparent || !$samedomain) {
            return $this->code_error('errorcodeparent', $setnumber, $key, $code, $parentkey, $parentcode);
        }

        return null;
    }

    /**
     * The folded codes the owner has stored, per column key.
     *
     * Read from the stored payload the adapter passed in (the owner's recorded value): the
     * submitted payload never counts as stored.
     *
     * @return array Key => [folded code => true].
     */
    public function stored_codes(): array {
        $stored = [];
        foreach (\profilefield_repeatable\helper::normalise_payload($this->storedpayload, $this->schema) as $set) {
            foreach ($set as $key => $value) {
                $value = trim((string)$value);
                if ($value !== '') {
                    $stored[$key][\profilefield_repeatable\helper::fold_code($value)] = true;
                }
            }
        }
        return $stored;
    }

    /**
     * One escaped code validation message.
     *
     * @param string $identifier Lang string identifier.
     * @param int $setnumber
     * @param string $key Column key.
     * @param string $code The submitted code.
     * @param string $parentkey Parent column key, for errorcodeparent and errorcodeparentmissing.
     * @param string $parentcode The parent column's code, for errorcodeparent.
     * @return string
     */
    private function code_error(
        string $identifier,
        int $setnumber,
        string $key,
        string $code,
        string $parentkey = '',
        string $parentcode = ''
    ): string {
        $schema = $this->schema;
        $options = ['context' => \core\context\system::instance()];
        $a = (object)[
            'set' => $setnumber,
            'column' => format_string($schema->label($key), true, $options),
            'code' => s($code),
        ];
        if ($parentkey !== '') {
            $a->parent = format_string($schema->label($parentkey), true, $options);
            $a->parentcode = s($parentcode);
        }
        return get_string($identifier, 'profilefield_repeatable', $a);
    }

    /**
     * Validate if provided value is a JSON object.
     *
     * @param string $rawpayload
     * @return bool
     */
    private function is_valid_json_object(string $rawpayload): bool {
        $decoded = json_decode($rawpayload);
        return json_last_error() === JSON_ERROR_NONE && is_object($decoded);
    }
}
