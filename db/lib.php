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
 * Procedural wrapper delegating to \profilefield_repeatable\helper.
 *
 * Loaded by db/install.php. Plugin classes do autoload during install and
 * upgrade (the caches are disabled, so core rescans the class map), so the wrapper is not needed
 * for that; it is kept as the single procedural entry point the install hook calls, and the
 * require_once below makes the helper available without relying on the class map being fresh.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Load the helper class directly, independent of the class map.
require_once(__DIR__ . '/../classes/helper.php');

/**
 * Ensure PostgreSQL JSONB type and base GIN index exist for storage table.
 *
 * @param moodle_database $db
 */
function profilefield_repeatable_ensure_postgres_jsonb_support(moodle_database $db): void {
    \profilefield_repeatable\helper::ensure_postgres_jsonb_support($db);
}
