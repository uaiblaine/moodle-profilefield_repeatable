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

namespace profilefield_repeatable\task;

use core\task\adhoc_task;
use core\task\manager;
use profilefield_repeatable\local\migration;

/**
 * Finish the version 2 migration of a field too large to re-key inside the upgrade.
 *
 * Re-keys the payloads, rebuilds the projection and drops the field's legacy labels
 * ({@see migration::finish_field()}); idempotent, so a failed run is simply retried.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rekey_payloads extends adhoc_task {
    #[\Override]
    public function get_name() {
        return get_string('taskrekeypayloads', 'profilefield_repeatable');
    }

    #[\Override]
    public function execute() {
        $fieldid = clean_param($this->get_custom_data()->fieldid ?? 0, PARAM_INT);
        if (empty($fieldid)) {
            return;
        }

        $count = migration::finish_field((int)$fieldid);
        mtrace('profilefield_repeatable: re-keyed ' . $count . ' payload(s) of field ' . $fieldid);
    }

    /**
     * Queue the task for one field, once.
     *
     * @param int $fieldid
     */
    public static function queue_for_field(int $fieldid): void {
        $task = new static();
        $task->set_custom_data(['fieldid' => $fieldid]);
        manager::queue_adhoc_task($task, true);
    }
}
