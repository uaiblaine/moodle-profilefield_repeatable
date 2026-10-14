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
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/upgradelib.php');

/**
 * Upgrade the plugin.
 *
 * Upgrade steps are numbered in the version namespace of the branch they live on, never in
 * another branch's.
 *
 * @param int $oldversion The version being upgraded from.
 * @return bool Always true.
 */
function xmldb_profilefield_repeatable_upgrade(int $oldversion): bool {
    global $DB;

    if ($oldversion < 2024100701) {
        // The PostgreSQL expression and GIN indexes served no Report Builder filter: remove them.
        profilefield_repeatable_drop_index_layer($DB);
        upgrade_plugin_savepoint(true, 2024100701, 'profilefield', 'repeatable');
    }

    return true;
}
