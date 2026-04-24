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

/**
 * Backward-compatible wrappers delegating to \profilefield_repeatable\helper.
 *
 * This file is required by db/install.php and db/upgrade.php which cannot
 * rely on class autoloading during upgrade steps.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine (anderson@blaine.com.br)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Ensure autoloading is available for the helper class.
require_once(__DIR__ . '/../classes/helper.php');

/**
 * Check if the active DB family is PostgreSQL.
 *
 * @param moodle_database $db
 * @return bool
 */
function profilefield_repeatable_is_postgres(moodle_database $db): bool {
    return \profilefield_repeatable\helper::is_postgres($db);
}

/**
 * Parse sub-item labels into unique canonical list.
 *
 * @param string $rawsubitems
 * @return string[]
 */
function profilefield_repeatable_parse_subitems(string $rawsubitems): array {
    return \profilefield_repeatable\helper::parse_subitems($rawsubitems);
}

/**
 * Normalise repeatable payload keeping only configured sub-items.
 *
 * @param mixed $rawpayload
 * @param string[] $subitems
 * @return array
 */
function profilefield_repeatable_normalise_payload($rawpayload, array $subitems): array {
    return \profilefield_repeatable\helper::normalise_payload($rawpayload, $subitems);
}

/**
 * Encode payload as strict JSON object.
 *
 * @param array $payload
 * @return string
 */
function profilefield_repeatable_encode_payload(array $payload): string {
    return \profilefield_repeatable\helper::encode_payload($payload);
}

/**
 * Ensure storage table exists with required columns and indexes.
 * Called from install.php during plugin installation.
 *
 * @param database_manager $dbman
 */
function profilefield_repeatable_upgrade_ensure_storage_table(database_manager $dbman): void {
    $table = new xmldb_table('profilefield_repeatable_data');

    if (!$dbman->table_exists($table)) {
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('dataid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('fieldid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('set_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('data', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('dataid_fk', XMLDB_KEY_FOREIGN, ['dataid'], 'user_info_data', ['id']);

        $table->add_index('dataid_setid_uix', XMLDB_INDEX_UNIQUE, ['dataid', 'set_id']);
        $table->add_index('fieldid_userid_ix', XMLDB_INDEX_NOTUNIQUE, ['fieldid', 'userid']);

        $dbman->create_table($table);
    }
}

/**
 * Ensure PostgreSQL JSONB type and base GIN index exist for storage table.
 *
 * @param moodle_database $db
 */
function profilefield_repeatable_ensure_postgres_jsonb_support(moodle_database $db): void {
    \profilefield_repeatable\helper::ensure_postgres_jsonb_support($db);
}

/**
 * Check if a PostgreSQL index exists.
 *
 * @param moodle_database $db
 * @param string $tablename
 * @param string $indexname
 * @return bool
 */
function profilefield_repeatable_postgres_index_exists(moodle_database $db, string $tablename, string $indexname): bool {
    return \profilefield_repeatable\helper::postgres_index_exists($db, $tablename, $indexname);
}

/**
 * Quote a SQL identifier for PostgreSQL.
 *
 * @param string $identifier
 * @return string
 */
function profilefield_repeatable_quote_identifier(string $identifier): string {
    return \profilefield_repeatable\helper::quote_identifier($identifier);
}
