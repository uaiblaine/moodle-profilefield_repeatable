moodle-profilefield_repeatable
==============================

[![Moodle Plugin CI](https://github.com/uaiblaine/moodle-profilefield_repeatable/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/uaiblaine/moodle-profilefield_repeatable/actions/workflows/ci.yml?query=branch%3Amain)

A Moodle user profile field plugin that stores repeatable field data as sets of sub-items, reportable in Report Builder, with optional reference resolution via the local_dictionaries plugin.


Requirements
------------

- Moodle 5.2 (branch `MOODLE_502_STABLE`; releases `v5.2-rN`), PHP 8.3 or later. Other Moodle
  versions have their own branches.
- Required: [moodle-local_repeatable](https://github.com/uaiblaine/moodle-local_repeatable)
  (`local_repeatable` `v5.2-r1`, version 2026042004 or later), the repeatable-sets engine: the column schema,
  payload reading, validation, the edit widget, the column editor, the display renderer (templates,
  stylesheet and JavaScript), the projection writer, the Report Builder entity and SQL, and the
  companion bridges live there, and this plugin adapts them to user profile fields. Install it at `local/repeatable` before (or with)
  this plugin.
- Optional: [moodle-local_dictionaries](https://github.com/uaiblaine/moodle-local_dictionaries) for reference resolution.
- Optional, Moodle 5.1 or later: [moodle-customfield_pattern](https://github.com/uaiblaine/moodle-customfield_pattern)
  (a version with `\customfield_pattern\api`) for pattern columns: masks and validation such as
  CPF, CNPJ, CEP and Brazilian phone numbers.

Database baseline follows Moodle requirements:

- PostgreSQL 16+
- MySQL 8.4+ / MariaDB 10.11+; supported fallback mode

Recommended for best performance: PostgreSQL.

Microsoft SQL Server (and Oracle, on Moodle 4.5) is not supported by the Report Builder source: its
per-sub-item SQL exists only for PostgreSQL and MySQL/MariaDB, so the "Repeatable profile field
sets" source is hidden on other databases. The profile field
itself (editing and the profile page) does not depend on it.


Motivation for this plugin
--------------------------

Standard Moodle profile fields support only simple text values. This plugin provides:

1. **Repeatable sets** — Store multiple related data values as structured records (e.g., language proficiencies, certifications, work experience)
2. **Reporting** — One Report Builder row per set, with a column and filter per set column
3. **Reference resolution** — Optional mapping to external domains (via local_dictionaries) for rich display values
4. **One source of truth** — The value is kept in core's `user_info_data`, so every core path that
   writes profile fields (forms, web services, CSV upload, auth sync) keeps working
5. **Privacy compliance** — Privacy API export and deletion of every stored value (see "Data stored")


Installation
------------

Install the plugin like any other plugin to folder
/user/profile/field/repeatable

See http://docs.moodle.org/en/Installing_plugins for details on installing Moodle plugins.

**Required dependency**: place [moodle-local_repeatable](https://github.com/uaiblaine/moodle-local_repeatable)
in `/local/repeatable` first; Moodle refuses to install or upgrade this plugin without it.

**Optional dependency**: If you want to use reference resolution in repeatable fields, also install [moodle-local_dictionaries](https://github.com/uaiblaine/moodle-local_dictionaries).


Usage & Configuration
---------------------

After installing, navigate to **Site Administration > Plugins > Moodle plugins > User profile fields > Profile fields**.

### Creating a Repeatable Field

1. Click "Create a new profile field"
2. Select type **Repeatable set**
3. Fill the common settings (short name, name, description, visibility) and define the
   **Columns** in the column editor. Each column has:
   - **Column name**: shown to users; multilang tags are allowed.
   - **Key**: generated from the name (lowercase, accents folded, other characters as `_`;
     `[a-z][a-z0-9_]{0,39}`). Values and saved reports are stored under the key, so renaming a
     column later changes only its name. Once users have values under a key, the key is locked.
   - **How users fill it**: *Free text*; *Dictionary, codes only*; *Dictionary, any code*; or,
     with `customfield_pattern` installed, *Pattern* (see "Pattern columns" under Editing).
     A dictionary column names a domain of the companion plugin `local_dictionaries` (picked from
     its list when installed) and may **depend on** an earlier dictionary column whose code
     narrows its items (cities of the chosen state); the editor warns, and saving refuses, a
     parent column that does not use the dictionary holding the parents of the column's
     dictionary. See "Dictionary columns" under Editing.
   - **Role in the set**: exactly one column is the **title** of the set (the accordion header);
     up to 3 others are shown in the **subline**; **required** columns must be filled in every set
     that has any value.

   Columns are reordered with the arrows, added with "Add column" (20 at most) and removed with
   "Remove column". A column whose key holds stored values cannot be removed, and its key cannot
   change: the form rejects such a schema, whatever was submitted.

   **Required columns and the dictionary and pattern checks** run on the profile form, on CSV
   upload and on web service requests (`core_user_create_users`, `core_user_update_users`), which
   refuse a failing value. Login-time account creation and updates (auth_oauth2 among them) and
   auth_db/auth_ldap account creation store the external source's value without them, so that a
   value from the identity provider cannot lock a user out or abort a sync; auth_db/auth_ldap
   attribute updates write `user_info_data` through core without calling the field.

Without JavaScript the columns are edited as the JSON schema the editor writes to `param1`:

```json
{"version": 2, "columns": [
  {"key": "language", "label": "Language", "mode": "dictionary", "domain": "iso639", "title": true},
  {"key": "level", "label": "Level", "mode": "dictionary_open", "domain": "cefr", "subline": true, "required": true},
  {"key": "certificate_number", "label": "Certificate number", "mode": "free"},
  {"key": "cpf", "label": "CPF", "mode": "pattern", "pattern": {"preset": "cpf", "mask": "", "regex": "", "regexerror": "", "storeformat": "raw"}}
]}
```

A user's value is one JSON object of sets keyed by column key, e.g.
`{"@@ID#1": {"language": "pt", "level": "C2", "certificate_number": "CEL-204871"}}`.

### Fields from 2.x

There is no upgrade step from 2.x or the pre-release 3.0 builds; the pre-release builds are
numbered above every branch and must be uninstalled first. Version 2.x configured a field with
four lists (sub-items, indexed sub-items, `subitem|domain` mappings, subline sub-items) and stored
values under the sub-item labels. A field still holding those lists keeps working and opens in the
definition form as its column schema (keys generated from the labels, mapped sub-items as strict
dictionary columns, the first sub-item as the title); saving it converts it, and an ad hoc task
("Re-key repeatable profile field values by column key") moves its stored values to the keys.
Until the task runs, values stored under the old labels still show everywhere. Reports saved by
2.x are not renamed.

### Display Modes

- **Profile pages** (`/user/profile.php`, `/user/view.php`) — Accordion display with collapsible sets
- **Other contexts** — Plain list table format

The field decides the layout, names the accordion after the field and the user
(`profilefield-repeatable-display-<fieldid>-<userid>-<shortname>`) and reads the sets' sync times
from its projection rows; `local_repeatable` renders the sets (`\local_repeatable\output\sets_renderer`,
its templates and stylesheet). The display's CSS classes are `local-repeatable-*` (they were
`profilefield-repeatable-*` up to r1): a theme that styles them must use the new
names.

### Editing

Users can:
- Add new sets via "Add new set" button
- Edit all sub-item values within each set
- Remove sets via action column

Empty sets are removed automatically on save. At least one empty set remains in the form.

The widget and the column editor are `local_repeatable`'s (`\local_repeatable\form\widget`,
`\local_repeatable\form\column_editor` and the AMD modules `local_repeatable/repeatable` and
`local_repeatable/columneditor`). The field places the widget in the profile form (its wrapper id
stays `profilefield-repeatable-<fieldid>-<userid>-<shortname>`), hands it the stored payload and
which dictionaries the editing user may read, and reads the locked keys of the definition form
from the stored field.

#### Dictionary columns

With `local_dictionaries` installed (a version that has `\local_dictionaries\api::lookup()`), a
dictionary column is a search box over its dictionary: type a name or a code ("port", "pt" and
"Português" all find Portuguese), pick the item, and the column stores its code and shows
"name (code)". A column that depends on another only suggests the items of the parent's code,
asks for the parent first while it is empty, and is emptied when the parent changes.

- **Dictionary, codes only**: the list offers the dictionary's active items only. On save, a code
  that is not in the dictionary, or has been deactivated, is refused, unless it is the code the
  user already has in that column (a deactivated code stays until the user changes it).
- **Dictionary, any code**: when no item has the typed code, the list offers `Use code "X"`
  (Enter does the same), and the value is marked "New code, no name yet" until the dictionary
  gets it.
- A dependent column's item must belong to the parent column's code (in the parent column's
  dictionary), whatever the mode; an item that has a parent needs the parent column filled.

Searching goes through the companion's `local_dictionaries_search_items` service, which applies
the dictionary's visibility: a dictionary restricted to `local/dictionaries:view` can be searched
only by users holding that capability (system context). For everyone else the column is a text box,
and nothing about the dictionary reaches them: a "codes only" column keeps the codes an
administrator already set for the user (shown with their names) and refuses any other code with one
message, whether the code exists or not; an "any code" column takes the code as text. The same holds
for a dependent column whose parent column's dictionary is restricted. Give the capability, or open
the dictionary to logged-in users, when users fill such a column themselves.

Without the companion (or with one older than `api::lookup()`), dictionary columns are text boxes
and their codes are not checked.

#### Pattern columns

A pattern column takes its rules from the custom field type `customfield_pattern`, which installs on
Moodle 5.1 and later. Its settings in the column editor are those of a Pattern custom field:

- **Preset**: None (regular expression only), CPF, CNPJ (alphanumeric), CEP, Brazilian phone, or
  Custom mask. A preset shows its mask read-only; Custom mask takes a mask of your own (`9` digit,
  `A` letter, `*` letter or digit, alternatives separated by `|`).
- **Store as**: masked (`529.982.247-25`) or raw (`52998224725`). Values are always shown masked.
- **Regular expression** (optional, anchored to the whole stored value) and the message shown when
  it does not match.

The edit form formats the value while the user types (the plugin's typing aid also covers the
inputs of a set added later). On save every non-empty value is checked (mask, CPF and CNPJ check
digits, regular expression): a wrong one is refused under the widget, naming the set and the
column. Values are stored as "Store as" says, also when a web service, CSV upload or sync writes
them (login-time account creation and auth sync skip the check, like the required and code
checks). The profile page, Report Builder
and the privacy export show them masked; Report Builder filters match the stored shape. Changing a
column's mask or storage does not rewrite stored values: a value that does not fit is shown as
stored.

**customfield_pattern is optional.** Without it (always on Moodle 4.5 and 5.0) the Pattern mode is
not offered and the definition form refuses a new pattern column. A pattern column saved while the
plugin was installed stays: its stored settings are kept unchanged (the editor shows it as "Pattern
(plugin not installed)"), its values are kept as typed and shown as stored, and its rules apply again
once the plugin is back.


Advanced Features
-----------------

### Report Builder storage

Each set is projected into `profilefield_repeatable_data` as one JSON text row for the
"Repeatable profile field sets" source. On PostgreSQL the filters read it as
`(data)::jsonb ->> 'key'`; on MySQL/MariaDB as `JSON_UNQUOTE(JSON_EXTRACT(...))`. No index serves
these expressions: Report Builder's "contains" is `ILIKE '%v%'` and "is equal to" compares
`LOWER()` values, which neither an expression index on the raw value nor a GIN index can answer.
Releases up to r1 created such indexes and a `jsonb` column on PostgreSQL; the r2 upgrade step
drops them (only names of their exact shapes, `pfrd_f<fieldid>_<16 hex>` and
`pfrd_<8 hex>_gin_ix`), turns the column back into text and deletes their queued ad hoc tasks.

### Reference Resolution

Dictionary columns store codes of a domain kept by the companion plugin `local_dictionaries`; the
profile page and Report Builder show their names:

```text
language (domain iso639): 'en' → 'English'
level (domain cefr):      'B2' → 'Upper-Intermediate'
```

The field calls the static class `\local_dictionaries\resolver` (`resolve()`, `resolve_bulk()`,
`domain_exists()`) and, through `local_repeatable`'s dictionary bridge, `\local_dictionaries\api`
(`list_domains()` for the column editor, `can_read()` and `lookup()` for the edit form); the edit
form searches through the companion's `local_dictionaries/autocomplete_transport`.

### Report Builder

The "Repeatable profile field sets" source has one row per set. For each repeatable field it
offers "Set number" and, per column, the value (free and pattern columns; pattern values shown
masked) or the name and the code (dictionary columns). Their identifiers follow the column key (`repeatable_<shortname>:<key>`,
`<key>_name`, `<key>_code`), so renaming a column keeps saved reports working.

The columns and filters are built by `local_repeatable`'s entity
(`\local_repeatable\reportbuilder\local\entities\sets`); the field's `repeatable_set` entity reads
the field's schema and makes every column and filter available only when the field is visible to
`\core\user::SUPPORT_USER`, as core decides for its own profile field columns.

API
---

### PHPUnit Testing

Tests run in CI (moodle-plugin-ci) against PostgreSQL and MariaDB. To run
them locally from an initialised Moodle PHPUnit environment (Moodle 5.x
split layout shown; drop `public/` on older layouts):

```bash
vendor/bin/phpunit public/user/profile/field/repeatable/tests/
```

Covered areas: the profile adapter end to end (edit form, validation, save, display through the
field, field definition and key locking), the conversion of 2.x fields, the Report Builder
datasource (including the saved-report identifiers and the field's visibility rules), privacy
export/delete, the deletion-cleanup and reprojection observers, the repair task, and the upgrade
step that removes the r1 PostgreSQL index layer. The engine's own unit tests (schema, payload,
widget, column editor, renderer, projection writer, entity) live in `local_repeatable`.


Troubleshooting
---------------

#### Data appears empty after upgrade
Ensure the `profilefield_repeatable_data` table was created. Check upgrade logs in Site Administration > Logs.

#### MySQL/MariaDB behavior
On MySQL/MariaDB the storage table (`profilefield_repeatable_data`) is populated exactly as on
PostgreSQL, from the canonical value in `user_info_data`. Only the SQL that reads a column of a
set differs (see "Report Builder storage").

#### Reference resolution not working
1. Verify `moodle-local_dictionaries` is installed and the domain exists there
2. Ensure the code exists in that domain (codes are matched case-insensitively)
3. Check that the column is a dictionary column naming that domain


Data stored
-----------

- **`user_info_data`** (core's table): the field's value, one row per user and repeatable field,
  holding the user's sets as one JSON object keyed by column key. It is kept while the user and the
  field exist; deleting the user or the field removes it (core), and so does a privacy deletion.
- **`profilefield_repeatable_data`**: one row per set (`dataid`, `fieldid`, `userid`, `set_id`, the
  set's values as JSON text, `timecreated`, `timemodified`), a copy of the value above for the
  "Repeatable profile field sets" Report Builder source. It is rewritten on every save, repaired
  by the `user_updated`/`user_created` observers and the daily "Rebuild repeatable profile field
  sets for reports" task, and removed when the user or the field is deleted (event observers), by a privacy
  deletion, and with the table when the plugin is uninstalled.
- **`config_plugins`**: `profilefield_repeatable/legacykeys_<fieldid>`, only for a field converted
  from 2.x until the "Re-key repeatable profile field values by column key" ad hoc task has moved
  its values; it maps column keys to the 2.x labels and holds no user data.

A data request exports, in the user's context, each repeatable field's name, description and sets,
each set's values under the column names (dictionary columns as their stored codes, pattern values
masked, values of a removed column under their stored name); the projection rows are a copy and
are not exported. A deletion removes the user's
`user_info_data` rows of repeatable fields and their projection rows. The plugin sends nothing to
an external service.


Settings
--------

The plugin has no site settings. Each field is configured in its definition form (Site
administration > Users > Accounts > User profile fields): the common profile-field settings and the columns.


Capabilities
------------

This plugin defines no capabilities. Core's rules apply: users edit their own value with
`moodle/user:editownprofile`, and others' with `moodle/user:update`. Who sees a value follows the
field's **Visible** setting (`profile_field_base::is_visible()`, with `moodle/user:viewalldetails`
and, for fields visible to teachers, `moodle/site:viewuseridentity`), on the profile page and in
Report Builder alike.


Translations
------------

The package ships English only. Translations are contributed through AMOS, the Moodle
translation portal (https://lang.moodle.org), and reach sites as language packs; the Brazilian
Portuguese strings kept in this repository are its source for that and are left out of the
release zip.


License
-------

This plugin is licensed under the [GNU GPL v3 License](http://www.gnu.org/copyleft/gpl.html).

Copyright © 2026 Anderson Blaine
