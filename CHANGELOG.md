# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased] - version 2026042002

### Removed
- The PostgreSQL index layer (owner decision D3, `docs/engine-extraction-design.md` §8). Measured on
  PostgreSQL 18.6 with 50,000 users and 125,000 projection rows, no Report Builder filter or listing
  used the per-column partial expression indexes or the GIN index: "contains" is `ILIKE '%v%'`,
  "is equal to" compares `LOWER()` values while the index held the bare `data->>'key'`, the indexes
  were partial on `jsonb_exists(data, key)`, which Report Builder never emits, and the GIN
  `jsonb_path_ops` index serves only `@>`, which nothing in the plugin emits. Gone with it: the
  `reconcile_indexes` ad hoc task, the install hook that converted the projection's `data` column to
  `jsonb` and created the GIN index (`db/install.php`, `db/lib.php`), the redundant
  `db/uninstall.php` (core drops the table and the plugin's ad hoc tasks), the "Index for reports"
  column role with its limit of 3 and its lang strings, and the mutation gates I1-I7 and SC11. The
  readers keep `(data)::jsonb ->> 'key'`, which reads the text column.
- A stored schema's `indexed` flag is dropped when read and omitted on the next save; version 1
  fields' indexed list (`param2`) is ignored.

### Changed
- Upgrade step 2026042001 (`db/upgradelib.php`, `profilefield_repeatable_drop_index_layer()`): on
  PostgreSQL it drops the indexes of the projection table whose names have the layer's exact shapes
  (`pfrd_f<fieldid>_<16 hex>`, `pfrd_<8 hex>_gin_ix`; never a bare prefix) and turns the `data` column
  back into `text`, as `install.xml` declares it; on every database it deletes the queued
  `reconcile_indexes` ad hoc tasks, whose class no longer exists. No DDL on MySQL/MariaDB. Tested by
  `tests/upgrade_test.php`, held by gates U1-U8; SC16 holds the dropped `indexed` flag.
- Engine extraction stage A (`docs/engine-extraction-design.md` §6), version 2026042002: the field is
  rearranged into the shape of the future `local_repeatable` engine behind its own namespace, with
  output, validation messages, SQL, Report Builder identifiers, lang strings and AMD module names
  unchanged. Engine side: `local\validator`, `form\widget`, `form\column_editor`,
  `output\sets_renderer`, `local\projection\{store,source,projector}`,
  `reportbuilder\local\entities\sets` (abstract) and `privacy\set_exporter`. Profile adapter:
  `local\field_schema` (the version 1 reader, out of `local\schema` and `helper`), `local\source`,
  and the trimmed `field.class.php`, `define.class.php`, `display_renderer`, `repeatable_set`,
  `local\projection` (now only `reproject_user()`) and privacy provider. A new test pins the
  datasource's column and filter unique identifiers to a list captured before the move, so saved
  reports keep working across the extraction (§5.2 item 5). Mutation gates repointed to the new
  files.

## [v5.2-r1] - 2026-10-13

Moodle 5.2 branch (`MOODLE_502_STABLE`): version `2026042000`, release `v5.2-r1`,
`MATURITY_STABLE`, `$plugin->supported = [502, 502]`, CI against core `MOODLE_502_STABLE`.

First release on the one-branch-per-Moodle-version layout: `MOODLE_405_STABLE` (`v4.5-r1`),
`MOODLE_501_STABLE` (`v5.1-r1`), `MOODLE_502_STABLE` (`v5.2-r1`, also `main`) and
`MOODLE_503_STABLE` (`v5.3-r1`). Each branch numbers itself in its Moodle version's namespace
(4.5 `20241007XX`, 5.1 `20251006XX`, 5.2 `20260420XX`, 5.3 `20261005XX`), declares
`$plugin->supported` for that version only, is `MATURITY_STABLE`, and runs CI against its own
core branch with the full PHP x database matrix, installing the companion `local_dictionaries`
from its matching branch (and `customfield_pattern` from `main` on 5.1 and later).

### Removed
- The pre-release upgrade step (the column schema v2 step, 2026101301), `db/upgradelib.php` with
  its tests and mutation gates, and `migration::INLINE_LIMIT`, which only that step read. Its
  savepoint is above every branch's version, so it could never run there, and no 2.x install
  exists to need it: the branches start fresh in their version namespaces. A field still holding
  the version 1 parameters keeps reading through them and is converted when it is saved in the
  definition form; reports saved by 2.x under its `subitemN` names are no longer renamed.
  `install.xml` carries `VERSION="2024100700"` on every branch.

### Security
- Columns holding stored values are locked on the server too: the definition form reads the
  keys in use from the stored payloads (not the projection) and rejects a schema that removes or
  re-keys one; the editor disables "Remove column" for them.
- A stored schema is re-checked when read: a column with a bad or colliding key is dropped before
  any SQL, index name or Report Builder identifier is built from it.
- Re-keying a converted field's payloads uses an exact compare-and-set, also on MariaDB's case-
  and accent-insensitive collation, and no generated key equals another column's 2.x label, which
  would cross-wire the reads of label-keyed sets.

### Added
- **Pattern columns (schema v2, stage C)**: with `customfield_pattern` installed (Moodle 5.1 or
  later, a version with `\customfield_pattern\api`), a column can be a *Pattern*: a preset (CPF,
  CNPJ, CEP, Brazilian phone), a custom mask and/or an anchored regular expression, stored masked
  or raw. The column editor offers the mode and its panel; the edit form formats the value while
  the user types and refuses a value that does not fit (wrong CPF or CNPJ check digit included),
  naming the set and the column; values are stored as configured, also through web services and
  sync; the profile page, Report Builder and the privacy export show them masked. The plugin is a
  soft dependency: without it (Moodle 4.5 and 5.0 always) the mode is not offered, a new pattern
  column is refused, and a stored one keeps its settings while its values are kept as typed.
- **Autocomplete and strict codes (schema v2, stage B)**: with `local_dictionaries` installed
  (a version with `api::lookup()`), a dictionary column on the edit form is a search box over its
  dictionary (`core/form-autocomplete` through the companion's transport): a name or a code finds
  the item, a value shows as "name (code)" and stores the code. A column that depends on another
  only suggests the items of the parent's code and is emptied when the parent changes. A "codes
  only" column refuses an unknown or deactivated code on save, except the code the user already
  has (deactivated or removed since); an "any code" column offers "Use code ..." and marks a code
  that has no name yet. A child whose item belongs to another parent code or parent dictionary, or
  whose parent column is blank, is refused. A user who may not read a restricted dictionary types
  the code instead and learns nothing about the dictionary: labels only for the codes already
  stored, one message for every other code in a "codes only" column, text in an "any code" one.
  Without the companion, codes stay typed text, unchecked.
- The column editor warns, and the definition form refuses, a parent column that does not use
  the dictionary holding the parents of the column's dictionary (when the companion knows it).
- **Column schema v2**: a field's columns are one JSON schema in `param1`
  (`{"version": 2, "columns": [...]}`), each column with a stable key, a label, a mode (`free`,
  `dictionary`, `dictionary_open`, `pattern`), an optional domain and parent column,
  and its role (title, subline, required, indexed). Values, Report Builder identifiers and index
  names follow the key, so renaming a column no longer orphans its data.
- A column editor in the field definition form (`amd/src/columneditor.js`) replaces the four text
  lists: name, key (locked once users hold values under it), mode, dictionary picked from
  `local_dictionaries`, parent column, roles with their limits, reordering. Without JavaScript the
  schema is edited as JSON. Every broken rule is listed under it.
- Per-column **required**: every set that has a value must fill the required columns; the field's
  own "required" setting still means "at least one set".
- Report Builder: free columns are `repeatable_<shortname>:<key>`, dictionary columns
  `<key>_name` and `<key>_code`; column titles use the column name.
- Privacy export lists each set's values under the column names, with the key appended when two
  names collide, and exports values under names no current column reads under those names.
- A field still on the 2.x lists opens in the definition form as its schema; saving it converts
  it, and the ad hoc task `rekey_payloads` re-keys its stored values; until then readers accept
  both payload shapes. The column editor lists domains through
  `\local_dictionaries\api::list_domains()` (an older companion gives an empty list).
- A test data generator for repeatable fields.

### Changed
- `param2`..`param4` are no longer used and are cleared when a field is saved; the strings of the
  four lists, of their errors and the unused `displaytableinfo`/`displaytablevalue` are removed.
- The edit widget receives its columns (key, label, mode, required) in a data attribute and marks
  required columns in the table header.
- Indexed columns no longer need the ":", "?" and "$" check: keys cannot contain them.
- Copyright headers carry the name only (no e-mail address); `amd/build` rebuilt for the edited
  module docblocks.
- `.gitattributes` and `.stylelintrc.json` follow the fleet templates.

### Removed
- The GIN `REINDEX` right after its `CREATE INDEX` (it rebuilt the same index under a write lock),
  the unused PostgreSQL feature helpers, the per-run index-task `debugging()` line, the
  unreachable `$set['raw']` fallback, the private `normalise_payload()` of the field class, and
  the dead procedural wrappers and storage-table step in `db/lib.php` and `db/install.php`
  (core creates the table from `install.xml` before the install hook).

### Changed
- The companion dictionary plugin is now `local_dictionaries` (was `local_profilefield_repeatable`):
  labels come from `\local_dictionaries\resolver`, the "domain exists" check uses
  `resolver::domain_exists()` instead of reading the companion's table, the domain pattern is read
  from `resolver::DOMAIN_PATTERN` when the companion is installed, and CI installs the new repository.
- Code comments audited against the code and Moodle's guidance: contracts stated, comments that
  contradicted the code corrected, history moved out of the code. `amd/build` rebuilt for the
  edited JSDoc of `amd/src/repeatable.js`.

### Security
- **Stored XSS in Report Builder**: the sub-item value, code and resolved-name columns now
  escape their output, and the name filter escapes its option labels (core's autocomplete
  element prints them unescaped).
- **Field visibility in Report Builder**: every column and filter of a repeatable field is
  available only when the field is visible to the viewer, the same gate core applies to its own
  profile-field columns (`is_visible()` at system level).

### Fixed
- **auth_ldap sync reaches the projection**: the `user_updated` and `user_created` observers are
  non-internal, so core buffers them until the sync's delegated transaction commits instead of
  running them before `profile_save_custom_fields()` writes the payload. Outside a transaction,
  and for auth_db sync of existing users, the daily `reproject_sets` task still repairs.
- **Names were escaped twice**: field and sub-item names reach the Mustache double stashes (edit
  form, plain list, accordion) and the Report Builder entity titles in the plain spelling.
- **Indexed sub-items with `:`, `?` or `$`** are rejected at definition time (their index DDL
  was read as a query placeholder and the index silently never created); the index task skips
  any already saved.
- **Report Builder resolved-name column**: labels are memoised per domain and code, misses
  included, so unresolved codes no longer cost two queries per row.
- **Accordion colours follow the theme**: the `--pfr-*` tokens read the theme's `--bs-*` tokens
  (Moodle 5.x dark mode), with the previous colours as fallbacks for Moodle 4.5.
- **Profile reads follow the canonical payload**: the edit form, `is_empty()` and the profile
  display read `user_info_data.data`. Values written by core's `profile_save_custom_fields()`
  (auth_db and auth_ldap attribute sync) no longer hide behind stale per-set rows.
- **Per-set rows are rebuilt when they drift**: observers on `user_updated` and `user_created`
  (auth_ldap sync) and a new daily scheduled task (`reproject_sets`, for auth_db sync and
  restored users) rewrite `profilefield_repeatable_data` when it no longer matches the payload.
- **Validation errors are visible**: the value's hidden input sits in a form group named after
  the field, so invalid-JSON, too-many-sets, value-too-long and required errors render under the
  widget, which references them with `aria-describedby`.
- **Index reconciliation dropped other fields' indexes**: the `pfrd_f<id>_` prefix is matched
  with an escaped `LIKE` and an exact name check, so field 1 no longer claims the indexes of
  fields 10-19 and 100-199.
- Privacy export and deletion compare context and user ids as integers.

### Changed
- The Report Builder source is offered only on PostgreSQL and MySQL/MariaDB; its per-sub-item
  SQL has no SQL Server or Oracle form.
- Per-set row writes moved to `\profilefield_repeatable\local\projection`.

## [2.2.0] - 2026-06-16

### Added
- Moodle 5.2 support (`$plugin->supported = [405, 502]`); CI runs the full PHP × DB
  matrix on Moodle 5.02 with 5.01/5.00/4.05 on PostgreSQL only.
- Automated release to the Moodle plugins directory via `moodle-release.yml`
  (triggered on `v*` tags).

## [2.1.0] - 2026-06-14

### Added
- Report Builder datasource (`repeatable_sets`): one row per set, with per-sub-item
  raw-code and resolved-name columns and matching filters.

### Changed
- CI migrated to the moodle-an-hochschulen/moodle-workflows reusable workflow,
  called once per supported Moodle branch (PHPUnit + Behat on every PHP × DB leg)
  and cross-installing the companion `local_profilefield_repeatable`.

## [2.0.3] - 2026-06-11

### Added
- Behat coverage: `@javascript` scenario filling a repeatable set on the profile edit form
  and asserting the accordion widget on the profile page

## [2.0.2] - 2026-06-10

### Fixed
- **Orphaned data on core deletions**: new event observers purge `profilefield_repeatable_data`
  rows when a user is deleted (`\core\event\user_deleted`) or a repeatable field is deleted
  (`\core\event\user_info_field_deleted`); field deletion also queues index reconciliation on
  PostgreSQL so expression indexes are dropped immediately
- **Privacy export overwrote itself with multiple repeatable fields**: each field now exports
  under its own subcontext (plugin name + field name)
- Privacy provider `get_records()` selects explicit columns instead of `SELECT *` with
  colliding `id` columns

### Added
- Payload limits with validation messages: at most 200 sets per field value and 1024
  characters per sub-item value (`helper::MAX_SETS` / `helper::MAX_VALUE_LENGTH`);
  normalisation enforces the same caps defensively on non-form paths

### Changed
- Storage rows are memoised per field instance and shared with the display renderer,
  halving profile-page queries per repeatable field
- Sync footer timestamp uses the localised `strftimedatetimeshort` format instead of a
  hardcoded format string
- README corrections: ad hoc (not scheduled) task wording, accurate MySQL/MariaDB storage
  behaviour, split-layout PHPUnit path, accurate test-coverage claims

## [2.0.1] - 2026-04-30

### Changed
- Display accordion reimplemented with native `<details>`/`<summary>` elements and improved
  accessibility
- Class file names normalised to lowercase; reference label resolution in the display
  renderer optimised (bulk prefetch per domain)

## [2.0.0] - 2026-04-24

### BREAKING CHANGE
- **No longer supports migrations from legacy EAV schema**
- Plugin now requires fresh installations only
- If upgrading from v1.x, clean the old schema before deploying v2.0.0+

### Changed
- Table creation moved to install.php — table is always guaranteed post-install
- Removed all legacy migration code (EAV schema conversion, backfill, field guards)
- upgrade.php is now a minimal placeholder for future versions
- `hasstoragetablefn` callback removed from display renderer
- `has_storage_table()` guards removed from field.class.php and display_renderer.php
- install.xml VERSION baseline updated to 2026040100

## [1.0.1] - 2026-04-02

### Changed
- Documentation now reflects Moodle 4.5+ database baseline (PostgreSQL 13+ / MySQL 8.0+)
- Updated PostgreSQL indexing examples and troubleshooting guidance
- Added PostgreSQL version matrix (13/14/15/17) with compatibility notes

### Notes
- Advanced PostgreSQL features for 14+/15+/17+ remain optional and are documented as roadmap opportunities

## [1.0.0] - 2026-04-01

### Added
- Initial release of the Repeatable profile field plugin
- JSON-per-set storage in `profilefield_repeatable_data` table
- PostgreSQL JSONB support with optional GIN expression indexes on up to 3 indexed sub-items
- AMD modules for edit form interactivity (`profilefield_repeatable/repeatable`) and display accordion (`profilefield_repeatable/displayaccordion`)
- Optional reference resolution via `local_profilefield_repeatable` plugin for domain mapping
- Full privacy API compliance: export and delete support for storage table
- Async index reconciliation via adhoc task for PostgreSQL index management
- Comprehensive PHPUnit test suite covering field definition, storage, display, and privacy
- Mustache templates for edit form, accordion display (profile pages), and plain list display
- Support for Moodle 4.5+ with PostgreSQL fallback to MySQL/MariaDB JSON storage

---

## Version History

| Version | Release Date | Notes |
|---------|--------------|-------|
| 2.0.3   | 2026-06-11   | Behat coverage |
| 2.0.2   | 2026-06-10   | Deletion-cleanup observers, privacy export fix, payload limits |
| 2.0.1   | 2026-04-30   | Native details/summary accordion, lowercase class files |
| 2.0.0   | 2026-04-24   | Clean install baseline — no legacy migrations |
| 1.0.1   | 2026-04-02   | Documentation updates |
| 1.0.0   | 2026-04-01   | Initial stable release |

