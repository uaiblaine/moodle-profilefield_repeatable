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
 * Helpers of the upgrade steps, kept apart from db/upgrade.php so tests can call them.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_reportbuilder\local\helpers\user_filter_manager;
use profilefield_repeatable\local\migration;
use profilefield_repeatable\local\schema;
use profilefield_repeatable\task\rekey_payloads;

/**
 * Convert every repeatable field from the version 1 parameters to the version 2 column schema.
 *
 * Per field: write the schema and the legacy labels, map saved Report Builder identifiers, then
 * re-key the payloads and rebuild the projection at once, or queue an adhoc task when the field
 * has more than {@see migration::INLINE_LIMIT} payloads. A field already on version 2 is skipped,
 * so a rerun is harmless.
 *
 * @return void
 */
function profilefield_repeatable_upgrade_to_schema_v2(): void {
    global $DB;

    $fields = $DB->get_records('user_info_field', ['datatype' => 'repeatable'], 'id ASC');
    foreach ($fields as $field) {
        $schema = profilefield_repeatable_upgrade_convert_field($field);
        if ($schema === null) {
            continue;
        }

        profilefield_repeatable_upgrade_map_report_identifiers($field, $schema);

        if ($DB->count_records('user_info_data', ['fieldid' => $field->id]) > migration::INLINE_LIMIT) {
            rekey_payloads::queue_for_field((int)$field->id);
        } else {
            migration::finish_field((int)$field->id);
        }
    }
}

/**
 * Write the version 2 schema of one version 1 field and remember its labels.
 *
 * The legacy labels go to the plugin config before param1 changes, so no reader ever sees the
 * new schema without them; param2..param4 are cleared.
 *
 * @param stdClass $field The {user_info_field} record.
 * @return schema|null The new schema with its legacy labels, or null when the field is on version 2.
 */
function profilefield_repeatable_upgrade_convert_field(stdClass $field): ?schema {
    global $CFG, $DB;

    if (schema::decode((string)$field->param1) !== null) {
        return null;
    }

    $schema = schema::from_v1(
        (string)$field->param1,
        (string)$field->param2,
        (string)$field->param3,
        (string)$field->param4
    );
    if (!empty($schema->legacy_labels())) {
        set_config(
            schema::legacy_config_name((int)$field->id),
            json_encode($schema->legacy_labels(), JSON_UNESCAPED_UNICODE),
            'profilefield_repeatable'
        );
    }

    $DB->update_record('user_info_field', (object)[
        'id' => (int)$field->id,
        'param1' => $schema->to_json(),
        'param2' => '',
        'param3' => '',
        'param4' => '',
    ]);

    require_once($CFG->dirroot . '/user/profile/definelib.php');
    profile_purge_user_fields_cache();

    return $schema;
}

/**
 * Old Report Builder identifier => new one, for every column of a converted field.
 *
 * Version 1 named a field's columns by 1-based sub-item index: "subitemN" for a plain one,
 * "subitemnameN" and "subitemcodeN" for a mapped one; version 2 names them by key
 * ({@see schema::report_identifiers()}). "setnumber" keeps its name.
 *
 * @param string $entityname The entity name, "repeatable_<shortname>".
 * @param schema $schema The converted schema, in version 1 order.
 * @return string[] Old unique identifier => new unique identifier.
 */
function profilefield_repeatable_upgrade_report_identifier_map(string $entityname, schema $schema): array {
    $map = [];
    foreach ($schema->keys() as $index => $key) {
        $position = $index + 1;
        foreach ($schema->report_identifiers($key) as $kind => $identifier) {
            $old = ['value' => 'subitem', 'name' => 'subitemname', 'code' => 'subitemcode'][$kind] . $position;
            $map["{$entityname}:{$old}"] = "{$entityname}:{$identifier}";
        }
    }
    return $map;
}

/**
 * Rename the saved columns, filters, conditions and filter values of one converted field.
 *
 * Only reports of this plugin's datasource are touched. Each row is renamed from what it was,
 * one row at a time, so an old name that equals another column's new name cannot chain.
 * Condition and user filter values are keyed "<uniqueidentifier>_<suffix>" and are renamed too.
 *
 * @param stdClass $field The {user_info_field} record (shortname).
 * @param schema $schema The converted schema.
 * @return void
 */
function profilefield_repeatable_upgrade_map_report_identifiers(stdClass $field, schema $schema): void {
    global $DB;

    $map = profilefield_repeatable_upgrade_report_identifier_map('repeatable_' . $field->shortname, $schema);
    $source = 'profilefield_repeatable\\reportbuilder\\datasource\\repeatable_sets';
    $reportids = $DB->get_fieldset_select(
        'reportbuilder_report',
        'id',
        'source = :source OR source = :rootsource',
        ['source' => $source, 'rootsource' => '\\' . $source]
    );
    if (empty($map) || empty($reportids)) {
        return;
    }

    [$insql, $params] = $DB->get_in_or_equal(array_keys($map), SQL_PARAMS_NAMED, 'uid');
    [$reportsql, $reportparams] = $DB->get_in_or_equal($reportids, SQL_PARAMS_NAMED, 'rid');
    foreach (['reportbuilder_column', 'reportbuilder_filter'] as $table) {
        $rows = $DB->get_records_select(
            $table,
            "reportid {$reportsql} AND uniqueidentifier {$insql}",
            $reportparams + $params,
            'id ASC',
            'id, uniqueidentifier'
        );
        foreach ($rows as $row) {
            $DB->set_field($table, 'uniqueidentifier', $map[$row->uniqueidentifier], ['id' => $row->id]);
        }
    }

    foreach ($reportids as $reportid) {
        $conditiondata = $DB->get_field('reportbuilder_report', 'conditiondata', ['id' => $reportid]);
        $renamed = profilefield_repeatable_upgrade_rename_value_keys((array)json_decode((string)$conditiondata, true), $map);
        if ($renamed !== null) {
            $DB->set_field('reportbuilder_report', 'conditiondata', json_encode($renamed), ['id' => $reportid]);
        }

        foreach (profilefield_repeatable_upgrade_filter_users((int)$reportid) as $userid) {
            $values = user_filter_manager::get((int)$reportid, (int)$userid);
            $renamed = profilefield_repeatable_upgrade_rename_value_keys($values, $map);
            if ($renamed !== null) {
                user_filter_manager::set((int)$reportid, $renamed, (int)$userid);
            }
        }
    }
}

/**
 * Rename the keys of a filter or condition value array ("<uniqueidentifier>_<suffix>").
 *
 * @param array $values
 * @param string[] $map Old unique identifier => new one.
 * @return array|null The renamed values, or null when nothing changed.
 */
function profilefield_repeatable_upgrade_rename_value_keys(array $values, array $map): ?array {
    $renamed = [];
    $changed = false;
    foreach ($values as $name => $value) {
        $newname = $name;
        foreach ($map as $old => $new) {
            // The "_" ends the identifier: "subitem1_" never matches "subitem10_operator".
            if (strpos((string)$name, $old . '_') === 0) {
                $newname = $new . substr((string)$name, strlen($old));
                $changed = true;
                break;
            }
        }
        $renamed[$newname] = $value;
    }
    return $changed ? $renamed : null;
}

/**
 * The users with saved filter values on one report.
 *
 * Moodle 5.0 and later keep them in {reportbuilder_user_filter}; 4.5 keeps them in user
 * preferences named "reportbuilder-report-<reportid>-<chunk>".
 *
 * @param int $reportid
 * @return int[]
 */
function profilefield_repeatable_upgrade_filter_users(int $reportid): array {
    global $DB;

    if ($DB->get_manager()->table_exists('reportbuilder_user_filter')) {
        $userids = $DB->get_fieldset_select('reportbuilder_user_filter', 'usercreated', 'reportid = :reportid', [
            'reportid' => $reportid,
        ]);
    } else {
        $userids = $DB->get_fieldset_select('user_preferences', 'userid', 'name = :name', [
            'name' => 'reportbuilder-report-' . $reportid . '-0',
        ]);
    }

    return array_values(array_unique(array_map('intval', $userids)));
}
