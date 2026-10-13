# Claude instructions for `profilefield_repeatable`

Auto-loaded whenever Claude works in this plugin's tree. **Fleet-wide standards live in
`~/dev/CLAUDE.md`** (coding style, CI gates, lang strings, the `mdl` environment, git) — do not
repeat them here. This file keeps only what is true for this plugin, as rules with a one-line
reason each; every session and subagent pays for it on every cache write, so the long version of a
gotcha goes to `docs/` (export-ignored) with a pointer from the rule.

Plugin context: a Moodle **user profile field** plugin ("Repeatable", `datatype = repeatable`)
that stores repeatable sets of sub-items per user. The canonical value is a JSON payload in core's
`user_info_data.data`; the plugin's own table `profilefield_repeatable_data` holds one row per set
as a projection for Report Builder (JSONB on PostgreSQL, with a GIN index and per-sub-item
expression indexes). Optional code→label resolution comes from the companion dictionary plugin
(soft dependency, see below). Supports Moodle **4.5 through 5.3**
(`$plugin->requires = 2024100100`, `$plugin->supported = [405, 503]`), MATURITY_STABLE, one
branch (`main`). CI is the moodle-an-hochschulen reusable workflow, one job per supported
branch in `.github/workflows/ci.yml` — **update those jobs when `supported` changes**. Development
happens on the m502 stack; the stacks that mount this repo at `user/profile/field/repeatable` follow
`$plugin->supported` (`~/dev/moodle-dev/plugins.conf`, `auto`; `mdl mounts` regenerates them).

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
field.class.php        profile_field_repeatable: edit form, validation, save, display entry
define.class.php       profile_define_repeatable: admin definition (schema JSON + column editor)
classes/helper.php     payload normalise/encode, caps, PG helpers, sql_column_value()
classes/local/schema.php           column schema v2: parse, from_v1(), validate(), keys, accessors
classes/local/migration.php        re-keying of payloads after the v2 upgrade (shared with the task)
classes/local/pattern_columns.php  the one door to customfield_pattern's api (pattern columns), soft dependency
classes/local/projection.php       per-set rows: sync(), is_current(), reproject_*(), keys_in_use()
classes/observer.php   cleanup on user/field deletion; reprojection on user_updated/user_created
classes/output/display_renderer.php  accordion (profile pages) or plain list, bulk label prefetch
classes/reportbuilder/             datasource repeatable_sets + entity repeatable_set
classes/task/reconcile_indexes.php adhoc: PG expression indexes
classes/task/reproject_sets.php    scheduled, daily: repair stale projections
classes/task/rekey_payloads.php    adhoc: finishes the v2 migration of a field above 500 payloads
db/upgradelib.php      the v2 upgrade step: convert fields, map saved report identifiers
amd/src/               repeatable.js (edit widget), dictionary_transport.js (its autocomplete transport),
                       markup.js (shared by both), columneditor.js (define form), displayaccordion.js
```

## Versions

`$plugin->version` is date-based (`YYYYMMDDXX`) while `main` serves every supported Moodle version.
On the split into one branch per Moodle version, each branch numbers itself in that version's
namespace (fleet rule, `~/dev/CLAUDE.md`). The split and r1 plan: `docs/audit-and-direction-2026-10.md` §3.

## Architecture gotchas

### Legacy profile-field API

- `field.class.php` and `define.class.php` keep core's fixed global class names and are loaded by
  `require_once`: tests require `user/profile/lib.php` and the file explicitly. New code goes under
  `classes/`, which is autoloaded.
- Plugin classes autoload during install and upgrade (`CACHE_DISABLE_ALL` makes core rescan the
  class map). `db/lib.php` stays only as the install hook's procedural entry point to
  `helper::ensure_postgres_jsonb_support()`; it is not needed to avoid the autoloader.

### Column schema (v2)

- `param1` holds `{"version":2,"columns":[{key,label,mode,domain?,parent?,pattern?,title?,subline?,
  required?,indexed?}]}`; `param2`..`param4` are cleared on save; `defaultdata` is forced to `'{}'`.
  `local\schema::validate()` is the only gate (exactly one title, subline/indexed ≤ 3, ≤ 20 columns,
  parent = an earlier dictionary column, no two columns producing one Report Builder identifier);
  the constructor normalises and never rejects. A `pattern` column is checked by customfield_pattern
  (see below) and keeps its `pattern` object verbatim.
- **Payloads and Report Builder identifiers are keyed by column key**, never by label. A key is
  `[a-z][a-z0-9_]{0,39}`; `generate_key()` and `amd/src/columneditor.js` `generateKey()` must stay
  in step. Keys holding values (`local\stored_values`, read from the stored field's
  `user_info_data` payloads, never the projection) are locked in the editor AND enforced by
  `define_validate_specific()`: a schema that removes or re-keys one is rejected. A stored schema
  is re-checked on read: `from_json()` drops columns with a bad or colliding key.
- `from_v1()` never gives a key equal to another column's label (or its `_name`/`_code`): v1
  payloads hold values under labels, and such a key would read the other column's value.
- Per-column `required` is checked in `edit_validate_field()` only: web services, CSV upload and
  auth sync save through `edit_save_data()` without it. A JSON null under a key counts as absent
  (`helper::column_value()`), as COALESCE treats PostgreSQL's NULL.
- The upgrade is resumable: `legacykeys_<fieldid>` is written for every converted field (`{}` when
  no label differs from its key) and removed only by `migration::finish_field()`; a v2 field that
  still has it is resumed. The rekey's compare-and-set is exact (`sql_equal()` case and accent
  sensitive on pgsql/mysql, PHP comparison elsewhere).
- Report Builder identifiers: free `<key>`, dictionary `<key>_name` + `<key>_code`, plus
  `setnumber`. The upgrade maps 2.x `subitemN`/`subitemnameN`/`subitemcodeN` (1-based position) in
  `reportbuilder_column`, `reportbuilder_filter`, `conditiondata` and users' filter values.
- **v1-tolerant reads**: a field still on the four lists is read through `schema::from_v1()`; a
  converted field whose payloads are not re-keyed yet has its key → 2.x label map in the plugin
  config `legacykeys_<fieldid>`. `helper::normalise_payload()` and `helper::sql_column_value()`
  (COALESCE) read either shape until `migration::finish_field()` removes that config. Any new reader
  goes through these two.
- The define form is a modal `dynamic_form`; the editor's data (locked keys, users, domains) rides
  in data attributes of the textarea, because `js_call_amd()` warns past 1024 characters.
  `helper::companion_domains()` reads `\local_dictionaries\api::list_domains()`; its `parentdomain`
  feeds the editor's parent-mismatch warning and `schema::validate()`'s `$parentdomains` rule.

### Canonical payload and projection

- **`user_info_data.data` is the single source of truth.** `edit_load_user_data()`, `is_empty()`
  and `display_data()` all decode it, because core's `profile_save_custom_fields()` (auth_db and
  auth_ldap attribute sync) writes it without calling this plugin.
- `profilefield_repeatable_data` is a projection for Report Builder only: `edit_save_data()` writes
  the payload and `local\projection::sync()` in one delegated transaction, as must any new writer.
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
- Caps `helper::MAX_SETS` (200) and `MAX_VALUE_LENGTH` (1024): `edit_validate_field()` checks the
  **raw** JSON, because `normalise_payload()` truncates silently and hides the excess.
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
- Code checks live in `edit_validate_field()` → `validate_dictionary_codes()` (one `api::lookup()`
  per readable domain); like `required`, web services and sync skip them. A codes-only column accepts
  the code already stored for that user and key (folded), read from `$this->data`, never the submission.
  `companion_available()` is the seam tests override to run without the companion.
- **No oracle on restricted dictionaries**: `lookup()` and `resolve_bulk()` are ungated, so every
  path that turns a SUBMITTED code into an answer is decided on `can_read_domain()` (the editing
  user): unreadable domains get labels only for stored codes, one uniform error for any other code
  of a codes-only column, no lookup and no parent check (also when only the parent's domain is
  unreadable), and no autocomplete.

### PostgreSQL layer

- Everything PG-specific sits behind `helper::is_postgres()` and no-ops on MariaDB (CI runs both).
- `CREATE/DROP INDEX CONCURRENTLY` cannot run inside a transaction, so index work lives in the
  `reconcile_indexes` adhoc task, never in a web request or an upgrade step. The one synchronous
  caller is `drop_all_for_field()` via `define_after_delete()`, which core never calls.
- Index ownership is decided by an exact match on `INDEX_NAME_PATTERN`
  (`pfrd_f<fieldid>_<16 hex>`), after a `LIKE` prefix whose `_` is escaped: a bare prefix let
  field 1 claim the indexes of fields 10-19 and 100-199. Every reconcile run also sweeps orphaned
  indexes, because core calls nothing on field deletion.
- DDL takes no bound parameters, so `helper::quote_identifier()` / `quote_literal()` quote it; DML
  stays on placeholders. `db/uninstall.php` drops the PG artefacts XMLDB does not know about.

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
  `helper::sql_subitem_value()` (the one path for sub-item SQL) has no SQL Server or Oracle form.
- Two repeatable fields in one report cross-join their sets: one repeatable field per report.

### Companion dictionary plugin (soft dependency)

- No `dependencies` in `version.php`; every touchpoint guards with `class_exists()`, so the plugin
  installs, validates and renders without the companion. Touchpoints, all through the companion's
  public API and never its tables: `\local_dictionaries\resolver::resolve()`/`resolve_bulk()`
  (renderer, entity, edit-form labels), `domain_exists()` (`define.class.php`), `api::list_domains()`
  (`helper::companion_domains()`), `api::can_read()` and `api::lookup()` (edit form, probed by
  `helper::companion_lookup_available()`), the AMD `local_dictionaries/autocomplete_transport`, and
  `resolver::DOMAIN_PATTERN` read by `helper::domain_pattern()`, with `helper::DOMAIN_PATTERN` as
  the fallback that a test pins equal. `helper::fold_code()` mirrors the companion's internal code key.
- The companion is `local_dictionaries` (repo `uaiblaine/moodle-local_dictionaries`). Its domains have
  a `visibility`; the field resolves codes the viewer already sees, which the companion's contract
  leaves ungated, so display checks no visibility. Searching is gated by the companion's service:
  the edit form asks `api::can_read()` first and falls back to a text input.
- `ci.yml` installs the companion's `main` (`plugin-dependencies`): a contract change in either repo
  turns the other's CI red. The renderer prefetches labels once per domain: keep it N+1-free.

### customfield_pattern (soft dependency, pattern columns)

- Every call goes through `local\pattern_columns`, whose `available()` probes `\customfield_pattern\api`,
  its methods and `api::CONFIG_VERSION === pattern_columns::CONFIG_VERSION` (another version may read
  a stored setting differently, so it counts as absent). The plugin installs on 5.1+ only: on 4.5 and
  5.0 the field always runs "absent", with no branching beyond that probe.
- Absent means: the mode is not offered, `schema::validate()` refuses a new or changed pattern column
  (`$patternerrors === null`) and accepts a stored one only with the same `pattern` object (loose `==`,
  key order ignored, from `pattern_objects()` of the STORED field); values are validated by nothing,
  stored as typed, shown as stored.
- Present: `edit_validate_field()` → `validate_pattern_values()` (the plugin's message is already safe
  HTML, passed into `errorpattern` unescaped); `edit_save_data_preprocess()` normalises (raw/masked),
  so web services and sync store the configured shape too; `edit_load_user_data()` formats, so a raw
  value is edited masked; renderer, Report Builder value callback (`s()` after formatting) and privacy
  export (plain text) format. `pattern_available()` on the field and the definition is the test seam.
- The widget builds inputs in JS, so the plugin's `input_attributes()` ride in the columns JSON
  (`inputattributes`, allowlisted names in `repeatable.js`) and `api::js_init()` loads the formatter,
  whose document-level listener covers inputs of sets added later. The editor gets availability and
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
  `tests/fixtures/edit_form.php`, which calls the edit hooks in `user/edit.php`'s order. The
  upgrade is tested by calling `xmldb_profilefield_repeatable_upgrade()` on v1 rows
  (`tests/upgrade_test.php`).
- PG-only behaviour is asserted behind a dbfamily check or `markTestSkipped()`, and
  companion-dependent tests skip when its classes or tables are absent. Pattern tests that need
  customfield_pattern skip without it; those describing its absence either use the
  `pattern_available()` seam (every leg) or skip when it IS installed (the 4.5/5.0 legs run them).
- Behat: scope profile-form locators to the category fieldset (`in the "Testing" "fieldset"`),
  because a bare "Language" also matches "Preferred language".
- `.mdlshield/config.yml` pins `versions: ["5.2"]`: read core-API findings knowing 4.5 is supported.

## When in doubt

Follow the patterns in existing files; if a new file matches no existing shape, re-examine the
approach.
