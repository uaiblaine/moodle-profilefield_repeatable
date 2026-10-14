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

/**
 * Where an adapter keeps its per-set projection rows.
 *
 * Engine side. The table belongs to the adapter and has the shape of
 * {profilefield_repeatable_data}: id, dataid (the canonical record), fieldid, the owner column,
 * set_id, data (one set as JSON text), timecreated, timemodified, and a unique index on
 * (dataid, set_id), which the PostgreSQL upsert of {@see projector} names.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class store {
    /** @var string Projection table name, without prefix or braces. */
    private string $table;

    /** @var string Column holding the owner of a set: userid for a profile field. */
    private string $ownercolumn;

    /**
     * Constructor.
     *
     * Both names are written into SQL as given: they come from adapter code, never from input.
     *
     * @param string $table Projection table name, without prefix or braces.
     * @param string $ownercolumn Column holding the owner of a set.
     */
    public function __construct(string $table, string $ownercolumn) {
        $this->table = $table;
        $this->ownercolumn = $ownercolumn;
    }

    /**
     * The projection table name, without prefix or braces.
     *
     * @return string
     */
    public function table(): string {
        return $this->table;
    }

    /**
     * The column holding the owner of a set.
     *
     * @return string
     */
    public function owner_column(): string {
        return $this->ownercolumn;
    }
}
