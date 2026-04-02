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
 * Strings for component 'profilefield_repeatable'.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine (anderson@blaine.com.br)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['addnewset'] = 'Add new set';
$string['errorinvalidjson'] = 'Invalid repeatable payload; expected a JSON object.';
$string['errorindexedsubitemlimit'] = 'You can index at most {$a} sub-items for this field.';
$string['errorindexedsubitemunknown'] = 'Indexed sub-item(s) not found in Sub-items: {$a}.';
$string['errordomainmapformat'] = 'Invalid reference mapping format: {$a}. Use "subitem|domain" (one per line).';
$string['errordomainmapunknownsubitem'] = 'Reference mapping sub-item(s) not found in Sub-items: {$a}.';
$string['errordomainmapduplicatesubitem'] = 'Reference mapping duplicates for sub-item(s): {$a}.';
$string['errordomainmapinvaliddomain'] = 'Invalid domain shortname(s): {$a}. Allowed pattern: lowercase letters, numbers, underscore.';
$string['errordomainmapmissingdomain'] = 'Reference domain(s) not found in local_profilefield_repeatable: {$a}.';
$string['errorsubitemsduplicate'] = 'Sub-items must be unique (case-insensitive).';
$string['errorsubitemsrequired'] = 'Please provide at least one sub-item, one per line.';
$string['displaytableinfo'] = 'Information';
$string['displaytablevalue'] = 'Value';
$string['indexedsubitems'] = 'Indexed sub-items (one per line)';
$string['indexedsubitems_help'] =
    'Optionally list sub-items that should receive dedicated PostgreSQL indexes. Each line must match a sub-item exactly.';
$string['referencesubitemdomains'] = 'Reference mapping (subitem|domain, one per line)';
$string['referencesubitemdomains_help'] =
    'Optional mapping to resolve code values to labels in profile display. Format each line as "subitem|domain", where domain is a shortname from local_profilefield_repeatable.';
$string['pluginname'] = 'Repeatable set';
$string['setcolumn'] = 'SET';
$string['actioncolumn'] = 'ACTION';
$string['privacy:metadata:profilefield_repeatable:userid'] =
    'The ID of the user whose data is stored by the repeatable set user profile field';
$string['privacy:metadata:profilefield_repeatable:fieldid'] = 'The ID of the profile field';
$string['privacy:metadata:profilefield_repeatable:data'] = 'Repeatable set user profile field user data';
$string['privacy:metadata:profilefield_repeatable:dataformat'] =
    'The format of repeatable set user profile field user data';
$string['privacy:metadata:profilefield_repeatable:tableexplanation'] = 'Additional profile data';
$string['privacy:metadata:profilefield_repeatable_data:dataid'] =
    'Foreign key to the row in user_info_data storing this repeatable field instance';
$string['privacy:metadata:profilefield_repeatable_data:fieldid'] = 'The profile field id';
$string['privacy:metadata:profilefield_repeatable_data:userid'] = 'The user id owner of this repeatable field data';
$string['privacy:metadata:profilefield_repeatable_data:set_id'] = 'The repeatable set number';
$string['privacy:metadata:profilefield_repeatable_data:data'] = 'JSON payload for one repeatable set';
$string['privacy:metadata:profilefield_repeatable_data:timecreated'] = 'The time when the set row was created';
$string['privacy:metadata:profilefield_repeatable_data:timemodified'] = 'The time when the set row was last modified';
$string['privacy:metadata:profilefield_repeatable_data:tableexplanation'] =
    'Repeatable profile field sets stored as JSON payloads';
$string['removeset'] = 'Remove set';
$string['repeatableset'] = 'Set {$a}';
$string['subitems'] = 'Sub-items (one per line)';
$string['subitems_help'] =
    'Define each sub-item label on a new line. These labels will become text inputs inside each repeatable set.';
$string['synchronisedat'] = 'Synchronised at: {$a}';
$string['taskreconcileindexes'] = 'Reconcile repeatable profile field JSON indexes';
