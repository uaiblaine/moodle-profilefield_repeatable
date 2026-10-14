# Review context for profilefield_repeatable

`profilefield_repeatable` is a Moodle user profile field type (`datatype = repeatable`) that
stores repeatable sets of sub-items for each user, for example several qualifications with the
same columns. The canonical value is a JSON object in core's `user_info_data.data`; the plugin's
own table `profilefield_repeatable_data` holds one row per set as a projection for Report
Builder (a JSON text column on every database, with no index on its values). The field is an
adapter of the required engine plugin `local_repeatable`, which holds the column schema, payload
normalisation, validation, the edit widget, the column editor, the display renderer with its
templates, stylesheet and AMD modules, the projection writer, the Report Builder entity and the
bridges to two optional plugins: `local_dictionaries`
(code-to-label dictionaries) and `customfield_pattern` (masks and checks). The plugin has one
branch per Moodle version (4.5, 5.1, 5.2 = `main`, 5.3).

## Who is trusted

- Site administrators are fully trusted. They define the field's column schema (a JSON
  document in `param1`) through core's profile field administration.
- The plugin declares **no capabilities, web services or page scripts**. Who may read or edit a
  value is core's profile field logic: the field's visibility and locked settings, and
  `moodle/user:update` for a locked field.
- The user who owns the profile, and anyone allowed to edit that user, are untrusted input for
  every value. Values are markup-bearing text.
- Dictionary domains can be restricted: a user who cannot read a domain must learn nothing about
  its codes from the edit form.

## Surfaces

- `field.class.php` (runtime: edit, validate, save, display) and `define.class.php`
  (administrator definition). `edit_save_data()` writes `user_info_data` itself and then
  synchronises the per-set rows in one delegated transaction. Validation runs in the engine's
  `\local_repeatable\local\validator`, built by the field with the STORED payload
  (`$this->data`) and a closure for `can_read_domain()` of the editing user: a codes-only
  column accepts an unknown code only if it is already stored for that user, and every
  submitted code in an unreadable domain gets one uniform error, no lookup and no autocomplete.
- Keys holding values are read from the stored field's `user_info_data` payloads and are locked
  in the definition form (`define_validate_specific()`), never from the submission.
- SQL uses placeholders; the JSON extraction binds the column key as a parameter on PostgreSQL
  and MariaDB (`\local_repeatable\local\sql::subitem_value()`). The engine's projection upsert
  (`\local_repeatable\local\projection\projector`) interpolates only the fixed table and owner
  column names the field's `local\source` hands it.
- `db/upgrade.php` step 2026042001 (PostgreSQL only) drops indexes an r1 release created on the
  projection table, matched by their exact name shapes (`pfrd_f<id>_<16 hex>`,
  `pfrd_<8 hex>_gin_ix`) read from `pg_indexes` and quoted, then turns the column to text.
- `db/events.php` registers observers on `user_deleted` and `user_info_field_deleted` that
  delete the plugin's rows, because core deletes `user_info_data` directly, and on
  `user_updated`/`user_created` that reproject drifted rows.
- A Report Builder datasource (`repeatable_sets`) offers one row per set through the field's
  `repeatable_set` entity, a subclass of the engine's. Every column and filter is available only
  when the field is visible to `\core\user::SUPPORT_USER` in the system context, mirroring core's
  own profile field helper; every value and filter option label goes through `s()` (engine).
- Display goes through the engine's Mustache templates with double stashes; `lib.php` adds a
  profile page widget that skips fields the viewer may not see (`is_visible()`). The engine's AMD
  modules build DOM with `createElement` and `textContent`; the field ships no templates, CSS or
  JavaScript of its own.
- Privacy: a full provider (metadata, contexts, user list, export, delete) covering
  `user_info_data` rows of this field type and `profilefield_repeatable_data`.

## Facts that look like findings but are by design

- **The plugin writes `user_info_data` itself** in `edit_save_data()`, because the JSON there is
  the canonical value and the per-set rows are rebuilt from it.
- **Web services, CSV upload and auth sync skip `edit_validate_field()`** (core behaviour), so
  required columns, code checks and the cap errors apply to the form only; on every save path the
  payload is still normalised (caps by silent truncation) and pattern values are
  normalised.
- **The optional plugins are soft dependencies.** Every use is guarded by `class_exists()` and
  goes through their public APIs. Stored codes are resolved to labels without a visibility check,
  as the dictionary plugin's contract allows; submitted codes are not.
- **`define_after_delete()` is not called by core**; the deletion observer removes the rows.
- A pattern column's error message comes from `customfield_pattern` already escaped and is
  passed into the form error unescaped on purpose.

## De-emphasise

- `lang/**`, `tests/**`, `docs/**`, `mutations/**` and `CHANGELOG.md` carry no
  production behaviour.
