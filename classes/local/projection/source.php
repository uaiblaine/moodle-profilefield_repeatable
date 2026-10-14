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

namespace profilefield_repeatable\local\projection;

use profilefield_repeatable\local\schema;

/**
 * What an adapter provides to the engine about its canonical payloads and projection rows.
 *
 * Engine side: the contract every adapter implements ({@see \profilefield_repeatable\local\source}
 * for the profile field). The engine reads canonical payloads and projection rows only through
 * it, so it never names an adapter's tables or the API that owns them. The canonical payload is
 * the single source of truth; the projection is rebuilt from it ({@see projector}).
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface source {
    /**
     * The plugin that owns the data, for messages.
     *
     * @return string Frankenstyle component.
     */
    public function component(): string;

    /**
     * Where the projection rows live.
     *
     * @return store
     */
    public function store(): store;

    /**
     * The column schema of one field, as the adapter reads it.
     *
     * @param int $fieldid
     * @return schema|null Null when the field does not exist.
     */
    public function schema(int $fieldid): ?schema;

    /**
     * One canonical record, read as it is now.
     *
     * @param int $dataid The canonical record's id.
     * @return \stdClass|null {id, fieldid, ownerid, data (the raw payload)}, or null when it is gone.
     */
    public function canonical(int $dataid): ?\stdClass;

    /**
     * Every canonical record of one field, in id order.
     *
     * @param int $fieldid
     * @return \moodle_recordset Records with at least id and data (the raw payload); the caller closes it.
     */
    public function canonical_recordset(int $fieldid): \moodle_recordset;

    /**
     * The projection rows of one owner's value of one field, for their sync times.
     *
     * @param int $fieldid
     * @param int $ownerid
     * @return \stdClass[] Rows (id, set_id, data, timemodified) in set order.
     */
    public function projection_rows(int $fieldid, int $ownerid): array;
}
