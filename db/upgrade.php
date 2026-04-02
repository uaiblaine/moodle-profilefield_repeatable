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
 * Upgrade logic for profilefield_repeatable.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine (anderson@blaine.com.br)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/lib.php');

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_profilefield_repeatable_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026030502) {
        $table = new xmldb_table('profilefield_repeatable_data');

        // Legacy schema was EAV-based and must be rebuilt.
        if ($dbman->table_exists($table) && $dbman->field_exists($table, new xmldb_field('subfield'))) {
            $dbman->drop_table($table);
        }

        profilefield_repeatable_upgrade_ensure_storage_table($dbman);
        profilefield_repeatable_upgrade_backfill_storage();
        profilefield_repeatable_ensure_postgres_jsonb_support($DB);

        $fieldids = $DB->get_fieldset_select('user_info_field', 'id', 'datatype = :datatype', ['datatype' => 'repeatable']);
        foreach ($fieldids as $fieldid) {
            \profilefield_repeatable\task\reconcile_indexes::schedule_for_field((int)$fieldid);
        }

        upgrade_plugin_savepoint(true, 2026030502, 'profilefield', 'repeatable');
    }

    return true;
}

/**
 * Ensure the JSON-per-set storage table exists with required columns/indexes.
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
        return;
    }

    $fieldid = new xmldb_field('fieldid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0, 'dataid');
    if (!$dbman->field_exists($table, $fieldid)) {
        $dbman->add_field($table, $fieldid);
    }

    $userid = new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0, 'fieldid');
    if (!$dbman->field_exists($table, $userid)) {
        $dbman->add_field($table, $userid);
    }

    $setid = new xmldb_field('set_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0, 'userid');
    if (!$dbman->field_exists($table, $setid)) {
        $dbman->add_field($table, $setid);
    }

    $data = new xmldb_field('data', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null, 'set_id');
    if (!$dbman->field_exists($table, $data)) {
        $dbman->add_field($table, $data);
    }

    $timecreated = new xmldb_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0, 'data');
    if (!$dbman->field_exists($table, $timecreated)) {
        $dbman->add_field($table, $timecreated);
    }

    $timemodified = new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, 0, 'timecreated');
    if (!$dbman->field_exists($table, $timemodified)) {
        $dbman->add_field($table, $timemodified);
    }

    $oldindex = new xmldb_index('dataid_setid_ix', XMLDB_INDEX_NOTUNIQUE, ['dataid', 'set_id']);
    if ($dbman->index_exists($table, $oldindex)) {
        $dbman->drop_index($table, $oldindex);
    }

    $oldsubfieldindex = new xmldb_index('dataid_subfield_ix', XMLDB_INDEX_NOTUNIQUE, ['dataid', 'subfield']);
    if ($dbman->index_exists($table, $oldsubfieldindex)) {
        $dbman->drop_index($table, $oldsubfieldindex);
    }

    $newunique = new xmldb_index('dataid_setid_uix', XMLDB_INDEX_UNIQUE, ['dataid', 'set_id']);
    if (!$dbman->index_exists($table, $newunique)) {
        $dbman->add_index($table, $newunique);
    }

    $fielduserindex = new xmldb_index('fieldid_userid_ix', XMLDB_INDEX_NOTUNIQUE, ['fieldid', 'userid']);
    if (!$dbman->index_exists($table, $fielduserindex)) {
        $dbman->add_index($table, $fielduserindex);
    }

    $subfield = new xmldb_field('subfield');
    if ($dbman->field_exists($table, $subfield)) {
        $dbman->drop_field($table, $subfield);
    }

    $subvalue = new xmldb_field('subvalue');
    if ($dbman->field_exists($table, $subvalue)) {
        $dbman->drop_field($table, $subvalue);
    }
}

/**
 * Backfill JSON-per-set rows using cached JSON payload from user_info_data.data.
 */
function profilefield_repeatable_upgrade_backfill_storage(): void {
    global $DB;

    if ($DB->record_exists('profilefield_repeatable_data', [])) {
        return;
    }

    $sql = "SELECT uid.id AS dataid, uid.userid, uid.fieldid, uid.data, uif.param1
              FROM {user_info_data} uid
              JOIN {user_info_field} uif ON uif.id = uid.fieldid
             WHERE uif.datatype = :datatype
               AND uid.data IS NOT NULL
               AND uid.data <> ''";

    $recordset = $DB->get_recordset_sql($sql, ['datatype' => 'repeatable']);
    $now = time();

    foreach ($recordset as $record) {
        $subitems = profilefield_repeatable_parse_subitems((string)$record->param1);
        $payload = profilefield_repeatable_normalise_payload((string)$record->data, $subitems);
        if (empty($payload)) {
            continue;
        }

        $setid = 1;
        foreach ($payload as $setdata) {
            $jsonset = json_encode($setdata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($jsonset === false) {
                $setid++;
                continue;
            }

            $DB->insert_record('profilefield_repeatable_data', (object)[
                'dataid' => (int)$record->dataid,
                'fieldid' => (int)$record->fieldid,
                'userid' => (int)$record->userid,
                'set_id' => $setid,
                'data' => $jsonset,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            $setid++;
        }
    }

    $recordset->close();
}
