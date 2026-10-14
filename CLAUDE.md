# Claude instructions for `profilefield_repeatable`

Auto-loaded whenever Claude works in this plugin's tree. **Fleet-wide standards live in
`~/dev/CLAUDE.md`** (coding style, CI gates, lang strings, the `mdl` environment, git) — do not
repeat them here. This file keeps only what is true for this plugin, as rules with a one-line
reason each; every session and subagent pays for it on every cache write, so the long version of a
gotcha goes to `docs/` (export-ignored) with a pointer from the rule.

Plugin context: a Moodle **user profile field** plugin ("Repeatable", `datatype = repeatable`)
that stores repeatable sets of sub-items per user. The canonical value is a JSON payload in core's
`user_info_data.data`; the plugin's own table `profilefield_repeatable_data` holds one row per set
as a projection for Report Builder (a JSON text column on every database, no index on its
values). Optional code→label resolution comes from the companion dictionary plugin
(soft dependency, see below). **Hard dependency on `local_repeatable`** (the repeatable-sets engine,
`~/dev/moodle-local_repeatable`): the column schema, payload reading, Report Builder SQL, validation,
the companion bridges, stored-value counting and the privacy export live there; this plugin is its
profile-field adapter (see "Engine and adapter"). **One branch per Moodle version**: `MOODLE_405_STABLE`,
`MOODLE_501_STABLE`, `MOODLE_502_STABLE` (= `main`) and `MOODLE_503_STABLE`, MATURITY_STABLE,
releases `v<major>.<minor>-r<N>`. Each branch's `version.php` sets `requires` and `supported` to
its own Moodle version, and its `.github/workflows/ci.yml` has one moodle-an-hochschulen job for
that core branch (full PHP x DB matrix) — **keep `version.php` and `ci.yml` per branch on a
merge**. Development happens on `main` and the m502 stack; the stacks that mount this checkout at
`user/profile/field/repeatable` follow `$plugin->supported` (`~/dev/moodle-dev/plugins.conf`,
`auto`; `mdl mounts` regenerates them).

## Agent orchestration budget (fleet rule, repeated here on purpose)

Section 6 of `~/dev/CLAUDE.md` (`moodle-dev/CLAUDE.fleet.md`) is the authority and says why.
This short copy reaches sessions that do not load that file: cloud sessions and checkouts
outside `~/dev`. Every subagent gets the model and effort of its role from its agent
definition, and none runs on the session model.

| Role | model | effort | agent |
|---|---|---|---|
| Mechanical sweeps, greps, renames, counts, log reading | `haiku` | `medium` | `fleet-sweeper` |
| Checklists against evidence (handoff counts, spec lines against a sweep log, lang lockstep) | `haiku` | `high` | `fleet-checker` |
| Readers, measurers, graders | `sonnet` | `medium` | `fleet-reader` |
| Refuters and verifiers of a blocking finding | `sonnet` | `high` | `fleet-verifier` |
| Well-scoped implementation (established cause, settled design, written recipe) | `sonnet` | `medium` | `fleet-fixer` |
| Non-trivial implementation (open design, several files, long tasks) | `opus` | `high` | `fleet-implementer` |
| Consolidators, critics, estimators, ADR and documentation drafters | `opus` | `high` | `fleet-synthesist` |

- Launch the `Agent` tool with `subagent_type: "fleet-*"`; it has no `effort` parameter, so
  the role's effort comes from that definition (`mdl claude-setup` installs them). Where they
  are not installed, pass `model`. Aliases only; never `fable`; `xhigh` only for a long-horizon
  implementer whose prompt says why; never `xhigh`/`max` on Sonnet or Haiku.
- No long command inside a subagent: `mdl ci --matrix`, `mdl mutate` and Behat run from the
  main session in a background Bash command; a subagent runs the fast gate its prompt names and
  reports the command with its counts.
- Workflows only on the user's opt-in, every `agent()` with `agentType: 'fleet-*'`, under 10
  agents. Advisor off by default.

## Commands

```sh
mdl ci moodle-profilefield_repeatable --matrix --behat   # every CI leg locally: the pre-merge gate
mdl phpunit m502 profilefield_repeatable                 # targeted tests
mdl behat m502 @profilefield_repeatable                  # Behat smoke tests
mdl grunt m502 user/profile/field/repeatable             # rebuild amd/build (commit with src)
mdl purge m502                                           # after PHP changes that affect output
# Mutation sweep: absolute spec path, and BOTH database families.
mdl mutate moodle-profilefield_repeatable /Users/uaiblaine/dev/moodle-profilefield_repeatable/mutations/gates.conf --fast --db pgsql
mdl mutate moodle-profilefield_repeatable /Users/uaiblaine/dev/moodle-profilefield_repeatable/mutations/gates.conf --fast --db mariadb
xmllint --noout --schema ~/dev/moodle-502/public/lib/xmldb/xmldb.xsd db/install.xml
```

In a Claude Code cloud session none of `mdl`, the stacks or `~/dev/CLAUDE.md` exist. The cloud
environment's setup script (`moodle-dev/cloud/setup.sh`) installs `moodle-plugin-ci` and the `mpci`
runner instead: `mpci` runs every gate on the highest branch in `ci.yml`, `mpci --branch <branch>`
another one, `mpci --reuse --only phpunit --filter <test>` iterates. It cannot run Behat, PHP 8.4 or
MariaDB, so the owner runs `mdl ci moodle-profilefield_repeatable --matrix --behat` before merging:
push a branch, open the pull request, leave the merge to them. If `which mpci` prints nothing, the
session was resumed on a container that skipped the setup script: attach `moodle-dev` and run
`bash cloud/setup.sh` from it. See `moodle-dev/cloud/README.md`.

`mutations/gates.conf` breaks one guard per gate and names the test that must redden. Gates marked
`# PG only` are held by tests that skip off PostgreSQL: judge them from the pgsql sweep only.

## Code layout

```
field.class.php        profile_field_repeatable (adapter): profile hooks, save transaction, seams, ids
define.class.php       profile_define_repeatable (adapter): stored field, locked keys, v1 conversion
classes/local/field_schema.php     the {user_info_field} reader (adapter): from_field(), from_v1(), legacy config
classes/local/stored_values.php    usage(): reads the field's user_info_data payloads for the engine; locked_labels()
classes/local/source.php           profile implementation of local\projection\source (adapter)
classes/local/projection/          engine-to-be (stage C): store, source (adapter contract), projector
classes/local/projection.php       reproject_user() (adapter: joins user_info_data to user_info_field)
classes/local/migration.php        re-keying of payloads of a field converted from v1 (run by the task)
classes/form/                      engine-to-be: widget (edit table, labels, JS), column_editor (define textarea)
classes/observer.php   cleanup on user/field deletion; reprojection on user_updated/user_created
classes/output/        sets_renderer (engine-to-be: accordion/plain), display_renderer (adapter: layout, rows)
classes/reportbuilder/             datasource repeatable_sets; entity sets (engine-to-be) + repeatable_set (adapter)
classes/privacy/       provider (adapter; the export of one payload is local_repeatable's set_exporter)
db/upgradelib.php      upgrade-step logic; self-contained (its own identifier quoting, no engine calls)
classes/task/reproject_sets.php    scheduled, daily: repair stale projections
classes/task/rekey_payloads.php    adhoc: re-keys a field the definition form converted from v1
amd/src/               repeatable.js (edit widget), dictionary_transport.js (its autocomplete transport),
                       markup.js (shared by both), columneditor.js (define form), displayaccordion.js
```

Engine-side classes (stage A of `docs/engine-extraction-design.md`) never call profile-field APIs: the
adapter passes the stored payload, the can-read callable and the seams' availability flags.

### Engine and adapter (local_repeatable)

- From `local_repeatable` (stages B1-B2): `local\schema`, `local\payload` (caps, `normalise()`,
  `column_value()`, `encode()`), `local\sql` (`is_postgres()`, `subitem_value()`, `column_value()`),
  `local\validator`, `local\stored_values::keys_in_use()`, `local\bridge\dictionaries`,
  `local\bridge\pattern` and `privacy\set_exporter`. Their rules, tests and mutation gates live there;
  this repository tests the wiring (stored payload from `$this->data`, `can_read_domain()` of the
  editing user, the `companion_available()`/`pattern_available()` seams, end-to-end form, save,
  display, privacy and Report Builder).
- `version.php` declares `dependencies`; core checks it against the version on disk and installs local
  plugins last, so an upgrade step of this field may use engine classes (they autoload) but no engine
  DB state. `db/upgradelib.php` calls neither, on purpose: upgrade steps are frozen history.
- Strings an engine class prints come from `local_repeatable` (the moved keys were deleted here).
  `repeatableset` exists in both: the engine's for the privacy export, this plugin's for the widget
  and the renderer, until stage C moves those.

## Versions

Each branch numbers itself in its Moodle version's namespace (4.5 `20241007XX`, 5.1 `20251006XX`,
5.2 `20260420XX`, 5.3 `20261005XX`; fleet rule, `~/dev/CLAUDE.md`), counter per branch. The r1
releases dropped the pre-release upgrade step (its savepoint 2026101301 is above every branch's
version); a new step is numbered in its own branch's namespace. `install.xml` keeps
`VERSION="2024100700"` on every branch. The plan: `docs/audit-and-direction-2026-10.md` §3.

## Architecture gotchas

### Legacy profile-field API

- `field.class.php` and `define.class.php` keep core's fixed global class names and are loaded by
  `require_once`: tests require `user/profile/lib.php` and the file explicitly. New code goes under
  `classes/`, which is autoloaded.
- Plugin classes autoload during install and upgrade (`CACHE_DISABLE_ALL` makes core rescan the
  class map). Upgrade-step logic lives in `db/upgradelib.php` functions, so tests call it.

### Column schema (v2)

- `param1` holds `{"version":2,"columns":[{key,label,mode,domain?,parent?,pattern?,title?,subline?,
  required?}]}`; `param2`..`param4` are cleared on save; `defaultdata` is forced to `'{}'`. An r1
  `indexed` flag is not in `schema::FLAGS`: dropped on read, omitted on save (the engine's `schema_test` pins it).
  `\local_repeatable\local\schema::validate()` is the only gate (exactly one title, subline ≤ 3, ≤ 20 columns,
  parent = an earlier dictionary column, no two columns producing one Report Builder identifier);
  the constructor normalises and never rejects. A `pattern` column is checked by customfield_pattern
  (see below) and keeps its `pattern` object verbatim.
- **Payloads and Report Builder identifiers are keyed by column key**, never by label. A key is
  `[a-z][a-z0-9_]{0,39}`; `generate_key()` and `amd/src/columneditor.js` `generateKey()` must stay
  in step. Keys holding values (`local\stored_values::usage()`, read from the stored field's
  `user_info_data` payloads, never the projection, and counted by the engine) are locked in the editor AND enforced by
  `define_validate_specific()`: a schema that removes or re-keys one is rejected. A stored schema
  is re-checked on read: `from_json()` drops columns with a bad or colliding key.
- `from_v1()` never gives a key equal to another column's label (or its `_name`/`_code`): v1
  payloads hold values under labels, and such a key would read the other column's value.
- Per-column `required` is checked in `edit_validate_field()` only: web services, CSV upload and
  auth sync save through `edit_save_data()` without it. A JSON null under a key counts as absent
  (`payload::column_value()`), as COALESCE treats PostgreSQL's NULL.
- Saving a v1 field in the definition form converts it: `legacykeys_<fieldid>` is written before
  `param1` changes and removed only by `migration::finish_field()`, run by the `rekey_payloads`
  task. The rekey's compare-and-set is exact (`sql_equal()` case and accent sensitive on
  pgsql/mysql, PHP comparison elsewhere).
- Report Builder identifiers: free `<key>`, dictionary `<key>_name` + `<key>_code`, plus
  `setnumber`.
- **v1-tolerant reads**: a field still on the four lists is read through `field_schema::from_v1()`; a
  converted field whose payloads are not re-keyed yet has its key → 2.x label map in the plugin
  config `legacykeys_<fieldid>`. The engine's `payload::normalise()` and `sql::column_value()`
  (COALESCE) read either shape until `migration::finish_field()` removes that config. Any new reader
  goes through these two.
- The define form is a modal `dynamic_form`; the editor's data (locked keys, users, domains) rides
  in data attributes of the textarea, because `js_call_amd()` warns past 1024 characters.
  `bridge\dictionaries::domains()` (engine) reads `\local_dictionaries\api::list_domains()`; its `parentdomain`
  feeds the editor's parent-mismatch warning and `schema::validate()`'s `$parentdomains` rule.

### Canonical payload and projection

- **`user_info_data.data` is the single source of truth.** `edit_load_user_data()`, `is_empty()`
  and `display_data()` all decode it, because core's `profile_save_custom_fields()` (auth_db and
  auth_ldap attribute sync) writes it without calling this plugin.
- `profilefield_repeatable_data` is a projection for Report Builder only: `edit_save_data()` writes
  the payload and `projection\projector::sync()` in one delegated transaction, as must any new writer.
- Drift is repaired by the `user_updated`/`user_created` observers and the daily
  `task\reproject_sets`. The observers are non-internal (`'internal' => false`): auth_ldap fires the
  event before `profile_save_custom_fields()` writes, inside one delegated transaction per user, and
  core buffers non-internal observers until that transaction commits. Outside a transaction the
  event still precedes the write, and auth_db sync of existing users fires none, so the daily task
  repairs those; restored users have no rows either. Tests that trigger these events call
  `preventResetByRollback()`, because PostgreSQL runs every PHPUnit test inside a transaction.
- `reproject_record()` re-reads inside its transaction and decides staleness again: the snapshot
  that selected the record may predate a user save, and writing from it would clobber that save.
- `is_current()` compares sets as key-sorted JSON, because JSONB keeps neither key order nor spacing.
- The renderer reads projection rows only for each set's last-sync time, matched by content, so a
  stale row never lends its timestamp to another set.
- `upsert_set_row()` has a PostgreSQL `ON CONFLICT` branch and a portable select-then-write branch:
  a schema or semantic change lands in both, or one DB family drifts.
- Caps `payload::MAX_SETS` (200) and `MAX_VALUE_LENGTH` (1024): `edit_validate_field()` checks the
  **raw** JSON (`validator::raw_error()`), because `payload::normalise()` truncates silently.
- Core deletes `user_info_data` directly on user and field deletion, so the observers purge the
  projection by `userid`/`fieldid`: the privacy API cannot see those orphans.

### Edit form

- The value travels in a hidden input that `repeatable.js` reads once on init and rewrites only on
  form submit; the widget holds the state in between. The
  hidden input sits inside a form group named after the field, because QuickForm prints no error
  for a bare hidden element; the widget points at the error with `aria-describedby`.
- Several repeatable fields can share one form: never assume a single instance in the JS.
- Dictionary columns marked `autocomplete` (companion with `api::lookup()` AND `api::can_read()` for
  the editing user, because the search service refuses the rest) are selects enhanced by
  `core/form-autocomplete` with `dictionary_transport`; the rest stay text inputs. Core addresses
  an enhanced select by id and keeps form handlers that look its elements up on submit, so a select
  keeps one id for life and a removed set holding one is hidden (`data-removed`), never deleted.
  `markup.js` exists so `repeatable.js` loads without the companion (the transport imports it).
- The widget reads values on submit itself: core turns typed text into a tag asynchronously, after
  the payload is built, so `controlValue()` reads an open column's typed text. Preloaded labels ride
  on the hidden input's `data-labels` (resolver, read in `edit_after_data()` from the element value,
  so a failed validation keeps them).
- Code checks live in `edit_validate_field()` → the engine's `local\validator` (one `api::lookup()`
  per readable domain); like `required`, web services and sync skip them. A codes-only column accepts
  the code already stored for that user and key (folded), read from `$this->data`, never the submission.
  `companion_available()` is the seam tests override to run without the companion.
- **No oracle on restricted dictionaries**: `lookup()` and `resolve_bulk()` are ungated, so every
  path that turns a SUBMITTED code into an answer is decided on `can_read_domain()` (the editing
  user): unreadable domains get labels only for stored codes, one uniform error for any other code
  of a codes-only column, no lookup and no parent check (also when only the parent's domain is
  unreadable), and no autocomplete.

### PostgreSQL

- Everything PG-specific sits behind `sql::is_postgres()` (engine) or `get_dbfamily()` in
  `db/upgradelib.php`, and no-ops on MariaDB (CI runs both).
- **No index layer** (owner decision D3, measured): Report Builder's filters (`ILIKE '%v%'`,
  `LOWER(expr) = LOWER(v)`) use no expression or GIN index, so the projection is a text column read
  as `(data)::jsonb ->> key`. Do not add indexes back without an `EXPLAIN` that uses them.
- Upgrade step 2026042001 removes the r1 layer: index ownership is an exact match on the two shapes
  it built (`pfrd_f<fieldid>_<16 hex>`, `pfrd_<8 hex>_gin_ix`), never a `LIKE` prefix (a bare
  prefix let field 1 claim fields 10-19); indexes go before the column turns text, because neither
  index type accepts a text column. DDL takes no bound parameters: `profilefield_repeatable_quote_identifier()`.

### Report Builder

- Core shows a profile field as one column over the raw blob with no per-field hook, so the
  datasource joins one `repeatable_<shortname>` entity per field to the core `user` entity: one row
  per set. Column names follow the column key (see "Column schema"), so a label rename keeps saved
  reports.
- Every column value and filter option label goes through `s()`: core's autocomplete prints option
  labels unescaped (stored XSS otherwise).
- Every column and filter is `set_is_available(is_visible(system))` on a field instance built with
  `\core\user::SUPPORT_USER`, mirroring core's `user_profile_fields` helper: with user id 0 the
  visibility checks take the wrong branch.
- The datasource is available only on the `postgres` and `mysql` families, because
  the engine's `sql::subitem_value()` (the one path for sub-item SQL) has no SQL Server or Oracle form.
- Two repeatable fields in one report cross-join their sets: one repeatable field per report.

### Companion dictionary plugin (soft dependency)

- Not in `dependencies`; every touchpoint guards with `class_exists()`, so the plugin installs,
  validates and renders without the companion. Touchpoints, all through the companion's public API
  and never its tables: `\local_dictionaries\resolver::resolve()`/`resolve_bulk()` (renderer,
  entity, edit-form labels), `domain_exists()` (`column_editor`), and through the engine's
  `bridge\dictionaries`: `api::list_domains()` (`domains()`), `api::can_read()` and `api::lookup()`
  (edit form, probed by `lookup_available()`), `resolver::DOMAIN_PATTERN` (`domain_pattern()`, whose
  fallback the engine pins), `fold_code()`; plus the AMD `local_dictionaries/autocomplete_transport`.
- The companion is `local_dictionaries` (repo `uaiblaine/moodle-local_dictionaries`). Its domains have
  a `visibility`; the field resolves codes the viewer already sees, which the companion's contract
  leaves ungated, so display checks no visibility. Searching is gated by the companion's service:
  the edit form asks `api::can_read()` first and falls back to a text input.
- Each branch's `ci.yml` installs the companion's matching `MOODLE_<NNN>_STABLE` branch
  (`plugin-dependencies`): a contract change in either repo turns the other's CI red. The renderer
  prefetches labels once per domain: keep it N+1-free.

### customfield_pattern (soft dependency, pattern columns)

- Every call goes through the engine's `local\bridge\pattern`, whose `available()` probes
  `\customfield_pattern\api`, its methods and `api::CONFIG_VERSION === pattern::CONFIG_VERSION` (another version may read
  a stored setting differently, so it counts as absent). The plugin installs on 5.1+ only: on 4.5 and
  5.0 the field always runs "absent", with no branching beyond that probe.
- Absent means: the mode is not offered, `schema::validate()` refuses a new or changed pattern column
  (`$patternerrors === null`) and accepts a stored one only with the same `pattern` object (strict
  `===` after a recursive key sort, never a loose `==`, which reads "1000" as "1e3"), read from
  `pattern_objects()` of the STORED field; values are validated by nothing, stored as typed, shown
  as stored.
- Present: `edit_validate_field()` → the engine's `local\validator` (the plugin's message is already safe
  HTML, passed into `errorpattern` unescaped); `edit_save_data_preprocess()` normalises (raw/masked),
  so web services and sync store the configured shape too; `edit_load_user_data()` formats, so a raw
  value is edited masked; renderer, Report Builder value callback (`s()` after formatting) and privacy
  export (plain text) format. `pattern_available()` on the field and the definition is the test seam.
- The widget builds inputs in JS, so the plugin's `input_attributes()` ride in the columns JSON
  (`inputattributes`; `repeatable.js` sets only the four names the API emits) and `api::js_init()`
  loads the formatter, whose document-level listener covers inputs of sets added later. The editor gets availability and
  presets (with their masks) from the textarea's `data-pattern` / `data-presets`.

### Display and styles

- `show_field_content()` returns false: the field renders as its own profile category through
  `profilefield_repeatable_myprofile_navigation()` (`lib.php`), not inline.
- No Bootstrap 5 utility at all, stricter than the fleet polyfill rule: the field renders inside
  core pages where the plugin owns no ancestor to gate on, so own each declaration under a
  `profilefield-repeatable-*` class (`tests/bootstrap_compat_test.php` enforces it).

## Testing notes

- Fields come from the plugin's data generator (`schema_json()`, `create_field()`), which builds a
  v2 schema from labels or column arrays; form behaviour (the error group) goes through
  `tests/fixtures/edit_form.php`, which calls the edit hooks in `user/edit.php`'s order.
  Re-keying is tested on a v1 field converted as the definition form does
  (`tests/local/migration_test.php`).
- PG-only behaviour is asserted behind a dbfamily check or `markTestSkipped()`, and
  companion-dependent tests skip when its classes or tables are absent. Pattern tests that need
  customfield_pattern skip without it; those describing its absence either use the
  `pattern_available()` seam (every leg) or skip when it IS installed (the 4.5 branch runs them).
- Behat: scope profile-form locators to the category fieldset (`in the "Testing" "fieldset"`),
  because a bare "Language" also matches "Preferred language".
- `.mdlshield/config.yml` pins `versions: ["5.2"]`: read core-API findings knowing 4.5 is supported.

## When in doubt

Follow the patterns in existing files; if a new file matches no existing shape, re-examine the
approach.
