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
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['columns'] = 'Columns';
$string['columns_help'] =
    'The columns each set has, as a JSON schema: {"version": 2, "columns": [{"key": "language", "label": "Language", "mode": "dictionary", "domain": "iso639", "title": true}, ...]}. With JavaScript the column editor writes it. Modes: free, dictionary (codes of a local_dictionaries domain only), dictionary_open (any code), pattern (a mask and/or regular expression from customfield_pattern, Moodle 5.1 or later; a "pattern" object with preset, mask, regex, regexerror and storeformat). Exactly one column is the title; up to 3 are shown in the subline. A column whose key holds stored values can be neither removed nor re-keyed; rename its label instead.';
$string['datasourcerepeatablesets'] = 'Repeatable profile field sets';
$string['entityrepeatablesetnamed'] = '{$a}';
$string['errorsaverejected'] = 'The repeatable sets of field {$a} were not saved: they do not pass the field\'s checks.';
$string['pluginname'] = 'Repeatable set';
$string['privacy:metadata:profilefield_repeatable:data'] = 'Repeatable set user profile field user data';
$string['privacy:metadata:profilefield_repeatable:dataformat'] =
    'The format of repeatable set user profile field user data';
$string['privacy:metadata:profilefield_repeatable:fieldid'] = 'The ID of the profile field';
$string['privacy:metadata:profilefield_repeatable:tableexplanation'] = 'Additional profile data';
$string['privacy:metadata:profilefield_repeatable:userid'] =
    'The ID of the user whose data is stored by the repeatable set user profile field';
$string['privacy:metadata:profilefield_repeatable_data:data'] = 'JSON payload for one repeatable set';
$string['privacy:metadata:profilefield_repeatable_data:dataid'] =
    'Foreign key to the row in user_info_data storing this repeatable field instance';
$string['privacy:metadata:profilefield_repeatable_data:fieldid'] = 'The profile field id';
$string['privacy:metadata:profilefield_repeatable_data:set_id'] = 'The repeatable set number';
$string['privacy:metadata:profilefield_repeatable_data:tableexplanation'] =
    'Repeatable profile field sets stored as JSON payloads';
$string['privacy:metadata:profilefield_repeatable_data:timecreated'] = 'The time when the set row was created';
$string['privacy:metadata:profilefield_repeatable_data:timemodified'] = 'The time when the set row was last modified';
$string['privacy:metadata:profilefield_repeatable_data:userid'] = 'The user id owner of this repeatable field data';
$string['profilewidgettitle'] = 'Repeatable fields';
$string['taskrekeypayloads'] = 'Re-key repeatable profile field values by column key';
$string['taskreprojectsets'] = 'Rebuild repeatable profile field sets for reports';
