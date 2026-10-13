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

$string['actioncolumn'] = 'ACTION';
$string['addcolumn'] = 'Add column';
$string['addnewset'] = 'Add new set';
$string['choosefirst'] = 'Choose a value for {$a} first.';
$string['columncount'] = '{$a->count} of {$a->max} columns';
$string['columndomain'] = 'Dictionary';
$string['columndomainhint'] = 'From local_dictionaries.';
$string['columndomainnocompanion'] = 'Shortname of a local_dictionaries domain. Without that plugin, values show as stored.';
$string['columnkey'] = 'Key';
$string['columnkeyhint'] = 'Generated from the name; lowercase letters, digits and _ only. Values and reports are stored under it.';
$string['columnkeylocked'] = 'Locked: users have values under this key.';
$string['columnlabel'] = 'Column name';
$string['columnlabelhint'] = 'Shown to users. Multilang tags allowed.';
$string['columnmode'] = 'How users fill it';
$string['columnnew'] = 'New';
$string['columnparent'] = 'Depends on';
$string['columnparenthint'] = 'An earlier dictionary column whose code narrows this one, e.g. cities of the chosen state.';
$string['columnrole'] = 'Role in the set';
$string['columns'] = 'Columns';
$string['columns_help'] =
    'The columns each set has, as a JSON schema: {"version": 2, "columns": [{"key": "language", "label": "Language", "mode": "dictionary", "domain": "iso639", "title": true}, ...]}. With JavaScript the column editor writes it. Modes: free, dictionary (codes of a local_dictionaries domain only), dictionary_open (any code). Exactly one column is the title; up to 3 are shown in the subline and up to 3 are indexed. A column whose key holds stored values can be neither removed nor re-keyed; rename its label instead.';
$string['columnsindata'] =
    '{$a} users have data in this field. Columns holding stored values cannot be removed and their keys are locked: renaming such a column changes only its name.';
$string['columnsubitem'] = '{$a}';
$string['columnsubitemcode'] = '{$a} (code)';
$string['columnsubitemname'] = '{$a} (name)';
$string['datasourcerepeatablesets'] = 'Repeatable profile field sets';
$string['domainoption'] = '{$a->name} ({$a->itemcount} items)';
$string['domainrestricted'] = 'Restricted: only users who can view dictionaries can search it.';
$string['domainvisible'] = 'Searchable by any logged-in user.';
$string['entityrepeatableset'] = 'Repeatable set';
$string['entityrepeatablesetnamed'] = '{$a}';
$string['errorcodeinactive'] = 'Set {$a->set}: the code "{$a->code}" of {$a->column} is no longer offered.';
$string['errorcodeparent'] = 'Set {$a->set}: {$a->column} "{$a->code}" does not belong to {$a->parent} "{$a->parentcode}".';
$string['errorcodeparentmissing'] = 'Set {$a->set}: choose a value for {$a->parent} before {$a->column}.';
$string['errorcoderestricted'] = 'Set {$a->set}: {$a->column} is a restricted list. Choose a value an administrator set, or ask for access.';
$string['errorcodeunknown'] = 'Set {$a->set}: "{$a->code}" is not a code of {$a->column}.';
$string['errorcolumnrequired'] = 'Set {$a->set}: {$a->column} is required.';
$string['errorcolumnslimit'] = 'A field can have at most {$a} columns.';
$string['errorcolumnsrequired'] = 'Add at least one column.';
$string['errordomaininvalid'] = 'Column "{$a}": choose a dictionary (lowercase letters, digits and underscore).';
$string['errordomainmissing'] = 'Dictionary not found in local_dictionaries: {$a}.';
$string['errorindexedlimit'] = 'At most {$a} columns can be indexed.';
$string['errorinvalidjson'] = 'Invalid repeatable payload; expected a JSON object.';
$string['errorkeyconflict'] = 'Two columns produce the report column "{$a}": change one of the keys.';
$string['errorkeyduplicate'] = 'Key used by two columns: {$a}.';
$string['errorkeyinvalid'] =
    'Invalid key "{$a}": start with a lowercase letter, then up to 39 lowercase letters, digits or underscores.';
$string['errorkeylocked'] = 'Columns holding stored values cannot be removed and their keys cannot change: {$a}.';
$string['errorlabelduplicate'] = 'Column name used twice: {$a}.';
$string['errorlabelrequired'] = 'Column {$a} has no name.';
$string['errormodeinvalid'] = 'Column "{$a}": choose free text or a dictionary mode.';
$string['errorparentinvalid'] = 'Column "{$a}" can only depend on an earlier dictionary column.';
$string['errorparentmismatch'] = 'Column "{$a->column}": the parent column does not use "{$a->parentdomain}", the dictionary holding the parents of "{$a->domain}".';
$string['errorschemainvalid'] = 'The columns are not a valid version 2 schema.';
$string['errorsublinelimit'] = 'At most {$a} columns can be shown in the subline.';
$string['errorsublinetitle'] = 'Column "{$a}" is the title and cannot also be in the subline.';
$string['errortitlecount'] = 'Mark exactly one column as the title of the set.';
$string['errortoomanysets'] = 'Too many sets: at most {$a} sets are allowed.';
$string['errorvaluetoolong'] = 'Value too long: each value is limited to {$a} characters.';
$string['modedictionary'] = 'Dictionary, codes only';
$string['modedictionaryhelp'] = 'Codes from the dictionary; stores the code, shows the name.';
$string['modedictionaryopen'] = 'Dictionary, any code';
$string['modedictionaryopenhelp'] = 'Suggests from the dictionary but accepts a new code.';
$string['modefree'] = 'Free text';
$string['modefreehelp'] = 'Any text up to 1,024 characters.';
$string['movedown'] = 'Move {$a} down';
$string['moveup'] = 'Move {$a} up';
$string['newcode'] = 'New code, no name yet';
$string['newcolumn'] = 'Column {$a}';
$string['nomatchstrict'] = 'No match. This column accepts listed codes only.';
$string['parentmismatch'] = 'The chosen parent column does not use "{$a->parentdomain}", the dictionary holding the parents of "{$a->domain}".';
$string['parentnone'] = 'None';
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
$string['removecolumn'] = 'Remove column';
$string['removecolumnlocked'] = 'Users have values in this column, so it cannot be removed.';
$string['removeset'] = 'Remove set';
$string['repeatableset'] = 'Set {$a}';
$string['roleindexed'] = 'Indexed';
$string['roleindexedlabel'] = 'Index for reports (PostgreSQL)';
$string['rolerequired'] = 'Required';
$string['rolerequiredlabel'] = 'Required in every set';
$string['rolesubline'] = 'Subline';
$string['rolesublinelabel'] = 'Show in subline';
$string['roletitle'] = 'Title';
$string['roletitlelabel'] = 'Title of the set';
$string['searchplaceholder'] = 'Type a name or a code';
$string['setcolumn'] = 'SET';
$string['setnumber'] = 'Set number';
$string['summaryindexed'] = 'Indexed {$a->count}/{$a->max}';
$string['summarysubline'] = 'Subline {$a->count}/{$a->max}';
$string['summarytitle'] = 'Title: {$a}';
$string['synchronisedat'] = 'Synchronised at: {$a}';
$string['taskreconcileindexes'] = 'Reconcile repeatable profile field JSON indexes';
$string['taskrekeypayloads'] = 'Re-key repeatable profile field values by column key';
$string['taskreprojectsets'] = 'Rebuild repeatable profile field sets for reports';
$string['usecode'] = 'Use code "{$a}"';
