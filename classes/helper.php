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

namespace profilefield_repeatable;

/**
 * Shared helpers for profilefield_repeatable.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine (anderson@blaine.com.br)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /** PostgreSQL feature flag: SQL/JSON path operators. */
    public const POSTGRES_FEATURE_JSONPATH = 'jsonpath';

    /** PostgreSQL feature flag gating {@see self::maybe_optimize_gin_deduplication()} (server 14+). */
    public const POSTGRES_FEATURE_GIN_DEDUP = 'gin_dedup';

    /** Maximum number of sets accepted per field value. */
    public const MAX_SETS = 200;

    /** Maximum length of one sub-item value in characters. */
    public const MAX_VALUE_LENGTH = 1024;

    /**
     * Domain shortname pattern for param3 reference mappings.
     *
     * Keep in step with the copy in define.class.php and with the companion dictionary plugin's
     * resolver, which rejects any other domain shortname.
     */
    public const DOMAIN_PATTERN = '/^[a-z0-9_]+$/';

    /**
     * Check if the active DB family is PostgreSQL.
     *
     * @param \moodle_database $db
     * @return bool
     */
    public static function is_postgres(\moodle_database $db): bool {
        return $db->get_dbfamily() === 'postgres';
    }

    /**
     * Parse sub-item labels into unique canonical list.
     *
     * One label per line (any line ending), trimmed, blank lines skipped. Duplicates are compared
     * case-insensitively and the first spelling wins.
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
     * Parse subitem/domain mapping lines with format "subitem|domain".
     *
     * Shared by display rendering and the Report Builder integration so the
     * reference-mapping format has a single canonical parser.
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
            if (!preg_match(self::DOMAIN_PATTERN, $domain)) {
                continue;
            }

            $mappings[$canonicalsubitem] = $domain;
        }

        return $mappings;
    }

    /**
     * Build a cross-DB SQL expression extracting one sub-item's text value from a set's JSON.
     *
     * PostgreSQL reads the JSONB value with the ->> operator (casting defensively so a
     * not-yet-migrated text column still works); MySQL/MariaDB use
     * JSON_UNQUOTE(JSON_EXTRACT(...)) with a quoted JSON path. The sub-item key is bound
     * as a parameter on both drivers so arbitrary labels are handled safely.
     *
     * @param \moodle_database $db
     * @param string $columnsql Aliased reference to the JSON column, e.g. "alias.data".
     * @param string $subitem Sub-item key to extract.
     * @param string $paramname Unique bound-parameter name to use for the key.
     * @return array Two-element list: the SQL expression string and its bound parameters.
     */
    public static function sql_subitem_value(
        \moodle_database $db,
        string $columnsql,
        string $subitem,
        string $paramname
    ): array {
        if (static::is_postgres($db)) {
            return ["(($columnsql)::jsonb ->> :$paramname)", [$paramname => $subitem]];
        }

        $path = '$."' . str_replace(['\\', '"'], ['\\\\', '\\"'], $subitem) . '"';
        return ["JSON_UNQUOTE(JSON_EXTRACT($columnsql, :$paramname))", [$paramname => $path]];
    }

    /**
     * Normalise repeatable payload keeping only configured sub-items.
     *
     * Returns the sets keyed '@@ID#1', '@@ID#2', ... in input order, each holding every configured
     * sub-item as a string. Silently drops sets with no non-blank value, keeps only the first
     * MAX_SETS remaining sets and truncates values to MAX_VALUE_LENGTH: callers that must reject excess
     * check the raw payload first ({@see \profile_field_repeatable::validate_payload_limits()}).
     *
     * @param mixed $rawpayload JSON string, object or array; anything else yields [].
     * @param string[] $subitems
     * @return array
     */
    public static function normalise_payload($rawpayload, array $subitems): array {
        if (empty($subitems)) {
            return [];
        }

        if (is_string($rawpayload)) {
            $rawpayload = trim($rawpayload);
            if ($rawpayload === '') {
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

        $normalised = [];
        $counter = 1;

        foreach ($decoded as $setdata) {
            if ($counter > self::MAX_SETS) {
                break;
            }

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
                if (\core_text::strlen($value) > self::MAX_VALUE_LENGTH) {
                    $value = \core_text::substr($value, 0, self::MAX_VALUE_LENGTH);
                }
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
     * Encode payload as strict JSON object.
     *
     * @param array $payload
     * @return string JSON, or '{}' when the payload is empty or cannot be encoded.
     */
    public static function encode_payload(array $payload): string {
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
     * Ensure PostgreSQL JSONB type and base GIN index exist for storage table.
     *
     * @param \moodle_database $db
     */
    public static function ensure_postgres_jsonb_support(\moodle_database $db): void {
        if (!static::is_postgres($db)) {
            return;
        }

        $dbman = $db->get_manager();
        $table = new \xmldb_table('profilefield_repeatable_data');
        if (!$dbman->table_exists($table)) {
            return;
        }

        $rawtablename = $db->get_prefix() . 'profilefield_repeatable_data';
        $tablename = static::quote_identifier($rawtablename);
        $columnname = static::quote_identifier('data');

        $columntype = $db->get_field_sql(
            "SELECT data_type
               FROM information_schema.columns
              WHERE table_schema = current_schema()
                AND table_name = :tablename
                AND column_name = :columnname",
            [
                'tablename' => $rawtablename,
                'columnname' => 'data',
            ]
        );

        if ($columntype !== 'jsonb') {
            $db->execute(
                "ALTER TABLE $tablename
                    ALTER COLUMN $columnname TYPE jsonb
                    USING COALESCE(NULLIF($columnname, ''), '{}')::jsonb"
            );
        }

        $indexname = 'pfrd_' . substr(sha1($rawtablename), 0, 8) . '_gin_ix';
        $created = false;
        if (!static::postgres_index_exists($db, $rawtablename, $indexname)) {
            $quotedindex = static::quote_identifier($indexname);
            $db->execute(
                "CREATE INDEX $quotedindex
                   ON $tablename
                USING gin ($columnname jsonb_path_ops)"
            );
            $created = true;
        }

        if ($created) {
            static::maybe_optimize_gin_deduplication($db, $indexname);
        }
    }

    /**
     * Check if a PostgreSQL index exists.
     *
     * @param \moodle_database $db
     * @param string $tablename
     * @param string $indexname
     * @return bool
     */
    public static function postgres_index_exists(\moodle_database $db, string $tablename, string $indexname): bool {
        if (!static::is_postgres($db)) {
            return false;
        }

        $sql = "SELECT 1
                  FROM pg_indexes
                 WHERE schemaname = ANY(current_schemas(false))
                   AND tablename = :tablename
                   AND indexname = :indexname";

        return $db->record_exists_sql($sql, [
            'tablename' => $tablename,
            'indexname' => $indexname,
        ]);
    }

    /**
     * Return PostgreSQL major version number or null when unavailable.
     *
     * @param \moodle_database $db
     * @return int|null
     */
    public static function postgres_major_version(\moodle_database $db): ?int {
        if (!static::is_postgres($db)) {
            return null;
        }

        $version = $db->get_field_sql('SHOW server_version_num');
        if (!is_numeric($version)) {
            return null;
        }

        $versionnum = (int)$version;
        if ($versionnum <= 0) {
            return null;
        }

        return (int)floor($versionnum / 10000);
    }

    /**
     * Check support for a PostgreSQL feature by server major version.
     *
     * @param \moodle_database $db
     * @param string $feature
     * @return bool
     */
    public static function postgres_supports_feature(\moodle_database $db, string $feature): bool {
        $major = static::postgres_major_version($db);
        if ($major === null) {
            return false;
        }

        switch ($feature) {
            case self::POSTGRES_FEATURE_JSONPATH:
                return $major >= 12;
            case self::POSTGRES_FEATURE_GIN_DEDUP:
                return $major >= 14;
            default:
                return false;
        }
    }

    /**
     * Rebuild a GIN index with REINDEX on PostgreSQL 14+.
     *
     * Best effort: a failure is reported through debugging() and otherwise ignored.
     *
     * @param \moodle_database $db
     * @param string $indexname
     */
    public static function maybe_optimize_gin_deduplication(\moodle_database $db, string $indexname): void {
        if (!static::postgres_supports_feature($db, self::POSTGRES_FEATURE_GIN_DEDUP)) {
            return;
        }

        $quotedindex = static::quote_identifier($indexname);
        try {
            $db->execute("REINDEX INDEX $quotedindex");
        } catch (\Throwable $e) {
            debugging(
                'profilefield_repeatable: could not optimize GIN deduplication for index ' .
                $indexname . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }

    /**
     * Quote a SQL identifier for PostgreSQL.
     *
     * @param string $identifier
     * @return string
     * @throws \coding_exception When the identifier contains a NUL byte.
     */
    public static function quote_identifier(string $identifier): string {
        if (strpos($identifier, "\0") !== false) {
            throw new \coding_exception('Invalid SQL identifier provided.');
        }

        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
