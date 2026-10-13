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

require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/upgradelib.php');

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_profilefield_repeatable_upgrade(int $oldversion): bool {
    if ($oldversion < 2026101301) {
        // Column schema v2: one JSON schema in param1, payloads and report identifiers keyed by column key.
        profilefield_repeatable_upgrade_to_schema_v2();
        upgrade_plugin_savepoint(true, 2026101301, 'profilefield', 'repeatable');
    }

    return true;
}
