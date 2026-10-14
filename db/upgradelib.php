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
 * Upgrade helpers for profilefield_repeatable, kept apart from db/upgrade.php so tests can call them.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Whether an index name is one the r1 PostgreSQL index layer created on the projection table.
 *
 * Only the two exact shapes it built: "pfrd_f<field id>_<16 hex>" (the per-column expression
 * indexes of the reconcile_indexes task) and "pfrd_<8 hex>_gin_ix" (the install hook's GIN index).
 * A prefix test would also take an index an administrator named in the same style.
 *
 * @param string $indexname
 * @return bool
 */
function profilefield_repeatable_is_index_layer_index(string $indexname): bool {
    return preg_match('/^pfrd_f\d+_[0-9a-f]{16}$/', $indexname) === 1
        || preg_match('/^pfrd_[0-9a-f]{8}_gin_ix$/', $indexname) === 1;
}

/**
 * Remove the r1 PostgreSQL index layer: its indexes, the jsonb column type and its queued tasks.
 *
 * Every database family: the queued reconcile_indexes adhoc tasks are deleted, because their class
 * no longer exists and cron would fail on each of them. PostgreSQL only: the layer's indexes on
 * {profilefield_repeatable_data} are dropped (plain DROP INDEX, the upgrade runs no transaction
 * around a step and the site is in maintenance), then the data column goes back to text, as
 * install.xml declares it; the readers cast it to jsonb
 * ({@see \profilefield_repeatable\helper::sql_subitem_value()}). The indexes go first, because
 * neither a jsonb_path_ops index nor a ->> expression accepts a text column. Index names come from
 * pg_indexes and are quoted, never taken from user input. Idempotent.
 *
 * @param moodle_database $db
 * @return void
 */
function profilefield_repeatable_drop_index_layer(moodle_database $db): void {
    $db->delete_records('task_adhoc', ['classname' => '\\profilefield_repeatable\\task\\reconcile_indexes']);

    if ($db->get_dbfamily() !== 'postgres') {
        return;
    }
    if (!$db->get_manager()->table_exists('profilefield_repeatable_data')) {
        return;
    }

    $tablename = $db->get_prefix() . 'profilefield_repeatable_data';
    $indexnames = $db->get_fieldset_sql(
        "SELECT indexname
           FROM pg_indexes
          WHERE schemaname = current_schema()
            AND tablename = :tablename",
        ['tablename' => $tablename]
    );
    foreach ($indexnames as $indexname) {
        if (profilefield_repeatable_is_index_layer_index($indexname)) {
            $db->execute('DROP INDEX IF EXISTS ' . \profilefield_repeatable\helper::quote_identifier($indexname));
        }
    }

    $columntype = $db->get_field_sql(
        "SELECT data_type
           FROM information_schema.columns
          WHERE table_schema = current_schema()
            AND table_name = :tablename
            AND column_name = :columnname",
        ['tablename' => $tablename, 'columnname' => 'data']
    );
    if ($columntype === 'jsonb') {
        $quotedtable = \profilefield_repeatable\helper::quote_identifier($tablename);
        $db->execute("ALTER TABLE {$quotedtable} ALTER COLUMN data TYPE text USING data::text");
        $db->reset_caches(['profilefield_repeatable_data']);
    }
}
