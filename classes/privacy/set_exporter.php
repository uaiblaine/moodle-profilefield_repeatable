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

namespace profilefield_repeatable\privacy;

/**
 * Turns one stored payload into the array a privacy export writes.
 *
 * Engine side: the adapter's provider finds the owner's payloads and their field's schema, and
 * writes the result where its own data lives.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class set_exporter {
    /**
     * The sets of one stored payload, keyed "Set N", each mapping a column name to its value.
     *
     * Every stored set with a value is exported, read through the field's schema (so a payload
     * not yet re-keyed exports the same way). Values stored under a name the schema no longer
     * reads (a removed column) are exported under that raw name. A name already used in the set
     * gets the key or the raw name appended, so no value overwrites another. A pattern value is
     * exported as it is shown ({@see \profilefield_repeatable\local\pattern_columns::format()}),
     * as plain text like every other value: the export is JSON, not HTML.
     *
     * @param \profilefield_repeatable\local\schema $schema The field's column schema.
     * @param string $rawpayload The stored payload.
     * @return array
     */
    public static function export(\profilefield_repeatable\local\schema $schema, string $rawpayload): array {
        $legacy = $schema->legacy_labels();
        $context = \core\context\system::instance();
        $decoded = json_decode($rawpayload, true);

        $sets = [];
        foreach (is_array($decoded) ? $decoded : [] as $rawset) {
            if (!is_array($rawset)) {
                continue;
            }
            $values = [];
            $read = [];
            foreach ($schema->keys() as $key) {
                $read[$key] = true;
                if (isset($legacy[$key])) {
                    $read[$legacy[$key]] = true;
                }
                $label = format_string($schema->label($key), true, ['context' => $context, 'escape' => false]);
                $value = self::export_value(\profilefield_repeatable\helper::column_value($rawset, $key, $legacy[$key] ?? null));
                $patternconfig = $schema->pattern_config($key);
                if ($patternconfig !== null && trim($value) !== '') {
                    $value = \profilefield_repeatable\local\pattern_columns::format($patternconfig, $value);
                }
                $values[self::unique_name($values, $label, $key)] = $value;
            }
            foreach ($rawset as $name => $value) {
                if (!isset($read[$name])) {
                    $values[self::unique_name($values, (string)$name, (string)$name)] = self::export_value($value);
                }
            }

            if (array_filter($values, static fn(string $value): bool => trim($value) !== '')) {
                $sets[get_string('repeatableset', 'profilefield_repeatable', count($sets) + 1)] = $values;
            }
        }

        return $sets;
    }

    /**
     * A name not yet used in an exported set: the name itself, else "name (suffix)", numbered if needed.
     *
     * @param array $values The set built so far.
     * @param string $name
     * @param string $suffix The column key or raw name.
     * @return string
     */
    private static function unique_name(array $values, string $name, string $suffix): string {
        $candidate = $name;
        $counter = 1;
        while (array_key_exists($candidate, $values)) {
            $candidate = $name . ' (' . $suffix . ($counter > 1 ? ' ' . $counter : '') . ')';
            $counter++;
        }
        return $candidate;
    }

    /**
     * One stored value as exported text.
     *
     * @param mixed $value
     * @return string
     */
    private static function export_value($value): string {
        if ($value === null) {
            return '';
        }
        return is_scalar($value) ? (string)$value : (string)json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
