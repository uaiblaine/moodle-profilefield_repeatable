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
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /** Maximum number of sets accepted per field value. */
    public const MAX_SETS = 200;

    /** Maximum length of one sub-item value in characters. */
    public const MAX_VALUE_LENGTH = 1024;

    /**
     * Fallback domain shortname pattern of dictionary columns.
     *
     * Used only while the companion `local_dictionaries` is absent; {@see self::domain_pattern()} reads the
     * companion's own pattern when it is installed, and a test pins the two to the same value.
     */
    public const DOMAIN_PATTERN = '/^[a-z0-9_]+$/';

    /**
     * The domain shortname pattern in force: the companion's when installed, else the fallback.
     *
     * @return string A preg pattern.
     */
    public static function domain_pattern(): string {
        if (class_exists('\local_dictionaries\resolver')) {
            return \local_dictionaries\resolver::DOMAIN_PATTERN;
        }
        return self::DOMAIN_PATTERN;
    }

    /**
     * The companion's domains for the column editor, or null when the companion is absent.
     *
     * Read through \local_dictionaries\api::list_domains(), which runs no capability check: only
     * the definition form, behind moodle/site:config, calls this. A companion too old to have
     * that method, or one that fails, gives an empty list; the editor then asks for the
     * shortname as text.
     *
     * @return array|null List of ['shortname', 'name' (plain), 'itemcount', 'visibility', 'parentdomain'].
     */
    public static function companion_domains(): ?array {
        if (!class_exists('\local_dictionaries\resolver')) {
            return null;
        }
        if (!method_exists('\local_dictionaries\api', 'list_domains')) {
            return [];
        }

        try {
            $records = \local_dictionaries\api::list_domains();
        } catch (\Throwable $e) {
            return [];
        }

        $context = \core\context\system::instance();
        $domains = [];
        foreach ($records as $record) {
            $record = (array)$record;
            $domains[] = [
                'shortname' => (string)($record['shortname'] ?? ''),
                'name' => format_string((string)($record['name'] ?? ''), true, ['context' => $context, 'escape' => false]),
                'itemcount' => (int)($record['itemcount'] ?? 0),
                'visibility' => (int)($record['visibility'] ?? 0),
                'parentdomain' => isset($record['parentdomain']) ? (string)$record['parentdomain'] : null,
            ];
        }

        return $domains;
    }

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
     * Parse version 1 sub-item labels (param1) into a unique canonical list.
     *
     * One label per line (any line ending), trimmed, blank lines skipped. Duplicates are compared
     * case-insensitively and the first spelling wins. Only {@see local\schema::from_v1()} reads
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
            if (!preg_match(self::domain_pattern(), $domain)) {
                continue;
            }

            $mappings[$canonicalsubitem] = $domain;
        }

        return $mappings;
    }

    /**
     * Build a cross-DB SQL expression extracting one column's text value from a set's JSON.
     *
     * PostgreSQL reads the JSONB value with the ->> operator (casting defensively so a
     * not-yet-migrated text column still works); MySQL/MariaDB use
     * JSON_UNQUOTE(JSON_EXTRACT(...)) with a quoted JSON path. The key is bound as a parameter
     * on both drivers.
     *
     * @param \moodle_database $db
     * @param string $columnsql Aliased reference to the JSON column, e.g. "alias.data".
     * @param string $subitem Column key to extract.
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
     * SQL reading one column of a set's JSON, also under its legacy label while it has one.
     *
     * A field whose payloads are still being re-keyed has projection rows keyed by label
     * ({@see local\schema::legacy_labels()}); COALESCE reads either shape, as the PHP readers do.
     *
     * @param \moodle_database $db
     * @param string $columnsql Aliased reference to the JSON column, e.g. "alias.data".
     * @param local\schema $schema
     * @param string $key Column key.
     * @param string $paramname Bound-parameter name for the key.
     * @param string $legacyparamname Bound-parameter name for the legacy label, used only when there is one.
     * @return array Two-element list: the SQL expression string and its bound parameters.
     */
    public static function sql_column_value(
        \moodle_database $db,
        string $columnsql,
        local\schema $schema,
        string $key,
        string $paramname,
        string $legacyparamname
    ): array {
        [$sql, $params] = self::sql_subitem_value($db, $columnsql, $key, $paramname);
        $legacy = $schema->legacy_labels()[$key] ?? null;
        if ($legacy === null) {
            return [$sql, $params];
        }

        [$legacysql, $legacyparams] = self::sql_subitem_value($db, $columnsql, $legacy, $legacyparamname);
        return ["COALESCE({$sql}, {$legacysql})", $params + $legacyparams];
    }

    /**
     * Normalise a repeatable payload to the columns of a schema.
     *
     * Returns the sets keyed '@@ID#1', '@@ID#2', ... in input order, each holding every column key
     * as a string. A value is read under the column key, or, when the set has no such key, under
     * the column's legacy label ({@see local\schema::legacy_labels()}): a version 1 payload reads
     * the same as its re-keyed form. Silently drops sets with no non-blank value, keeps only the
     * first MAX_SETS remaining sets and truncates values to MAX_VALUE_LENGTH: callers that must
     * reject excess check the raw payload first
     * ({@see \profile_field_repeatable::validate_payload_limits()}).
     *
     * @param mixed $rawpayload JSON string, object or array; anything else yields [].
     * @param local\schema $schema
     * @return array
     */
    public static function normalise_payload($rawpayload, local\schema $schema): array {
        $keys = $schema->keys();
        if (empty($keys)) {
            return [];
        }
        $legacy = $schema->legacy_labels();

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
            foreach ($keys as $key) {
                $value = self::column_value($setdata, $key, $legacy[$key] ?? null) ?? '';
                if (!is_scalar($value)) {
                    $value = '';
                }

                $value = (string)$value;
                if (\core_text::strlen($value) > self::MAX_VALUE_LENGTH) {
                    $value = \core_text::substr($value, 0, self::MAX_VALUE_LENGTH);
                }
                if (trim($value) !== '') {
                    $hasvalue = true;
                }
                $set[$key] = $value;
            }

            if ($hasvalue) {
                $normalised['@@ID#' . $counter] = $set;
                $counter++;
            }
        }

        return $normalised;
    }

    /**
     * The raw value of one column in a decoded set: under the key, else under its legacy label.
     *
     * A JSON null counts as absent, as the SQL reader treats it ({@see self::sql_column_value()}:
     * PostgreSQL's ->> gives NULL for it, which COALESCE passes over).
     *
     * @param array $set Decoded set.
     * @param string $key Column key.
     * @param string|null $legacylabel The key's legacy label, if any.
     * @return mixed|null Null when the set holds no value for the column.
     */
    public static function column_value(array $set, string $key, ?string $legacylabel) {
        if (isset($set[$key])) {
            return $set[$key];
        }
        if ($legacylabel !== null && isset($set[$legacylabel])) {
            return $set[$legacylabel];
        }
        return null;
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
        if (!static::postgres_index_exists($db, $rawtablename, $indexname)) {
            $quotedindex = static::quote_identifier($indexname);
            $db->execute(
                "CREATE INDEX $quotedindex
                   ON $tablename
                USING gin ($columnname jsonb_path_ops)"
            );
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
