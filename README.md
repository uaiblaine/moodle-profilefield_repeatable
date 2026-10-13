moodle-profilefield_repeatable
==============================

[![Moodle Plugin CI](https://github.com/uaiblaine/moodle-profilefield_repeatable/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/uaiblaine/moodle-profilefield_repeatable/actions/workflows/ci.yml?query=branch%3Amain)

A Moodle user profile field plugin that stores repeatable field data as sets of sub-items with efficient PostgreSQL JSONB indexing and optional reference resolution via the local_dictionaries plugin.


Requirements
------------

- Moodle 5.3 (branch `MOODLE_503_STABLE`; releases `v5.3-rN`), PHP 8.3 or later. Other Moodle
  versions have their own branches.
- Optional: [moodle-local_dictionaries](https://github.com/uaiblaine/moodle-local_dictionaries) for reference resolution.
- Optional, Moodle 5.1 or later: [moodle-customfield_pattern](https://github.com/uaiblaine/moodle-customfield_pattern)
  (a version with `\customfield_pattern\api`) for pattern columns: masks and validation such as
  CPF, CNPJ, CEP and Brazilian phone numbers.

Database baseline follows Moodle requirements:

- PostgreSQL 17+
- MySQL 8.4+ / MariaDB 11.4+; supported fallback mode

Recommended for best performance: PostgreSQL.

Microsoft SQL Server (and Oracle, on Moodle 4.5) is not supported by the Report Builder source: its
per-sub-item SQL exists only for PostgreSQL and MySQL/MariaDB, so the "Repeatable profile field
sets" source is hidden on other databases. The profile field
itself (editing and the profile page) does not depend on it.


Motivation for this plugin
--------------------------

Standard Moodle profile fields support only simple text values. This plugin provides:

1. **Repeatable sets** — Store multiple related data values as structured records (e.g., language proficiencies, certifications, work experience)
2. **Efficient indexing** — PostgreSQL expression indexes on selected columns for fast queries
3. **Reference resolution** — Optional mapping to external domains (via local_dictionaries) for rich display values
4. **Backward compatibility** — Falls back to JSON storage in user_info_data when storage table unavailable
5. **Privacy compliance** — Full GDPR export/delete support


Installation
------------

Install the plugin like any other plugin to folder
/user/profile/field/repeatable

See http://docs.moodle.org/en/Installing_plugins for details on installing Moodle plugins.

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
     that has any value; up to 3 columns are **indexed** on PostgreSQL.

   Columns are reordered with the arrows, added with "Add column" (20 at most) and removed with
   "Remove column". A column whose key holds stored values cannot be removed, and its key cannot
   change: the form rejects such a schema, whatever was submitted.

   **Required columns are checked by the profile form only.** Web services
   (`core_user_create_users`, `core_user_update_users`), CSV upload and auth plugin sync save
   through the field without its form validation, so a set they write may leave a required
   column empty.

Without JavaScript the columns are edited as the JSON schema the editor writes to `param1`:

```json
{"version": 2, "columns": [
  {"key": "language", "label": "Language", "mode": "dictionary", "domain": "iso639", "title": true, "indexed": true},
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

### Editing

Users can:
- Add new sets via "Add new set" button
- Edit all sub-item values within each set
- Remove sets via action column

Empty sets are removed automatically on save. At least one empty set remains in the form.

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
them (those skip the check, like the required and code checks). The profile page, Report Builder
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

### PostgreSQL Expression Indexes

On PostgreSQL, the plugin automatically creates expression indexes on the indexed columns (up to 3 per field):

```sql
CREATE INDEX CONCURRENTLY pfrd_f{fieldid}_{key_hash}
ON mdl_profilefield_repeatable_data (fieldid, ((data ->> '{key}')))
WHERE fieldid = {fieldid}
  AND jsonb_exists(data, '{key}')
```

The plugin also ensures a base JSONB GIN index for generic JSONB lookups:

```sql
CREATE INDEX pfrd_{tablehash}_gin_ix
ON mdl_profilefield_repeatable_data USING GIN (data jsonb_path_ops)
```

Indexes are managed automatically via an adhoc task when field configuration changes.

### PostgreSQL Version Matrix

| PostgreSQL Version | Status | Notes |
|--------------------|--------|-------|
| 13+ | Active baseline | Fully supported by Moodle 4.5+ and by this plugin |
| 14+ | Optional enhancements | SQL/JSON path queries can be used in custom reporting |
| 15+ | Optional enhancements | Additional SQL features available, no plugin requirement change |
| 17+ | Optional enhancements | Recommended for newest planner/runtime improvements |

This release keeps compatibility with the Moodle baseline while documenting newer PostgreSQL opportunities.

### Reference Resolution

Dictionary columns store codes of a domain kept by the companion plugin `local_dictionaries`; the
profile page and Report Builder show their names:

```text
language (domain iso639): 'en' → 'English'
level (domain cefr):      'B2' → 'Upper-Intermediate'
```

The field calls the static class `\local_dictionaries\resolver` (`resolve()`, `resolve_bulk()`,
`domain_exists()`) and `\local_dictionaries\api` (`list_domains()` for the column editor,
`can_read()` and `lookup()` for the edit form); the edit form searches through the companion's
`local_dictionaries/autocomplete_transport`.

### Report Builder

The "Repeatable profile field sets" source has one row per set. For each repeatable field it
offers "Set number" and, per column, the value (free and pattern columns; pattern values shown
masked) or the name and the code (dictionary columns). Their identifiers follow the column key (`repeatable_<shortname>:<key>`,
`<key>_name`, `<key>_code`), so renaming a column keeps saved reports working.


API
---

### PHPUnit Testing

Tests run in CI (moodle-plugin-ci) against PostgreSQL and MariaDB. To run
them locally from an initialised Moodle PHPUnit environment (Moodle 5.x
split layout shown; drop `public/` on older layouts):

```bash
vendor/bin/phpunit public/user/profile/field/repeatable/tests/
```

Covered areas: column schema and its validation, field definition, the conversion of 2.x fields,
storage upsert/read, display rendering, Report Builder, privacy export/delete, and deletion-
cleanup observers.


Troubleshooting
---------------

#### Data appears empty after upgrade
Ensure the `profilefield_repeatable_data` table was created. Check upgrade logs in Site Administration > Logs.

#### Indexes not created on PostgreSQL
Index reconciliation runs as an **ad hoc task** queued whenever the field configuration is saved
(it is not listed under Scheduled tasks). Check Site Administration > Server > Tasks > Ad hoc tasks
(and the task logs) for recent runs, ensure the field has columns marked "Index for reports",
and verify PostgreSQL 13+ connectivity. Running cron processes the queue.

#### MySQL/MariaDB behavior
On MySQL/MariaDB the storage table (`profilefield_repeatable_data`) is populated exactly as on
PostgreSQL, with a `user_info_data` JSON fallback kept in sync. Only the PostgreSQL-specific
parts are skipped: the JSONB column conversion, the base GIN index, and the per-sub-item
expression indexes.

#### Reference resolution not working
1. Verify `moodle-local_dictionaries` is installed and the domain exists there
2. Ensure the code exists in that domain (codes are matched case-insensitively)
3. Check that the column is a dictionary column naming that domain


Capabilities
------------

This plugin does not introduce new capabilities. Profile field visibility is controlled by existing user/editownprofile and user/editprofile capabilities.


License
-------

This plugin is licensed under the [GNU GPL v3 License](http://www.gnu.org/copyleft/gpl.html).

Copyright © 2026 Anderson Blaine
