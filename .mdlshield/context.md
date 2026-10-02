# Review context for profilefield_repeatable

`profilefield_repeatable` is a Moodle user profile field type (`datatype = repeatable`) that
stores repeatable sets of named sub-items for each user, for example several qualifications
with the same columns. A value is kept twice: as a JSON object in core's
`user_info_data.data`, and as one row per set in the plugin's own table
`profilefield_repeatable_data`. On PostgreSQL that table's `data` column is converted to
JSONB and indexed with SQL the plugin runs itself. An optional soft dependency, the companion
plugin `local_profilefield_repeatable`, turns stored codes into labels. The plugin supports
Moodle 4.5 through 5.2 on one branch.

## Who is trusted

- Site administrators are fully trusted. They define the field through core's profile field
  administration: the sub-item names (`param1`), the indexed sub-items (`param2`), the
  code-to-domain mappings (`param3`) and the sub-line items (`param4`). Those definitions
  are the only text that reaches the index SQL.
- The plugin declares **no capabilities, web services or page scripts**. Who may read or edit a
  value is core's profile field logic: the field's visibility and locked settings, and
  `moodle/user:update` for a locked field.
- The user who owns the profile, and anyone allowed to edit that user, are untrusted
  input for every value. Sub-item names and values are markup-bearing text.

## Surfaces

- `field.class.php` (runtime: edit, validate, save, display) and `define.class.php`
  (administrator definition and validation). `edit_save_data()` writes `user_info_data`
  itself and then synchronises the per-set rows in one delegated transaction. Payloads are
  capped at 200 sets and 1024 characters per value; only configured sub-items with scalar
  values are kept.
- **Index SQL, PostgreSQL only** (`classes/helper.php`, `classes/task/reconcile_indexes.php`).
  Installation converts the column to JSONB and creates one GIN index. An adhoc task,
  queued when a field is saved or deleted, creates and drops up to three expression indexes
  per field with `CREATE INDEX CONCURRENTLY` and `DROP INDEX CONCURRENTLY`. Index names are
  `pfrd_f<fieldid>_<sha1 prefix>`, so no user text reaches an identifier; identifiers are
  quoted and the field id is cast to an integer. The one value interpolated into DDL is the
  sub-item name, as a string literal with single quotes doubled and NUL bytes rejected;
  DDL cannot bind parameters, and core's PostgreSQL driver (checked on 5.2) refuses to run
  with `standard_conforming_strings` off, so a backslash is not an escape. Any other value
  interpolated into these statements is a finding. In the task, a failed index statement is
  caught and logged at developer level, never thrown, so a retry loop cannot start.
- All other SQL uses placeholders; the JSON extraction helper binds the sub-item key as a
  parameter on both PostgreSQL and MariaDB (`helper::sql_subitem_value()`).
- `db/events.php` registers observers on `user_deleted` and `user_info_field_deleted` that
  delete the plugin's rows, because core deletes `user_info_data` directly.
- A Report Builder datasource (`repeatable_sets`) offers one row per set. The plugin adds no
  visibility check there; access follows Report Builder's own report permissions.
- Display goes through `display_renderer` and three Mustache templates with double stashes.
  `lib.php` adds a profile page widget that skips fields the viewer may not see
  (`is_visible()`). Neither AMD module assigns `innerHTML`; the editor builds its rows with `createElement`
  and `textContent`.
- Privacy: a full provider (metadata, contexts, user list, export, delete) covering
  `user_info_data` rows of this field type and `profilefield_repeatable_data`.

## Facts that look like findings but are by design

- **The plugin writes `user_info_data` itself** in `edit_save_data()`, because it stores the
  normalised JSON there as the fallback copy alongside the per-set rows.
- **Reads prefer the per-set rows and fall back to the JSON copy**; both are rewritten together
  on every save.
- **The soft dependency is deliberate.** The plugin installs and renders without the
  companion: every use is guarded by `class_exists()` and `table_exists()`, and it reads the
  companion's domain table directly. Labels from the companion are escaped by the templates.
- **`define_after_delete()` is not called by core.** The deletion observer and a sweep inside
  the reconcile task remove orphaned indexes instead.
- **The domain shortname pattern `^[a-z0-9_]+$` is duplicated** in this plugin and the
  companion on purpose, and must stay identical.
- Uninstall drops the table, which removes its indexes with it.

## De-emphasise

- `amd/build/**` is minified output of `amd/src/**`; review the source.
- `lang/**`, `tests/**`, `docs/**`, `CHANGELOG.md` and `styles.css` carry no production
  behaviour.
