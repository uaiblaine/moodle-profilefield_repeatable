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

use local_repeatable\local\payload;
use profilefield_repeatable\local\projection\projector;

/**
 * Reprojection of one user's repeatable profile fields.
 *
 * Profile adapter: it joins {user_info_data} to {user_info_field}, which only the profile field
 * knows; the staleness check and the rewrite are the engine's ({@see projector}), through the
 * profile field's {@see source}. Run by the user_updated/user_created observers.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class projection {
    /**
     * Rebuild the rows of every repeatable field of one user whose rows no longer match the payload.
     *
     * @param int $userid
     * @return int Number of {user_info_data} records whose rows were rewritten.
     */
    public static function reproject_user(int $userid): int {
        global $DB;

        if ($userid <= 0) {
            return 0;
        }

        $datarecords = $DB->get_records_sql(
            "SELECT d.id, d.userid, d.fieldid, d.data, f.param1, f.param2, f.param3, f.param4
               FROM {user_info_data} d
               JOIN {user_info_field} f ON f.id = d.fieldid
              WHERE d.userid = :userid
                AND f.datatype = :datatype",
            ['userid' => $userid, 'datatype' => 'repeatable']
        );
        if (empty($datarecords)) {
            return 0;
        }

        $rowsbydataid = [];
        $rows = $DB->get_records(
            source::TABLE,
            ['userid' => $userid],
            'dataid ASC, set_id ASC',
            'id, dataid, set_id, data'
        );
        foreach ($rows as $row) {
            $rowsbydataid[(int)$row->dataid][] = $row;
        }

        $stale = [];
        foreach ($datarecords as $datarecord) {
            $schema = field_schema::from_field((object)[
                'id' => $datarecord->fieldid,
                'param1' => $datarecord->param1,
                'param2' => $datarecord->param2,
                'param3' => $datarecord->param3,
                'param4' => $datarecord->param4,
            ]);
            $payload = payload::normalise($datarecord->data, $schema);
            if (!projector::is_current($payload, $rowsbydataid[(int)$datarecord->id] ?? [])) {
                $stale[] = [(int)$datarecord->id, $schema];
            }
        }

        return projector::write_stale(new source(), $stale);
    }
}
