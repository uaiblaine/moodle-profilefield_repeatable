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
`~/dev/moodle-local_repeatable`): the column schema, payload reading, Report Builder SQL and entity,
validation, the companion bridges, stored-value counting, the privacy export, the edit widget, the
column editor, the display renderer (templates, CSS, AMD) and the projection writer live there; this
plugin is its profile-field adapter (see "Engine and adapter"). **One branch per Moodle version**: `MOODLE_405_STABLE`,
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
classes/local/source.php           implements the engine's local\projection\source: {user_info_data}, owner userid
classes/local/projection.php       reproject_user() (joins user_info_data to user_info_field; engine projector)
classes/local/migration.php        re-keying of payloads of a field converted from v1 (run by the task)
classes/observer.php   cleanup on user/field deletion; reprojection on user_updated/user_created
classes/output/display_renderer.php  layout decision, accordion id, projection rows; the engine's sets_renderer renders
classes/reportbuilder/             datasource repeatable_sets; entity repeatable_set (extends the engine's sets)
classes/privacy/       provider (the export of one payload is local_repeatable's set_exporter)
db/upgradelib.php      upgrade-step logic; self-contained (its own identifier quoting, no engine calls)
classes/task/reproject_sets.php    scheduled, daily: repair stale projections
classes/task/rekey_payloads.php    adhoc: re-keys a field the definition form converted from v1
```

No `amd/`, `templates/` or `styles.css`: the widget, column editor, renderer, their templates,
stylesheet and AMD modules are `local_repeatable`'s (stage C). Engine classes never call
profile-field APIs: this adapter passes the stored payload, the can-read callable and the seams'
availability flags.

### Engine and adapter (local_repeatable)

- From `local_repeatable`: `local\schema`, `local\payload` (caps, `normalise()`, `column_value()`,
  `encode()`), `local\sql` (`is_postgres()`, `subitem_value()`, `column_value()`), `local\validator`,
  `local\stored_values::keys_in_use()`, `local\bridge\dictionaries`, `local\bridge\pattern`,
  `privacy\set_exporter` (stages B1-B2); `form\widget`, `form\column_editor`, `output\sets_renderer`
  with the three templates, `styles.css` and the AMD modules `local_repeatable/{repeatable,markup,
  dictionary_transport,columneditor,displayaccordion}` (C1-C2); `local\projection\{store,source,
  projector}` and the abstract `reportbuilder\local\entities\sets` (C3). Their rules, unit tests and
  mutation gates live there; this repository tests the wiring (stored payload from `$this->data`,
  `can_read_domain()` of the editing user, the `companion_available()`/`pattern_available()` seams,
  end-to-end form, save, display through the field, privacy, observers, tasks and Report Builder).
- The ids the adapter builds stay `profilefield-repeatable-<fieldid>-<userid>-<shortname>` (edit
  widget wrapper) and `profilefield-repeatable-display-...` (accordion): the engine's JS reads them by
  exact value from its init config and no engine template, CSS or JS matches their prefix.
- `version.php` declares `dependencies`; core checks it against the version on disk and installs local
  plugins last, so an upgrade step of this field may use engine classes (they autoload) but no engine
  DB state. `db/upgradelib.php` calls neither, on purpose: upgrade steps are frozen history.
- Strings an engine class prints come from `local_repeatable` (the moved keys were deleted here,
  `repeatableset` included). This plugin keeps only its own: `pluginname`, `columns` (+ `_help`, the
  define form's textarea), `datasourcerepeatablesets`, `entityrepeatablesetnamed`,
  `profilewidgettitle`, the two task names and the privacy metadata.

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
  `[a-z][a-z0-9_]{0,39}` (the engine's `schema::generate_key()` and its column editor's JS
  `generateKey()` stay in step there). Keys holding values (`local\stored_values::usage()`, read from the stored field's
  `user_info_data` payloads, never the projection, and counted by the engine) are locked in the editor AND enforced by
  `define_validate_specific()`: a schema that removes or re-keys one is rejected. A stored schema
  is re-checked on read: `from_json()` drops columns with a bad or colliding key.
- `from_v1()` never gives a key equal to another column's label (or its `_name`/`_code`): v1
  payloads hold values under labels, and such a key would read the other column's value.
- Per-column `required` and the code checks run on the form (`edit_validate_field()`, which the CSV
  upload also calls) and on web service requests (`core_user_create_users`, `core_user_update_users`,
  also AJAX): both reach the one private `submitted_error()`, which `edit_save_data()` runs before any
  write only when `save_checks_apply()` says the request is a web service or the AJAX call of a
  logged-in user, refusing a failing value with `errorsaverejected` (the field's name, never a code,
  label or domain). The documented residual: login-time account creation and updates
  (`create_user_record()`, `update_user_record_by_id()`, auth_oauth2) and auth_db/auth_ldap account
  creation call `profile_save_data()` without the form and store the external source's value (normalised)
  unchecked, because refusing would lock the user out or abort the sync; the source is configured by an
  administrator, a user cannot choose it. auth_db/auth_ldap attribute updates use core's
  `profile_save_custom_fields()`, which never reaches this class. A JSON null under a key counts as absent
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
  in data attributes of the textarea, because `js_call_amd()` warns past 1024 characters:
  `define_after_data()` reads the usage of the STORED field and hands it to the engine's
  `column_editor::attributes()`, then `init_js()` loads `local_repeatable/columneditor`.
  `bridge\dictionaries::domains()` (engine) reads `\local_dictionaries\api::list_domains()`; its `parentdomain`
  feeds the editor's parent-mismatch warning and `schema::validate()`'s `$parentdomains` rule.

### Canonical payload and projection

- **`user_info_data.data` is the single source of truth.** `edit_load_user_data()`, `is_empty()`
  and `display_data()` all decode it, because core's `profile_save_custom_fields()` (auth_db and
  auth_ldap attribute sync) writes it without calling this plugin.
- `profilefield_repeatable_data` is a projection for Report Builder only: `edit_save_data()` writes
  the payload and the engine's `projector::sync()` (with this plugin's `local\source`) in one
  delegated transaction, as must any new writer.
- Drift is repaired by the `user_updated`/`user_created` observers and the daily
  `task\reproject_sets`. The observers are non-internal (`'internal' => false`): auth_ldap fires the
  event before `profile_save_custom_fields()` writes, inside one delegated transaction per user, and
  core buffers non-internal observers until that transaction commits. Outside a transaction the
  event still precedes the write, and auth_db sync of existing users fires none, so the daily task
  repairs those; restored users have no rows either. Tests that trigger these events call
  `preventResetByRollback()`, because PostgreSQL runs every PHPUnit test inside a transaction.
- The writer's own rules are the engine's (`local_repeatable` `local\projection\projector`):
  `reproject_record()` re-reads inside its transaction, `is_current()` compares key-sorted JSON, the
  upsert has an `ON CONFLICT` and a portable branch, and the renderer dates a set only from a row
  matching its content. `local\source` must keep answering what the record is NOW
  (`canonical()` re-reads `{user_info_data}`), or the re-check reads a stale snapshot.
- Caps `payload::MAX_SETS` (200) and `MAX_VALUE_LENGTH` (1024): `edit_validate_field()` checks the
  **raw** JSON (`validator::raw_error()`), because `payload::normalise()` truncates silently.
- Core deletes `user_info_data` directly on user and field deletion, so the observers purge the
  projection by `userid`/`fieldid`: the privacy API cannot see those orphans.

### Edit form

- The widget (HTML, labels, JS) is the engine's `form\widget` and `local_repeatable/repeatable`; its
  JS rules (state held between init and submit, one id per select for life, several instances per
  form, `markup.js` loading without the companion) live there. The adapter (`get_widget()`,
  `widget_ids()`) hands it the schema, `can_read_domain()` of the editing user and the two seams,
  and owns the form integration below.
- The value travels in a hidden input that sits inside a form group named after the field, because
  QuickForm prints no error for a bare hidden element; the widget points at the error
  (`fgroup_id_error_<inputname>`) with `aria-describedby`. Several repeatable fields can share one
  form: the wrapper id carries the field id, the user id and the shortname.
- Dictionary columns are `autocomplete` only with the companion's `api::lookup()` AND
  `api::can_read()` for the editing user, because the search service refuses the rest; the rest stay
  text inputs. Preloaded labels ride on the hidden input's `data-labels` (`widget::labels()`, read in
  `edit_after_data()` from the element value, so a failed validation keeps them, with the STORED
  codes from the validator).
- Code checks live in `edit_validate_field()` → the engine's `local\validator` (one `api::lookup()`
  per readable domain), shared by `edit_validate_field()` and `edit_save_data()` through
  `submitted_error()`; login and auth sync skip them (see above). A codes-only column accepts
  the code already stored for that user and key (folded), read from `$this->data`, never the submission.
  `companion_available()` is the seam tests override to run without the companion.
- **No oracle on restricted dictionaries**: `lookup()` and `resolve_bulk()` are ungated, so every
  path that turns a SUBMITTED code into an answer is decided on `can_read_domain()` (the editing
  user): unreadable domains get labels only for stored codes, one uniform error for any other code
  of a codes-only column, no lookup and no parent check (also when only the parent's domain is
  unreadable), and no autocomplete. `tests/save_path_test.php` holds this for the paths that skip the
  form (operator with `moodle/user:update` through `profile_save_data()` and the update web service).

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
  reports; `repeatable_sets_test::test_column_and_filter_identifiers_are_stable` pins them to a
  literal list: never edit that list to make a change pass.
- The columns, filters and their escaping (`s()` on every value and filter option label) are the
  engine's `reportbuilder\local\entities\sets`; `repeatable_set` extends it with the field's schema,
  the projection table and `is_available()`.
- Every column and filter is `set_is_available(is_visible(system))` on a field instance built with
  `\core\user::SUPPORT_USER`, mirroring core's `user_profile_fields` helper: with user id 0 the
  visibility checks take the wrong branch.
- The datasource is available only on the `postgres` and `mysql` families, because
  the engine's `sql::subitem_value()` (the one path for sub-item SQL) has no SQL Server or Oracle form.
- Two repeatable fields in one report cross-join their sets: one repeatable field per report.

### Companion dictionary plugin (soft dependency)

- Not in `dependencies`; every touchpoint guards with `class_exists()`, so the plugin installs,
  validates and renders without the companion. The field itself calls only `api::can_read()`
  (`can_read_domain()`, behind the `companion_available()` seam); every other touchpoint is the
  engine's (`bridge\dictionaries`, the renderer, the entity, the widget and column editor, the AMD
  transport), all through the companion's public API and never its tables.
- The companion is `local_dictionaries` (repo `uaiblaine/moodle-local_dictionaries`). Its domains have
  a `visibility`; the field resolves codes the viewer already sees, which the companion's contract
  leaves ungated, so display checks no visibility. Searching is gated by the companion's service:
  the edit form asks `api::can_read()` first and falls back to a text input.
- Each branch's `ci.yml` installs the companion's matching `MOODLE_<NNN>_STABLE` branch
  (`plugin-dependencies`): a contract change in either repo turns the other's CI red.

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
  value is edited masked; the engine's renderer, Report Builder value callback (`s()` after
  formatting) and privacy export (plain text) format. `pattern_available()` on the field and the
  definition is the test seam, handed to the engine's widget, column editor and validator.

### Display and styles

- `show_field_content()` returns false: the field renders as its own profile category through
  `profilefield_repeatable_myprofile_navigation()` (`lib.php`), not inline.
- `display_renderer` decides the layout (accordion on `/user/profile.php` and `/user/view.php`, plain
  list elsewhere), names the accordion and reads this user's projection rows; the engine's
  `output\sets_renderer` renders. The templates, the `local-repeatable-*` stylesheet and its
  no-Bootstrap-5 rule (`bootstrap_compat_test`) are the engine's: this plugin ships no CSS.

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
