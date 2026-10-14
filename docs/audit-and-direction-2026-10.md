# Repeatable fields and reference dictionaries: audit and direction (2026-10-12)

Scope: `profilefield_repeatable` (this repo, v2.2.0, `2026080700`, STABLE, supported [405, 502])
and `local_profilefield_repeatable` (v0.4.0, `2026061600`, ALPHA, supported [405, 502]), with
`customfield_pattern` (0.1.0, BETA, [501, 503]) as the integration target. Method: four read-only
audits (one per plugin, one of the pattern API, one of core 4.5/5.1/5.2/5.3), then one independent
refuter per blocking finding. Nothing was executed against Moodle data; the refuters ran read-only
SQL to settle `LIKE` and collation semantics. Line numbers are at `main` 427febd (field) and 2cfcdaa
(dictionaries).

Neither repository has a tag or a release yet; neither stack (m405/m501/m502) holds a repeatable
field or a dictionary domain, and m503 has neither plugin (out of `supported`).

## 1. What was built

### profilefield_repeatable

- **Configuration** in core's `user_info_field`: `param1` column labels (newline list, which are also
  the JSON keys), `param2` indexed columns (max 3), `param3` `column|domain` mappings, `param4`
  subline columns (max 3), `defaultdata` forced to `{}`. `param5` is unused.
- **Value**: `{"@@ID#1": {"<label>": "<text>", ...}, ...}`; normalised by `helper::normalise_payload`
  (empty sets dropped, 200 sets, 1024 chars per value, unknown keys dropped).
- **Dual storage**: the whole payload in `user_info_data.data`, plus one row per set in
  `profilefield_repeatable_data` (`dataid, fieldid, userid, set_id, data`), written in one delegated
  transaction; reads prefer the plugin table.
- **PostgreSQL layer**: `data` converted to JSONB with a GIN (`jsonb_path_ops`) index; an adhoc task
  creates up to three partial expression indexes per field (`pfrd_f<id>_<hash>`), `CONCURRENTLY`.
- **Edit UI**: a hidden JSON input plus `amd/src/repeatable.js`, which clones a row of
  `<input type=text>` per column. Mapped columns are typed by hand: no select, no search.
- **Display**: a `myprofile` category (accordion on profile pages, plain list elsewhere);
  `show_field_content()` is false, so core never renders it inline. Labels are bulk-resolved once
  per domain.
- **Report Builder**: datasource `repeatable_sets` = user entity plus one entity per repeatable
  field, one row per set; per-column value columns read through `helper::sql_subitem_value()`
  (PG `->>`, MariaDB `JSON_EXTRACT`); mapped columns get a name column, a code column and an
  autocomplete filter.
- **Privacy, observers**: export/delete per field; observers purge plugin rows on user and field
  deletion and queue the index task.

### local_profilefield_repeatable

- Tables `_domain` (`shortname` unique, `name`) and `_item` (`domainid`, `code` char(255), `label`
  TEXT, unique `domainid, code`). No sort order, no active flag, no search column, no hierarchy, no
  language, no delete operation.
- `resolver` (static: `resolve`, `resolve_bulk`, `domain_exists`), silent on missing tables and cache
  failure; MUC `labels` (application, key `domain:code`, full purge on every write).
- `local\manager`: domain upsert, CSV parse, chunked item upsert (`MAX_BATCH_SIZE` 5000 for WS).
- Web services `get_reference_labels` (read, `viewreference`) and `upsert_reference_items` (write,
  `managereference`), both manager-archetype; a predefined service enabled by default.
- `manage.php`: create domain, import CSV (file or pasted), list domains. No item browser.
- `null_provider` (no user data).

### The contract between them

Soft dependency (no `$plugin->dependencies`): `class_exists('\local_profilefield_repeatable\resolver')`
at `define.class.php:561`, `display_renderer.php:516,541`, `repeatable_set.php:268,318`; the field
plugin also reads the dictionary table directly (`define.class.php:565,585,591`). The domain pattern
`/^[a-z0-9_]+$/` is copied in six places. Each repo's CI installs the other's `main`.

## 2. Verified findings (fix before any STABLE release)

| # | Plugin | Finding | Verdict |
|---|---|---|---|
| B1 | field | **Stored XSS in Report Builder.** `repeatable_set.php:128-160` value, code and name columns have no escaping callback; core's `column::format_value` and `flexible_table` do not escape (core entities end in `s($value)`). A user who can edit the field runs script in the session of whoever opens the report. | CONFIRMED |
| B2 | field | **Visibility bypass in Report Builder.** No `set_is_available($field->is_visible(system))` on any column or filter (core gates both, `user_profile_fields.php:149,211`). A private or teachers-only field is readable through an audience-shared report, filters included. | CONFIRMED |
| S1 | field | **Reconcile drops other fields' indexes.** `reconcile_indexes.php:122,210` match `pfrd_f<id>_%` with an unescaped `_`: saving field 1 drops the expression indexes of fields 10-19 and 100-199. Performance only, silent, not self-healing (re-created only when that field is saved again). | CONFIRMED (read-only SQL) |
| S-case | dictionaries | **Code case differs by DB family.** On MariaDB (`utf8mb4_unicode_ci`, the default) `ABC` and `abc` collide on the unique index; `upsert_items` misses its PHP lookup and the insert fails the whole batch; `resolve_bulk` returns results keyed by the stored spelling, so the caller's code finds nothing. PostgreSQL treats them as two codes. Decision: codes are case-insensitive, normalised on write (option A; XMLDB cannot make MariaDB case-sensitive). | CONFIRMED |
| S3 | field | **Dual-storage drift.** `profile_save_custom_fields()` writes `user_info_data` directly; its core caller is `auth_plugin_base::update_user_record()` (auth_ldap, auth_db attribute sync with `field_map_profile_field_<shortname>`). The plugin rows then go stale and reads prefer them (`field.class.php:80-87,277-284`, `display_renderer.php:321-329`); an empty synced value leaves the old sets showing. No drift detection exists. REST `core_user_*_users`, CSV upload, signup, OAuth2 and the profile form all go through `edit_save_data` and are NOT affected. Restored users are missing from the Report Builder source until their next edit. | PARTIAL: real, narrower than claimed |

Other findings, not independently refuted (read-from-source):

- Field plugin:
  - **S2.** Server-side validation errors on the hidden input never render (`errortoomanysets`,
    `errorvaluetoolong`, required).
  - **S4.** `show_field_content()` false hides the field from `core_user::get_user_details` (web
    services and the Moodle App). Confirm this is intended.
  - **S5.** Renaming a column label silently orphans its data. Labels are the JSON keys.
  - **S6.** The locked-field guard needs `moodle/user:update` on `$USER`, so CLI, cron, auth sync and
    signup cannot write a locked field.
  - **S7.** The partial and GIN indexes are probably never used by the plugin's own queries.
    Measure with `EXPLAIN` before keeping them.
  - **S8.** SQL Server is unguarded (Report Builder SQL fails).
  - **S9.** Non-PG upsert race.
  - **S10.** Strict `!==` between context `instanceid` and user id in the privacy provider.
  - **CSS.** Literal palette, no theme-token fallback chain, no dark mode on 5.2+.
  - **Comments.** 34 files carry the e-mail in `@copyright`; the `displayaccordion.js` GPL header
    is truncated.
  - **Accessibility.** Focus is lost on row removal, every remove button has the same
    `aria-label`, and the label in `edit_form.mustache` has no `for`.
- Dictionaries plugin:
  - No event on any write.
  - The web services lack `require_login()` and guest rejection.
  - The CSV import has no BOM strip and no `;` delimiter, splits quoted multi-line fields, and drops
    short rows, invalid UTF-8 and truncated codes silently.
  - Dead lang strings `wsgetlabelsdesc` and `wsupsertdesc`.
  - No generator.
  - The README is 38 lines.
- Both plugins:
  - `.gitattributes` exports `CLAUDE.md`, `.github`, `phpcs.xml` etc. into the release zip.
  - `ci.yml` has no branch filter, `workflow_dispatch` or concurrency; it runs a 5.00 leg outside
    `supported` and has no 5.3 leg.
  - The repo `CLAUDE.md` files are 557 and 458 lines, repeat the fleet rules and name a stale
    `/Volumes/N1TB/...` path.
  - The CHANGELOGs stop before the last bump.

5.3 compatibility: no API blocker in either plugin (profile field base classes, report builder
`set_main_table` alias, `core_external` namespace, cache and admin APIs all checked). The 5.3 work
is branching, CI, versions and README; 5.3 requires PostgreSQL 17 / MariaDB 11.4.

## 3. Versioning and STABLE

Fleet model: one branch per Moodle version (`MOODLE_405_STABLE`, `MOODLE_501_STABLE`,
`MOODLE_502_STABLE` = `main`, `MOODLE_503_STABLE`), each numbering in its version's namespace
(4.5 `20241007XX`, 5.1 `20251006XX`, 5.2 `20260420XX`, 5.3 `20261005XX`), releases
`v<major>.<minor>-r<N>` with the `git archive` zip.

The trap is that both plugins already carry numbers above the 4.5/5.1/5.2 namespaces: field
`2026080700`, dictionaries `2026061600`. Any site with them installed reads `20260420XX` as a
downgrade. Options:

1. **No production site has them**: set the version row on the dev stacks (fleet recipe) and start
   every branch at its namespace. This is the clean path.
2. **A production site has them**: 5.3 is unaffected (`20261005XX` is higher). 4.5/5.1/5.2 would
   either keep counting from the current number on those branches (breaking the namespace) or need
   the version row set on each production site before the upgrade.

CI per branch installs the companion's **matching** branch (`plugin-dependencies:
uaiblaine/moodle-<other>,MOODLE_50X_STABLE`), so both repos split in the same step.

STABLE gate for r1: B1, B2, S1, S-case, S3 fixed with mutation-checked tests; `mdl ci --matrix
--behat` green on all four branches and both DB families; privacy `component_is_compliant` in the
plugin's own tests; README per branch; MDL Shield `review full` on the hardening pull request of
each repo (it touches report access, web services and an upgrade step).

## 4. Direction

### 4.1 Dictionaries as a fleet service, not a profile-field accessory

The domain → code → label model has no profile-field dependency. Other consumers are already
visible: `enrol_apply` application questions (its own customfield area), course/cohort/group custom
fields, and `profilefield_brasilufmunicipio`, which today reads the IBGE API for a UF → municipality
pair. That last one is a hierarchical, externally-sourced dictionary.

Recommendation: rename to a name that ties it to neither "profile field" nor "repeatable"
(`local_dictionaries`, `local_codelists` or `local_refdata`; check the moodle.org directory before
choosing). `local_repeatable_dictionaries` would carry the same coupling one level up: a
single-value "dictionary" custom field is likely the most common consumer, and it is not
repeatable.

What a rename costs (a frankenstyle rename is a new plugin):

- **Moves with the code**: the two tables, two capabilities, the cache definition, two web
  services, the service shortname, the admin page and URL, the lang file names, the namespaces.
- **Field plugin call sites**: 10.
- **CI**: the dependency lines on both sides.
- **Without production data**: rename in the repo, no migration code.
- **With production data**: the new plugin's `install.php` copies rows from the old tables when
  they exist and clones the old capabilities' role assignments; the old plugin is uninstalled
  afterwards. Token clients of the old web services must be reconfigured.

Do the rename **before** the branch split and before r1, so no STABLE release ever ships the old
name.

### 4.2 Data model for search (needed by autocomplete)

| Need | Change |
|---|---|
| Indexed search by label | `searchkey` char(255): lowercase, accents removed (`core_text`); index `(domainid, searchkey)`. Prefix search uses the index; substring `LIKE` is acceptable up to tens of thousands of items per domain (measure). |
| Stable order | `sortorder` int. |
| Retire without breaking stored data | `active` flag: inactive items still resolve for display but are not offered. |
| One rule for codes | Case-insensitive, normalised on write (S-case). |
| Hierarchy (UF → municipality) | `parentid` (nullable) plus a search filtered by parent code. |
| Translated labels | Accept multilang syntax in `label`, `format_string` at display, `current_language()` in the cache key. |
| Who may search | Per-domain `visibility`: public to any logged-in non-guest user vs restricted to `viewreference`. Profile editing by ordinary users needs the former; today both web services are manager-only. |
| Pluggable sources | A `source` interface (local table is one implementation; an external API with a cached snapshot is another), so `brasilufmunicipio`-style data needs no copy-paste. Later, not r1. |
| Cache | A per-domain revision in the key instead of a full purge on every write. |
| API | `search(domain, query, limit, parentcode = null)` returning `{code, label}` plus `hasmore`; a read-only web service with `readonlysession`; an AMD transport module. |

### 4.3 Autocomplete in the editor

Per column, a **mode**:

- `free`: plain text, as today.
- `dictionary`: strict. Only active codes of the domain are accepted; the stored code survives even
  if later deactivated.
- `dictionary_open`: suggestions from the domain, but any typed code is accepted.

Client: the row template renders a `<select>` for dictionary columns and calls
`core/form-autocomplete` `enhance()` on each new row with the dictionaries' transport (`tags: true`
for `dictionary_open`). Core supports this outside moodleform and on rows added later (it enhances
each element once). Stored value = code; display = label. Server: validation re-checks strict codes
in `edit_validate_field`, so the JS is never the authority. The search web service authorises by the
domain's visibility, not by the caller's claim about which field it is editing (fleet rule: decide
on the recorded fact).

### 4.4 Masks and regex per column (customfield_pattern)

`customfield_pattern\local\pattern` is already pure: `new pattern(array $config)`, `normalise()`,
`validate()` (returns a localised message or null), `format_for_display()`. It does not touch the
customfield API, and `amd/src/mask.js` binds by event delegation to any input carrying
`data-customfield-pattern-mask`, rows added later included.

- **pattern**: declare `local\pattern` (or a thin `customfield_pattern\api` facade) a public,
  documented contract with a config schema (`preset`, `mask`, `regex`, `regexerror`,
  `storeformat`) that ignores unknown keys. Move the `maxlength` versus mask-length rule from the
  controller into `pattern`.
- **repeatable**: a column of mode `pattern` stores that config verbatim in the column schema;
  validation in `edit_validate_field`, normalisation on save, `format_for_display` in the profile
  display and in a Report Builder callback; the editor adds the data attribute and loads
  `customfield_pattern/mask`.
- **Soft dependency**: without the plugin, the option is hidden in the define form, stored config
  is kept, and values display as stored.
- **Versions**: pattern supports 5.1+. The 4.5 branch of repeatable ships without the feature,
  which the branch-per-version model makes natural, unless pattern is backported to 4.5 (its pure
  classes look portable; its controllers were not checked on 4.5).
- A column is `free`, `dictionary`, `dictionary_open` or `pattern`, one mode each.

### 4.5 Column schema v2 (prerequisite for 4.3 and 4.4, and the fix for S5)

Four newline lists cannot carry per-column mode, domain, pattern, required flag or stable identity.
Replace `param1`..`param4` with one JSON schema in `param1`:
`{"version": 2, "columns": [{"key": "lang", "label": "Language", "mode": "dictionary", "domain":
"iso639", "indexed": true, "subline": false, "required": false, "pattern": null}, ...]}`.

- **Keys**: generated once from the label (`[a-z0-9_]`) and never changed by a label edit.
- **Migration**: an upgrade step rewrites every field's config and re-keys every stored payload,
  in both `user_info_data` and the plugin table. It runs in chunks, as an adhoc task when large.
- **Define form**: a repeatable column editor instead of four textareas. It is the same widget
  pattern, which makes for a good dogfood.

Doing v2 before r1 avoids shipping a STABLE format that the next release must migrate on four
branches.

### 4.6 Repeatable fields beyond the user profile

Core has no repeatable or multi-value field type in any version up to 5.3, and no move to merge
profile fields into `core_customfield`. Customfield areas in core: course, cohort, group, grouping,
content bank, question bank; 5.1+ adds report and shared. `enrol_apply` defines its own area.

A `customfield_repeatable` type can store the payload JSON in `customfield_data.value` (text,
unbounded; override `export_value()` so `format_text` does not run on JSON). Core's Report Builder
helper gives such a type one column with `export_value()` formatting and no control over its SQL, so
"one row per set" reporting needs the same own-table projection plus a datasource, as the profile
field has.

Where the shared code lives:

- **(a) A new engine plugin** (`local_repeatable`): column schema, payload normalisation, editor JS,
  display renderer, Report Builder entity builder, pattern bridge. `profilefield_repeatable` and
  `customfield_repeatable` become thin adapters that hard-depend on it; the dictionaries plugin
  stays separate and soft.
- **(b) The engine inside the dictionaries plugin**: one plugin fewer, but every dictionary user
  installs the repeatable engine, and vice versa.
- **(c) Copy the engine into each type plugin**: rejected (two copies of the payload rules drift).

Recommendation: (a), after r1. The order is v2 schema, dictionaries search, autocomplete and
pattern in the profile field, then extract the engine when the second consumer is written. The
helper code and renderer are already mostly free of the profile API (audit §6), so extraction is
mechanical once the schema is stable.

### 4.7 Smaller design corrections worth taking with v2

- **Single source of truth.** `user_info_data` is canonical and the plugin table is a projection.
  It is rebuilt on read when the JSON differs from the projection (one decode per read;
  `timemodified` is not enough, because `profile_save_custom_fields()` does not bump it). This
  closes S3 whatever path wrote the value, and it also backfills restored users.
- **Indexes earn their keep or go.** `EXPLAIN` the Report Builder filters. If the expression
  indexes are not used, drop the adhoc task, the GIN index and the REINDEX: the largest
  complexity-for-nothing in the plugin.
  Measured 2026-10-14: they are not used, and stage A0 removes the layer (decision D3 in
  `docs/engine-extraction-design.md`).
- **One copy of the domain pattern**: a constant in the dictionaries API, read by the field plugin
  when present.
- **Limits**: a cap on the number of columns; uniqueness disabled for this type
  (`forceunique` compares the first 255 characters of a JSON blob).

## 5. Proposed sequence

1. **Hardening on current names** (both repos, one pull request each): B1, B2, S1, S-case, S3, S2,
   S6, S10; dictionaries events, web service login and guest rejection, CSV robustness. Tests
   mutation-checked; `security-diff-read`; MDL Shield `review full`.
2. **Fleet standards**: slim `CLAUDE.md` from the template, `.gitattributes`, `ci.yml` from the
   template, copyright lines, CSS tokens and dark mode, accessibility fixes, README and CHANGELOG.
   Then the comment audit (fleet toolkit `moodle-dev/ci/comment-audit`, which runs as a workflow
   and needs the owner's opt-in).
3. **Dictionaries rename** (if chosen) and the search data model (4.2).
4. **Column schema v2** with the migration (4.5), then autocomplete (4.3) and pattern columns
   (4.4).
5. **Branch split** (both repos together), version numbers per namespace, 5.3 leg,
   `MATURITY_STABLE`, releases `v4.5-r1`, `v5.1-r1`, `v5.2-r1`, `v5.3-r1`.
6. **Engine extraction** and `customfield_repeatable` (4.6), as r2.

Steps 1-2 can ship before the owner's decisions on 3-6. Splitting before step 4 would multiply
every later change by four cherry-picks.

## 6. Decisions (owner, 2026-10-12)

1. **Production**: neither plugin is installed on a production site. The rename needs no migration
   code, and every branch starts in its own version namespace; only the dev stacks need their
   version rows set.
2. **Dictionaries**: generic name, `local_dictionaries` (absent from the moodle.org directory on
   2026-10-12).
3. **Column schema v2 before r1.**
4. **Shared engine**: a new plugin, after r1.
5. Pattern on 4.5 and S4 were not asked: the recommendations above stand (skip pattern columns on
   the 4.5 branch; S4 stays as is until the owner says otherwise).
