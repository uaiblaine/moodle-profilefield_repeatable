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

use local_repeatable\local\bridge\dictionaries;
use local_repeatable\local\payload;
use local_repeatable\local\schema;

/**
 * The column schema of a {user_info_field} record, version 2 or version 1.
 *
 * Profile adapter: the one reader of a profile field's param1..param4 and of the plugin config
 * that keeps a converted field's legacy labels. The schema itself ({@see schema}) is engine side
 * and never reads a profile field.
 *
 * Fields still on the version 1 parameters (param1 lists the labels, param2 indexed ones that
 * nothing reads, param3 "label|domain" mappings, param4 the subline) are read through
 * {@see self::from_v1()}, whose payloads are keyed by label until the payloads are re-keyed
 * ({@see migration}).
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class field_schema {
    /** @var string Prefix of the plugin config holding a field's key => version 1 label map. */
    public const LEGACY_CONFIG_PREFIX = 'legacykeys_';

    /**
     * Build the version 2 schema equivalent to the version 1 parameters.
     *
     * Keys come from the labels ({@see schema::generate_key()}); mapped columns become strict
     * dictionaries, the others free text; the first column is the title, as version 1 displayed
     * it. Unknown, duplicate and surplus entries of param4 are dropped, as version 1 reads them.
     * Every key remembers its label as a legacy label.
     *
     * A key never equals another column's label (or that label's "_name"/"_code" form): a
     * version 1 set holds values under the labels, and a key matching another column's label
     * would read that column's value ({@see payload::column_value()}).
     *
     * @param string $param1 Labels, one per line.
     * @param string $param2 Indexed labels, one per line; ignored, because nothing is indexed.
     * @param string $param3 "label|domain" mappings, one per line.
     * @param string $param4 Subline labels, one per line.
     * @return schema
     */
    public static function from_v1(string $param1, string $param2 = '', string $param3 = '', string $param4 = ''): schema {
        $labels = self::parse_subitems($param1);
        $domains = self::parse_subitem_domain_map($param3, $labels);
        $subline = self::v1_label_set($param4, $labels, schema::MAX_SUBLINE, $labels[0] ?? null);

        $columns = [];
        $taken = [];
        $legacy = [];
        foreach ($labels as $position => $label) {
            $otherlabels = [];
            foreach ($labels as $other) {
                if ($other !== $label) {
                    $otherlabels = array_merge($otherlabels, schema::reserved_identifiers($other));
                }
            }
            $key = schema::generate_key($label, array_merge($taken, $otherlabels));
            $taken = array_merge($taken, schema::reserved_identifiers($key));
            $column = ['key' => $key, 'label' => $label, 'mode' => schema::MODE_FREE];
            if (isset($domains[$label])) {
                $column['mode'] = schema::MODE_DICTIONARY;
                $column['domain'] = $domains[$label];
            }
            $column['title'] = $position === 0;
            $column['subline'] = isset($subline[$label]);
            $columns[] = $column;
            $legacy[$key] = $label;
        }

        return schema::from_columns($columns)->with_legacy_labels($legacy);
    }

    /**
     * The canonical labels a version 1 list names, in list order, unique and capped.
     *
     * @param string $raw One label per line, matched case-insensitively.
     * @param string[] $labels Canonical labels.
     * @param int $limit
     * @param string|null $exclude A canonical label left out (the title, for the subline).
     * @return array Canonical label => true.
     */
    private static function v1_label_set(string $raw, array $labels, int $limit, ?string $exclude): array {
        $canonical = [];
        foreach ($labels as $label) {
            $canonical[\core_text::strtolower($label)] = $label;
        }

        $set = [];
        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $label = $canonical[\core_text::strtolower(trim($line))] ?? null;
            if ($label === null || $label === $exclude || isset($set[$label])) {
                continue;
            }
            $set[$label] = true;
            if (count($set) >= $limit) {
                break;
            }
        }

        return $set;
    }

    /**
     * The schema of a {user_info_field} record, version 2 or version 1.
     *
     * A version 2 field whose payloads are not re-keyed yet carries its legacy labels in the
     * plugin config ({@see self::legacy_config_name()}).
     *
     * @param \stdClass $field Record with param1..param4 and, for the legacy labels, id.
     * @return schema
     */
    public static function from_field(\stdClass $field): schema {
        $schema = schema::from_json((string)($field->param1 ?? ''));
        if ($schema === null) {
            return self::from_v1(
                (string)($field->param1 ?? ''),
                (string)($field->param2 ?? ''),
                (string)($field->param3 ?? ''),
                (string)($field->param4 ?? '')
            );
        }

        if (!empty($field->id)) {
            $legacy = get_config('profilefield_repeatable', self::legacy_config_name((int)$field->id));
            $legacy = is_string($legacy) ? json_decode($legacy, true) : null;
            if (is_array($legacy)) {
                $schema = $schema->with_legacy_labels($legacy);
            }
        }

        return $schema;
    }

    /**
     * Name of the plugin config holding a field's legacy labels while its payloads are re-keyed.
     *
     * @param int $fieldid
     * @return string
     */
    public static function legacy_config_name(int $fieldid): string {
        return self::LEGACY_CONFIG_PREFIX . $fieldid;
    }

    /**
     * Parse version 1 sub-item labels (param1) into a unique canonical list.
     *
     * One label per line (any line ending), trimmed, blank lines skipped. Duplicates are compared
     * case-insensitively and the first spelling wins. Only {@see self::from_v1()} reads
     * version 1 parameters.
     *
     * @param string $rawsubitems
     * @return string[]
     */
    public static function parse_subitems(string $rawsubitems): array {
        $lines = preg_split('/\R/u', $rawsubitems) ?: [];
        $subitems = [];
        $seen = [];

        foreach ($lines as $line) {
            $subitem = trim($line);
            if ($subitem === '') {
                continue;
            }

            $normalised = \core_text::strtolower($subitem);
            if (isset($seen[$normalised])) {
                continue;
            }

            $seen[$normalised] = true;
            $subitems[] = $subitem;
        }

        return $subitems;
    }

    /**
     * Parse version 1 subitem/domain mapping lines with format "subitem|domain" (param3).
     *
     * @param string $rawmappings Raw param3 contents.
     * @param string[] $subitems Canonical configured sub-items.
     * @return array<string, string> Map of canonical sub-item to domain shortname.
     */
    public static function parse_subitem_domain_map(string $rawmappings, array $subitems): array {
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
            if (!preg_match(dictionaries::domain_pattern(), $domain)) {
                continue;
            }

            $mappings[$canonicalsubitem] = $domain;
        }

        return $mappings;
    }
}
