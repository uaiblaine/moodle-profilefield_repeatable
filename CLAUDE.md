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
(soft dependency, see below). Supports Moodle **4.5 through 5.2**
(`$plugin->requires = 2024100100`, `$plugin->supported = [405, 502]`), MATURITY_STABLE, one
branch (`main`). CI is the moodle-an-hochschulen reusable workflow, one job per supported
branch in `.github/workflows/ci.yml` — **update those jobs when `supported` changes**. Development
happens on the m502 stack; this repo is mounted into m405, m501, m502 and m502b at
`user/profile/field/repeatable` (see `~/dev/moodle-dev/plugins.conf`).

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
define.class.php       profile_define_repeatable: admin definition, param1..param4 validation
classes/helper.php     payload parse/normalise/encode, caps, PG helpers, sql_subitem_value()
classes/local/projection.php       per-set rows: sync(), is_current(), reproject_*()
classes/observer.php   cleanup on user/field deletion; reprojection on user_updated/user_created
classes/output/display_renderer.php  accordion (profile pages) or plain list, bulk label prefetch
classes/reportbuilder/             datasource repeatable_sets + entity repeatable_set
classes/task/reconcile_indexes.php adhoc: PG expression indexes
classes/task/reproject_sets.php    scheduled, daily: repair stale projections
amd/src/               repeatable.js (edit widget), displayaccordion.js
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
- Install and upgrade steps call only the `db/lib.php` procedural wrappers: no autoloading there.

### Field parameters

| Column | Meaning |
|---|---|
| `param1` | Sub-items, one per line (required, duplicates rejected) |
| `param2` | Indexed sub-items (max 3, must exist in param1) → PG expression indexes |
| `param3` | Reference mappings `subitem\|domain`, one per line |
| `param4` | Subline sub-items (max 3, never the primary sub-item) |
| `defaultdata` | Forced to `'{}'` |

- `define_save_preprocess()` canonicalises all four; split lines with `preg_split('/\R/u')`, never
  `explode("\n")`, because CRLF input must keep working.
- Column schema v2 (one JSON schema in place of the four lists) is planned before r1:
  `docs/audit-and-direction-2026-10.md` §4.5.

### Canonical payload and projection

- **`user_info_data.data` is the single source of truth.** `edit_load_user_data()`, `is_empty()`
  and `display_data()` all decode it, because core's `profile_save_custom_fields()` (auth_db and
  auth_ldap attribute sync) writes it without calling this plugin.
- `profilefield_repeatable_data` is a projection for Report Builder only: `edit_save_data()` writes
  the payload and `local\projection::sync()` in one delegated transaction, as must any new writer.
- Drift is repaired by the `user_updated`/`user_created` observers and the daily
  `task\reproject_sets`, because auth_db sync fires no event and restored users have no rows.
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

- The value travels in a hidden input that `repeatable.js` keeps in step with the widget. The
  hidden input sits inside a form group named after the field, because QuickForm prints no error
  for a bare hidden element; the widget points at the error with `aria-describedby`.
- Several repeatable fields can share one form: never assume a single instance in the JS.

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
  per set. Internal column names use a 1-based sub-item index (`subitem1`, `subitemcode1`), so
  identifiers stay stable for Behat and saved reports.
- Every column value and filter option label goes through `s()`: core's autocomplete prints option
  labels unescaped (stored XSS otherwise).
- Every column and filter is `set_is_available(is_visible(system))` on a field instance built with
  `\core\user::SUPPORT_USER`, mirroring core's `user_profile_fields` helper: with user id 0 the
  visibility checks take the wrong branch.
- The datasource is available only on the `postgres` and `mysql` families, because
  `helper::sql_subitem_value()` (the one path for sub-item SQL) has no SQL Server or Oracle form.
- Two repeatable fields in one report cross-join their sets: one repeatable field per report.

### Companion dictionary plugin (soft dependency)

- No `dependencies` in `version.php`; every touchpoint guards with `class_exists()`/`table_exists()`,
  so the plugin installs, validates and renders without the companion. Touchpoints:
  `resolver::resolve()`/`resolve_bulk()` (renderer, entity), the domain table read by
  `define.class.php`, and `/^[a-z0-9_]+$/` in `helper::DOMAIN_PATTERN` and `define.class.php`.
- The companion is now `local_dictionaries` (repo `uaiblaine/moodle-local_dictionaries`); this
  branch still calls the old `\local_profilefield_repeatable\resolver` and table
  `local_profilefield_repeatable_domain`, and a follow-up switches to `\local_dictionaries\resolver`
  and `\local_dictionaries\resolver::DOMAIN_PATTERN`.
- `ci.yml` installs the companion's `main` (`plugin-dependencies`): a contract change in either repo
  turns the other's CI red. The renderer prefetches labels once per domain: keep it N+1-free.

### Display and styles

- `show_field_content()` returns false: the field renders as its own profile category through
  `profilefield_repeatable_myprofile_navigation()` (`lib.php`), not inline.
- No Bootstrap 5 utility at all, stricter than the fleet polyfill rule: the field renders inside
  core pages where the plugin owns no ancestor to gate on, so own each declaration under a
  `profilefield-repeatable-*` class (`tests/bootstrap_compat_test.php` enforces it).

## Testing notes

- Fields come from `create_custom_profile_field()` (`field_class_test.php::create_field()`); form
  behaviour (the error group) goes through `tests/fixtures/edit_form.php`, which calls the edit
  hooks in `user/edit.php`'s order.
- PG-only behaviour is asserted behind a dbfamily check or `markTestSkipped()`, and
  companion-dependent tests skip when its classes or tables are absent.
- Behat: scope profile-form locators to the category fieldset (`in the "Testing" "fieldset"`),
  because a bare "Language" also matches "Preferred language".
- `.mdlshield/config.yml` pins `versions: ["5.2"]`: read core-API findings knowing 4.5 is supported.

## When in doubt

Follow the patterns in existing files; if a new file matches no existing shape, re-examine the
approach.
