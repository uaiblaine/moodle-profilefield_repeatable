# Repeatable-sets engine: extraction design (local_repeatable + customfield_repeatable)

Status: design, 2026-10-14. Direction approved by the owner: option (a) of
`docs/audit-and-direction-2026-10.md` §4.6 (a new engine plugin; the profile field and a new custom
field type become thin adapters; the dictionaries plugin stays separate and soft), decision 4 of §6
("a new plugin, after r1"). This document is the stage-by-stage plan an implementer follows.

Baseline: `profilefield_repeatable` `main` at 7b91ce4 (v5.2-r1, `2026042000`), tags `v4.5-r1`,
`v5.1-r1`, `v5.2-r1`, `v5.3-r1` published on GitHub. Branch checkouts `-405`, `-501`, `-503` differ
only in `version.php`, `ci.yml` and docs.

Evidence labels used below:

- **[V]**: read in the source during this design pass; the file:line is the evidence.
- **[R]**: reported with file:line by the core-reading subagent of this pass and not re-read here.
  The load-bearing ones (`custom_fields` columns, `export_value`, the config form being a
  `dynamic_form`, JS returned with a dynamic form) were spot-checked and are marked [V].
- **[I]**: inference or recommendation. A stage must confirm it before relying on it.

Path abbreviations: `core52:` = `~/dev/moodle-502/public/`, `core45:` = `~/dev/moodle-405/`,
`core53:` = `~/dev/moodle-503/public/`. Bare paths are in this repository.

---

## 1. Inventory

Totals [V]: PHP outside `tests/` is 6,154 lines (lang files included), `amd/src` 1,819, templates and
CSS 667, `tests/` 5,841, `mutations/` 689 (`gates.conf` has 445 lines and about 118 gates).

Classes used in the tables:

- **ENGINE**: moves to `local_repeatable` as it is, apart from renames.
- **ADAPTER**: stays in `profilefield_repeatable`.
- **SPLIT**: the seam is named in the row.

### 1.1 PHP

| File | Lines | Class | Seam / note |
|---|---:|---|---|
| `classes/helper.php` | 494 | SPLIT | **Engine** (about 440 lines): `MAX_SETS`, `MAX_VALUE_LENGTH` (:28,:31), `normalise_payload()` (:296), `column_value()` (:375), `encode_payload()` (:391), `sql_subitem_value()` (:235), `sql_column_value()` (:263), `is_postgres()` (:133), `quote_identifier()` (:487). `postgres_index_exists()` (:463) was removed in stage A0. The dictionaries probes `DOMAIN_PATTERN`/`domain_pattern()` (:39,:46), `companion_domains()` (:63), `companion_lookup_available()` (:103) and `fold_code()` (:118) go to a bridge class. `ensure_postgres_jsonb_support()` (:409) was removed in stage A0 with the JSONB column and the GIN index. **Adapter**: the v1 parsers `parse_subitems()` (:147) and `parse_subitem_domain_map()` (:177), which only `schema::from_v1()` reads. |
| `classes/local/schema.php` | 925 | SPLIT | **Engine** (about 790 lines): constants, `from_columns`, `decode`, `from_json`, `without_unsafe_columns`, `with_legacy_labels`/`legacy_labels` (generic "key aliases" read by the payload and SQL readers), `normalise_column`, `validate*`, `generate_key`, identifiers, accessors, `to_json`. **Adapter** (about 135 lines), into a new `profilefield_repeatable\local\field_schema`: `from_v1()` (:209), `v1_label_set()` (:251), `from_field()` (:281, reads `param1..4` of `{user_info_field}` and `get_config('profilefield_repeatable', 'legacykeys_<id>')` at :293), `legacy_config_name()` (:309), `LEGACY_CONFIG_PREFIX` (:82). The error strings (:528,:576,:664) change component to `local_repeatable`. |
| `classes/local/stored_values.php` | 84 | SPLIT | **Engine**: the per-payload loop of `keys_in_use()` (:43), taking an iterable of raw payloads. **Adapter**: the recordset over `{user_info_data}` (:48) and `locked_labels()` (:76). |
| `classes/local/migration.php` | 154 | ADAPTER | v1 → v2 re-keying of `{user_info_data}` (:84,:134). A custom field never has v1 data. |
| `classes/local/pattern_columns.php` | 171 | ENGINE | The one door to `\customfield_pattern\api`. It becomes `local_repeatable\local\bridge\pattern`. |
| `classes/local/projection.php` | 378 | SPLIT | **Engine** (about 300 lines): `sync()` (:53), `is_current()` (:102), `canonical_set()` (:131), `write_stale()` (:248), `reproject_record()` (:273), and `upsert_set_row()` (:316) with both its PG `ON CONFLICT` and its portable branch. All are parameterised by a projection store (table, owner column) and by a canonical reader. `reproject_field()` (:204) becomes generic over the adapter's canonical recordset. **Adapter**: `reproject_user()` (:147), which joins `{user_info_data}` to `{user_info_field}`. |
| `classes/observer.php` | 102 | ADAPTER | User and profile-field events (`db/events.php`). |
| `classes/output/display_renderer.php` | 562 | SPLIT | **Engine**: template-context builders, label prefetch and resolution, pattern formatting, the accordion and plain layouts (:89-:320, :392-:556). **Adapter**: `is_profile_page_context()` (:430, profile URLs), `get_projection_records()` (:366, reads `{profilefield_repeatable_data}` by user), and the accordion id prefix (:420). The adapter passes a layout flag, the sync-time rows and an id prefix. |
| `classes/privacy/provider.php` | 371 | SPLIT | **Engine**: `export_sets()` (:169), `unique_name()` (:223) and `export_value()` (:239), which turn one stored payload into the exported array. **Adapter**: metadata, contexts, users, delete paths and `get_records()` (:354). |
| `classes/reportbuilder/datasource/repeatable_sets.php` | 137 | ADAPTER | Its class name is persisted in `reportbuilder_report.source` (core52:`lib/db/install.xml:4595` [V]) and must not move. The entity names `repeatable_<shortname>` (:95) are persisted in the column and filter unique identifiers and must not change either. |
| `classes/reportbuilder/local/entities/repeatable_set.php` | 443 | SPLIT | **Engine**: an abstract `sets` entity holding `get_all_columns()` (:140), `get_all_filters()` (:246), the escaping callbacks, `resolve_label()`/`lookup_label()`/label memo (:335-:390) and `name_filter_options()` (:392, parameterised by table). **Adapter**: a thin subclass with the default tables (:66), the title (:75) and `is_field_visible()` (:111, `profile_get_user_field(... SUPPORT_USER ...)`). The engine applies `set_is_available()` (:84-:96) from the adapter's abstract `is_available()`. |
| `classes/task/reconcile_indexes.php` | 365 | REMOVED | Removed in stage A0 with the whole PostgreSQL index layer (see §8 D3). Nothing of it moves to the engine. |
| `classes/task/rekey_payloads.php` | 60 | ADAPTER | v1 only. Class name persisted in queued adhoc rows. |
| `classes/task/reproject_sets.php` | 65 | ADAPTER | Thin: loops the profile fields and calls the engine reprojector with the adapter's source. |
| `define.class.php` | 280 | SPLIT | **Adapter** keeps the `profile_define_base` hooks. **Engine** `form\column_editor` takes over three pieces. (1) The attribute payload of `define_after_data()` (:123-:138): locked keys, users, domains, companion, pattern and presets. (2) The collection of `domainexists`/`parentdomains`/`patternerrors` in `define_validate_specific()` (:166-:209). (3) The canonical JSON of `define_save_preprocess()` (:221). The adapter keeps the v1 conversion in `define_save()` (:243-:266) and `stored_field()` (:60). |
| `field.class.php` | 890 | SPLIT | **Engine** `form\widget`: the template context and columns JSON of `edit_field_add()` (:59-:91), `dictionary_widget_properties()` (:194), `pattern_widget_properties()` (:127), `widget_labels()` (:245) and the JS config (:329-:346). **Engine** `local\validator`: `validate_payload_limits()` (:485), `validate_required_columns()` (:531), `validate_pattern_values()` (:566), `validate_dictionary_codes()` (:621), `column_code_error()` (:676), `stored_codes()` (:734), `code_error()` (:758), `map_pattern_values()` (:143), `is_valid_json_object()` (:845). **Adapter** keeps the `profile_field_base` hooks: the hidden-in-group transport (:93-:100), the locked guard (:332,:366), the `{user_info_data}` write plus `projection::sync()` transaction (:359-:405), `is_empty`/`show_field_content`/`display_data`, element ids (:855-:889) and the `pattern_available()`/`companion_available()` test seams. |
| `lib.php` | 79 | ADAPTER | `profilefield_repeatable_myprofile_navigation()` (core calls it through `get_plugins_with_function('myprofile_navigation')`, core52:`user/classes/output/myprofile/manager.php:72` [V]). |
| `db/install.xml` | 29 | ADAPTER | `profilefield_repeatable_data` (see §3). |
| `db/install.php`, `db/lib.php` | 39, 42 | REMOVED | Removed in stage A0 (they only called the JSONB helper). `db/uninstall.php` was removed too. |
| `db/upgrade.php` | 36 | ADAPTER | Step 2026042001 (added in stage A0) drops the old indexes, the `indexed` flag and the JSONB column on r1 sites. |
| `db/events.php`, `db/tasks.php` | 46, 37 | ADAPTER | |
| `version.php` | 32 | ADAPTER | Gains `$plugin->dependencies`. |

### 1.2 AMD, templates, CSS

| File | Lines | Class | Note |
|---|---:|---|---|
| `amd/src/repeatable.js` | 674 | ENGINE | Becomes `local_repeatable/repeatable`. It loads the transport by name (`TRANSPORT`, :33), which becomes `local_repeatable/dictionary_transport`. |
| `amd/src/dictionary_transport.js` | 117 | ENGINE | `getStrings` component (:58-:62) becomes `local_repeatable`. It keeps the only import of `local_dictionaries/autocomplete_transport` (:35), so the editor loads without the companion. |
| `amd/src/markup.js` | 47 | ENGINE | |
| `amd/src/columneditor.js` | 920 | ENGINE | Its selector `textarea[data-region="profilefield-repeatable-schema"]` (:898) becomes `local-repeatable-schema`. String component changes (:904). `generateKey()` (:83) stays in step with `schema::generate_key()`. |
| `amd/src/displayaccordion.js` | 61 | ENGINE | |
| `amd/build/*` | 5 files | ENGINE | Rebuilt in the engine (`mdl grunt m502 local/repeatable`) and deleted from the field. |
| `templates/edit_form.mustache`, `display_accordion.mustache`, `display_plain.mustache` | 65, 85, 47 | ENGINE | Template names become `local_repeatable/...`. |
| `styles.css` | 470 | ENGINE | Class prefix `profilefield-repeatable-*` becomes `local-repeatable-*`. A search of `~/dev` outside this plugin's checkouts found no theme or plugin using the old classes [V]. |

### 1.3 Lang strings (126 per language, en and pt_br in lockstep [V])

- **Stay in the adapter (20)**:
  - `pluginname`
  - the 13 `privacy:metadata:*` strings
  - `profilewidgettitle`
  - `datasourcerepeatablesets`, `entityrepeatablesetnamed`
  - the task names: `taskrekeypayloads`, `taskreprojectsets` (`taskreconcileindexes` was removed in stage A0)
- **Move to `local_repeatable` (106)**:
  - the column editor (`addcolumn` … `summarytitle`, `column*`, `mode*`, `pattern*`, `role*`, `move*`, `domain*`, `parent*`, `removecolumn*`, `newcolumn`)
  - every `error*` string
  - the widget strings (`addnewset`, `removeset`, `repeatableset`, `setcolumn`, `actioncolumn`, `newcode`, `searchplaceholder`, `choosefirst`, `nomatchstrict`, `usecode`)
  - display (`synchronisedat`)
  - Report Builder column titles (`setnumber`, `columnsubitem`, `columnsubitemname`, `columnsubitemcode`, `entityrepeatableset`)
  - `columns`, `columns_help`. `addHelpButton('param1', 'columns', 'local_repeatable')` reads across components.

  The engine adds `pluginname` and `privacy:metadata`. Each consumer adds its own `pluginname`, privacy strings, task names and datasource strings.

Upgrade note for r1 sites [I]: a `tool_customlang` customisation of a moved string stays keyed to
`profilefield_repeatable` and stops applying. Say so in the r2 CHANGELOG and README.

### 1.4 Tests (PHPUnit test-method counts by `public function test_`; data providers add cases)

| File | Lines | Tests | Class | Seam |
|---|---:|---:|---|---|
| `tests/local/schema_test.php` | 538 | 17 | SPLIT | `from_v1`/`from_field`/legacy-config cases go to the adapter; the rest go to the engine |
| `tests/helper_test.php` | 150 | 7 | SPLIT | v1 parser cases go to the adapter; payload, SQL and fallback-pattern cases go to the engine |
| `tests/local/pattern_columns_test.php` | 104 | 4 | ENGINE | |
| `tests/bootstrap_compat_test.php` | 271 | 4 | ENGINE | It scans templates, JS and CSS, all of which move. The adapter keeps none. |
| `tests/dictionary_codes_test.php` | 496 | 14 | SPLIT | The logic cases become validator tests with a `can_read` callable in the engine. Two end-to-end cases through `edit_validate_field()` stay in the adapter. |
| `tests/pattern_field_test.php` | 434 | 11 | SPLIT | Validator, widget and renderer cases go to the engine. Save/load hook cases (PT8, PT9, PT10, PT11) stay. |
| `tests/field_class_test.php` | 1152 | 34 | SPLIT | Limits, required columns, widget context and escaping go to the engine. Hooks, the locked guard, the transaction, the error group and canonical reads (F1-F3) stay. |
| `tests/define_class_test.php` | 354 | 11 | SPLIT | Column-editor attribute and validation-assembly cases go to the engine. The lock source, v1 conversion and seams stay. |
| `tests/local/projection_test.php` | 208 | 6 | SPLIT | The writer and staleness cases go to the engine, against a fixture store (§6, stage C). `reproject_user` stays. |
| `tests/task/reconcile_indexes_test.php` | 204 | 4 | REMOVED | Removed in stage A0 with the task. |
| `tests/reportbuilder/datasource/repeatable_sets_test.php` | 561 | 13 | SPLIT | Escaping (E1-E4, PT6, R1) and availability application (E5, E6) become engine unit tests on column objects through `column::format_value()` (core52:`reportbuilder/classes/local/report/column.php:726`, core45 :648 [V]). The SQL, visibility source (E7), DB allowlist (S1, S2) and saved-report identifiers stay in the adapter. |
| `tests/local/migration_test.php`, `tests/observer_test.php`, `tests/privacy/provider_test.php`, `tests/task/reproject_sets_test.php` | 270, 326, 338, 114 | 3, 7, 9, 1 | ADAPTER | The privacy export-shape cases (PV1, PV2, PT14) are duplicated as engine exporter tests. |
| `tests/generator/lib.php` | 84 | — | SPLIT | `schema_json()` (:47) goes to an engine generator. `create_field()` (:73) stays. |
| `tests/fixtures/edit_form.php` | 68 | — | ADAPTER | |
| `tests/behat/*.feature` | 169 | 4 files | ADAPTER | They drive profile pages. The engine gets its own Behat only through its consumers (§6). |

### 1.5 Mutation gates (`mutations/gates.conf`, about 118 gates) [V]

Gates move with the code they break, because `mdl mutate` runs the suite of the repository it
mutates.

- **To the engine (about 85)**:
  - all `SC*`, `SF*`, `PT1`-`PT3`, `PT16`, `PT17`, `KV1`, `L1`, `B9` (schema)
  - `B1`-`B8`, `B11`-`B18`, `ES1`, `ES2`, `LR4`, `PT7`, `PT12`, `RQ1`, `RQ2` (`field.class.php` → validator/widget)
  - `H1`, `LR1`, `LR2`, `NL1` (helper)
  - `J1`-`J3`, `J6`-`J9` (projection)
  - `ES3`-`ES5`, `PT13`, `X1` (renderer)
  - `E1`-`E6`, `PT6`, `R1` (entity)
  - `PV1`, `PV2`, `PT14` (export)
  - `K3` (stored values)
  - the pattern bridge gate
  - (the `reconcile_indexes` ownership and sweep gates, I1-I7, and SC11 were removed in stage A0; U1-U8 and SC16 were added for the upgrade step 2026042001)
- **Stay in the adapter (about 33)**:
  - `F1`-`F3`, `PT8`-`PT11` (hooks)
  - `B10`, `D1`, `K1`, `K2`, `L2`, `L3`, `PT4`, `PT5`, `V1C`, `V1S` (define hooks)
  - `E7` (visibility source), `S1`, `S2` (datasource)
  - `J4`, `J5` (`reproject_user`)
  - `LR3` (legacy config read)
  - migration, observer, events, `reproject_sets`, privacy `V1`/`V2` and the task wiring gates

A moved gate's `#   ->` lines must name the engine test that now holds it. Run the sweep in the engine
repository on both DB families before the commit that adds the gates (fleet rule).

---

## 2. Engine API (`local_repeatable`)

Principle [I]: **the adapter is the Moodle-hook class; the engine is a library of services that
receive decisions, never make Moodle-API-specific ones.** The engine never calls `profile_*` or
`core_customfield` APIs, never reads `$USER` for an authorisation decision unless the adapter passes
it, and never knows a table name the adapter did not give it. That keeps the two adapters' security
decisions where their APIs live (fleet rule: decide on the recorded fact).

### 2.1 Namespaces and classes

| Class | Origin | Responsibility |
|---|---|---|
| `local_repeatable\local\schema` | `classes/local/schema.php` minus the v1 reader | Schema v2: `from_columns`, `decode`, `from_json` (drops unsafe columns), `with_legacy_labels` (generic key aliases), `validate(...)` (signature unchanged, `schema.php:420`), `generate_key`, identifiers, accessors, `to_json`/`to_document`. |
| `local_repeatable\local\payload` | `helper.php` | `MAX_SETS`, `MAX_VALUE_LENGTH`, `normalise()`, `encode()`, `column_value()`. |
| `local_repeatable\local\sql` | `helper.php` | `is_postgres()`, `subitem_value()`, `column_value()` (COALESCE over a legacy alias), `quote_identifier()`, `quote_literal()`. (`pg_index_exists()` was removed in stage A0.) |
| `local_repeatable\local\bridge\dictionaries` | `helper.php` plus the dictionary calls scattered in `field.class.php`, `define.class.php`, renderer and entity | Soft dependency on `local_dictionaries`. Every method guards with `class_exists()`/`method_exists()`: `available()`, `lookup_available()`, `domain_pattern()` (`DOMAIN_PATTERN` fallback pinned equal by a test), `domains()`, `domain_exists()`, `can_read(string $domain, ?int $userid)` (companion's `api::can_read`, `local_dictionaries/classes/api.php:59` [V]), `lookup()`, `resolve_bulk()`, `fold_code()`. |
| `local_repeatable\local\bridge\pattern` | `pattern_columns.php` | Soft dependency on `customfield_pattern` (`classes/api.php:57` `CONFIG_VERSION` [V]); unchanged contract. Always absent on 4.5 (the plugin supports [501, 503]). |
| `local_repeatable\local\stored_values` | `stored_values.php` | `keys_in_use(iterable $payloads, schema $schema): array`. |
| `local_repeatable\local\validator` | `field.class.php` | An instance object built per call by the field (`get_validator()`). Built from: the schema; the **stored** payload (owner's recorded value, never the submission); a `callable $canread` (fn(string $domain): bool, the editing user); and two availability flags (dictionary lookup, pattern), which are the adapters' test seams. Methods: `raw_error(string $json): ?string` (JSON object + caps on the raw payload), `error(array $payload, bool $fieldrequired): ?string` (required set, required columns, dictionary codes, pattern values, in today's order), `for_storage(array $payload): array` (pattern normalise), `for_editing(array $payload): array` (pattern format). |
| `local_repeatable\form\widget` | `field.class.php`, `templates/edit_form.mustache` | An instance object built per call by the field (`get_widget()`). `render(schema, string $fieldname, array $ids, callable $canread, ?array $labelsfor): string` for the table HTML, `js_config(array $ids, bool $readonly): array`, `labels(string $payload, array $storedcodes, callable $canread): array`. The adapter supplies wrapper, hidden and error ids and adds the hidden input and the group itself. |
| `local_repeatable\form\column_editor` | `define.class.php` | `attributes(usage, patternavailable)` (the `data-*` set, so `js_call_amd()` stays under 1024 characters), `errors(array $document, array $lockedlabels, array $storedpatterns): string[]` (assembles `domainexists`/`parentdomains`/`patternerrors` from the bridges and calls `schema::validate()`), `canonical(string $json): ?string`, `init_js()`. |
| `local_repeatable\output\sets_renderer` | `display_renderer.php` | `render(schema, string $payload, string $layout, string $idprefix, string $fieldname, callable $syncrows): string` with `$layout` = `accordion` or `plain`. `$syncrows` is a callable so the projection query runs only when a set is shown. Bulk label prefetch once per domain. |
| `local_repeatable\local\projection\store` | new | Value object: projection table and owner column (`userid` / `instanceid`). The PG index prefix was removed in stage A0. |
| `local_repeatable\local\projection\source` | new interface | What an adapter must provide to the projector. See §2.2. |
| `local_repeatable\local\projection\projector` | `projection.php` | `sync(source, dataid, fieldid, ownerid, payload)`, `is_current()`, `canonical_set()`, `reproject_record(source, dataid, schema)`, `reproject_field(source, fieldid, ?schema)` (the optional schema avoids a second read; read counts unchanged). |
| `local_repeatable\local\pg\jsonb`, `local_repeatable\local\pg\index_reconciler` | removed | Removed in stage A0 with the PostgreSQL index layer; the projection is a text column read as `(data)::jsonb ->> key`. |
| `local_repeatable\reportbuilder\local\entities\sets` | `repeatable_set.php` | Abstract entity. The constructor takes the projection table and field id; the abstract `get_schema()` and `is_available()` supply the schema and availability. It builds `setnumber`, value / `_name` / `_code` columns and filters with `s()` on every value and option label. Identifiers come from `schema::report_identifiers()`, so they stay as they are today. |
| `local_repeatable\privacy\set_exporter` | `privacy/provider.php` | `export(schema, string $rawpayload): array` (labels, collisions, leftovers, pattern format, plain text). |
| `local_repeatable\privacy\provider` | new | `null_provider`: the engine stores nothing. |

The engine has **no tables, capabilities, settings, web services, events, tasks or observers**.

### 2.2 What an adapter provides

The `projection\source` interface [I] has six methods (`field_ids()`, `existing_field_ids()` and `indexed_columns_enabled()` were dropped; the reprojection task is adapter code and reads its own field records):

| Method | Answers | Profile adapter | Customfield adapter |
|---|---|---|---|
| `component(): string` | Which plugin owns the data | `profilefield_repeatable` | `customfield_repeatable` |
| `store(): store` | Where the projection lives | `profilefield_repeatable_data`, `userid` | `customfield_repeatable_set`, `instanceid` |
| `schema(int $fieldid): ?schema` | Schema source | `field_schema::from_field()` (v1-tolerant, legacy config) | `field_controller` `configdata['schema']` |
| `canonical(int $dataid): ?stdClass` | Payload read `{id, fieldid, ownerid, data}` | `{user_info_data}` | `{customfield_data}`, aliasing `value AS data` |
| `canonical_recordset(int $fieldid)` | All payloads of a field, id order | `{user_info_data}` | `{customfield_data}` |
| `projection_rows(int $fieldid, int $ownerid): array` | Sync timestamps for display | by `userid` | by `instanceid` |

The adapter's own classes provide everything else, as decisions passed into engine calls:

- **Payload write**: the adapter writes its canonical row and calls `projector::sync()` in **one
  delegated transaction**. Profile: `{user_info_data}` (today's `field.class.php:384-401`).
  Customfield: `parent::instance_form_save()`, then `sync` (§4.3).
- **Visibility and capability decisions**:
  - Edit read-only: the profile adapter decides it as `is_locked() && !has_capability('moodle/user:update', system)`. The customfield handler renders only editable fields (core52:`customfield/classes/handler.php:779` [V]), so read-only never arises there.
  - Dictionary search and label rights: both adapters pass `fn($d) => bridge\dictionaries::can_read($d)` for the current user. It stays a seam, because the restricted-dictionary rules (B11-B18) are decided on it.
  - Report Builder availability:
    - Profile: `is_visible(system)` on a `SUPPORT_USER` instance, as core does (core52:`reportbuilder/classes/local/helpers/user_profile_fields.php:151,214`; core45 :155,:227 [V]).
    - Customfield: `handler->can_view($field, 0)`, as core's helper does (core52:`reportbuilder/classes/local/helpers/custom_fields.php:162-164` [V]).
- **Form integration**: element name, wrapper, hidden and error ids, the field's display name and
  where the HTML goes (`addElement('html')` plus a group holding the hidden input named after the
  element, so QuickForm prints its error, `field.class.php:93-100`).

### 2.3 AMD renames and how the field's JS init calls change

| r1 call | r2 call |
|---|---|
| `js_call_amd('profilefield_repeatable/repeatable', 'init', [$config])` (`field.class.php:342`) | `js_call_amd('local_repeatable/repeatable', 'init', [$config])`, `$config` from `widget::js_config()` (same keys: `wrapperid`, `hiddenid`, `readonly`, `strings`); `strings` read from `local_repeatable` |
| `js_call_amd('profilefield_repeatable/columneditor', 'init')` (`define.class.php:138`) | `column_editor::init_js()` → `local_repeatable/columneditor`; the textarea carries `data-region="local-repeatable-schema"` |
| `js_call_amd('profilefield_repeatable/displayaccordion', 'init')` (`display_renderer.php:179`) | issued by `sets_renderer` as `local_repeatable/displayaccordion` |
| `TRANSPORT = 'profilefield_repeatable/dictionary_transport'` (`repeatable.js:33`) | `local_repeatable/dictionary_transport` |

No shim modules: nothing outside the field's own PHP names the old modules [V: search of `~/dev`],
and nothing persists an AMD module name. Module basenames are kept, so the move is a rename of the
component only and `git log --follow` works.

---

## 3. Projection storage: one engine table or one table per adapter

The two options:

- **(E) Engine table**: `local_repeatable_set (component, area, fieldid, instanceid, setnumber, dataid, data, ...)`.
- **(P) Per adapter**: the field keeps `profilefield_repeatable_data` as a text projection
  (the JSONB, GIN and expression indexes were removed in stage A0); the custom field gets `customfield_repeatable_set`.

| Criterion | (E) one engine table | (P) table per adapter |
|---|---|---|
| Report Builder SQL sharing | Shared entity joins on `component`/`fieldid`. | Shared entity takes the table name as a parameter; the SQL is identical. Even. |
| PG index ownership | Every partial index needs `WHERE component = ... AND fieldid = ...`. The name must encode the adapter. The orphan sweep must ask every adapter which field ids exist, so the engine must discover adapters, which is a registry or callback the engine does not need otherwise. | Moot since stage A0 removed the PG indexes: nothing is left to own. |
| Privacy | Profile rows are user data (`userid`); custom-field rows usually are not. One table forces a nullable `userid` and an engine provider that must know which rows are personal data. The adapters' providers would delete from the engine's table. | Each adapter declares and deletes its own rows: the profile provider is unchanged, and the custom field follows core's `customfield_provider` shape (§4.6). |
| Upgrade cost for r1 sites | **High, and ordered against us.** Core upgrades plugin types in `get_plugin_types()` order with `local` always last (core52:`lib/classes/component.php:842-843`; core45 :687; core53 :845; loop core52:`lib/upgradelib.php:1952-1955`, core45 :1937 [V]). So `profilefield_repeatable`'s upgrade step runs **before** `local_repeatable` is installed and cannot copy rows into the engine's table. The copy would have to live in `local_repeatable/db/install.php`, which would then know the field's table. Dropping the old table needs a later release.  | **None.** The table stays. r2 needs no upgrade step of its own (§5). |
| Uninstall | Uninstalling a consumer must delete its rows from another plugin's table. | `db/uninstall.php` drops its own table, as today. |
| Engine scope | The engine gains install, upgrade, uninstall, privacy and tables. | The engine stays a library with no DB state. Its install order relative to the consumers is irrelevant. |
| Duplication | One `install.xml`. | Two 8-column table definitions, . |

**Recommendation: (P), one table per adapter.** The decisive facts are the upgrade order (`local`
last), r1 being published, and privacy staying with the API that knows whose data it is. The
duplication is two short XMLDB tables.

Shape of `customfield_repeatable_set` [I]:

- Fields: `id`, `dataid` (FK `customfield_data.id`), `fieldid`, `instanceid`, `set_id`, `data`
  (text), `timecreated`, `timemodified`.
- Indexes: unique `(dataid, set_id)`, and `(fieldid, instanceid)`.
- No `component`/`area` columns. In 5.1+ a shared-category field can hold data for several areas
  under one `fieldid`. `customfield_data` tells the rows apart with `component`, `area` and
  `itemid` (core52:`lib/db/install.xml` unique index `instanceid, fieldid, component, area, itemid`
  [V]; 4.5 has only `instanceid, fieldid` [R core45:`lib/db/install.xml:4347`]). The shared-area
  follow-up joins `customfield_data` on `dataid` (the primary key) and needs no schema change.
- The name is 26 characters, within XMLDB's limit [I].

---

## 4. `customfield_repeatable`

### 4.1 Storage in `customfield_data`

- `datafield()` returns `'value'`, the text column with no explicit limit (core52:
  `lib/db/install.xml` `value TYPE="text" NOTNULL="true"` [R :4347]). The core base class lists the
  allowed datafields in its docblock (core52:`customfield/classes/data_controller.php:158-166` [R]).
- `valueformat` is `FORMAT_PLAIN` and `valuetrust` is 0 on every write.
- **`export_value()` must be overridden.** The default runs
  `format_text($value, valueformat, ['trusted' => valuetrust])` for `datafield() === 'value'`
  (core52:`customfield/classes/data_controller.php:359-377` [V]). The override:
  - normalises the payload, returning null when it is empty;
  - returns the engine's plain-layout HTML, where every value is escaped by Mustache, dictionary codes
    are resolved and pattern values are formatted.

  Core uses `export_value()`:
  - in Report Builder's generic column callback (core52:`reportbuilder/classes/local/helpers/custom_fields.php:150-161` [V]);
  - in `display()` → `output\field_data` (core52:`customfield/classes/output/field_data.php:57-59` [R]), hence course listings (core52:`course/renderer.php:566-570` [R]);
  - in core's privacy export (core52:`customfield/classes/privacy/provider.php` about :470 [R]).
- `get_default_value()` returns null. A non-null default makes core's filter wrap the column in
  `COALESCE(col, :default)` (core52:`reportbuilder/classes/local/helpers/custom_fields.php:228-236`
  [V]), which would compare JSON with a default.
- `is_empty()` is overridden to "no set after `payload::normalise()`". The default only tests `'' === ''`
  (core52:`customfield/classes/data_controller.php:240` [R]), so `{}` would count as filled.

### 4.2 Field configuration (`field_controller`)

- **The config form is a modal `dynamic_form`, as the profile define form is**:
  - `core_customfield\field_config_form extends \core_form\dynamic_form` (core52:`customfield/classes/field_config_form.php:36`), and `definition()` calls `$field->config_form_definition($mform)` (:89) [V].
  - It is opened through `core_form/modalform` (core52:`customfield/amd/src/form.js:32,172,210` [R]).
  - The dynamic-form web service returns `$PAGE->requires->get_end_code()` with the HTML (core52:`lib/form/classes/external/dynamic_form.php:92-93` [V]), so a `js_call_amd()` issued in `config_form_definition()` runs in the modal.

  The engine's data-attribute transport (`column_editor::attributes()`) therefore applies unchanged.
  It exists because `js_call_amd()` warns past 1024 characters (CLAUDE.md:145-146).
- `config_form_definition(\MoodleQuickForm $mform)` (core52:`customfield/classes/field_controller.php:253` [R]):
  - Adds a textarea `configdata[schema]` with `data-region="local-repeatable-schema"`.
  - Sets the attributes from `column_editor::attributes($stored, $usage)`. The locked keys come from `stored_values::keys_in_use()` over this field's `customfield_data` rows, never from the submission.
  - Issues `column_editor::init_js()`.
  - The handler then adds its own area settings, for example course visibility and locked (core52:`customfield/classes/field_config_form.php:92` [V]).
- `config_form_validation(array $data, $files)` (:147 [R]): calls `column_editor::errors()` with the
  locked labels and stored pattern objects of the **stored** field (`$data['id']`), and keys the
  errors on `configdata[schema]`.
- `prepare_for_config_form(\stdClass $formdata)` (:245 [R]): pretty-prints the stored schema, as
  `define_after_data()` does today (`define.class.php:114-121`).
- `save()` (:193 [R]):
  - Rewrites `configdata['schema']` to `schema::to_json()` before `parent::save()`. `configdata` is stored JSON-encoded (core52:`customfield/classes/api.php:166-172` [R]).
  - The schema is stored as a **string** inside `configdata`. That keeps `from_json()` and the strict `===` comparison of stored pattern objects (`schema.php:591`) unchanged.
- `delete()`: core deletes `customfield_data` directly in `field_controller::delete()` (core52:
  `customfield/classes/field_controller.php:182-186` [R]) and does not call `data_controller::delete()`.
  The override therefore deletes `customfield_repeatable_set` rows by `fieldid`. (The index sweep was
  removed in stage A0.) There is no data save or delete event in `core_customfield` [R].
- Field types are filtered only site-wide, through the enabled-plugins list
  (`handler::get_available_field_types()` → `api::get_available_field_types()`, core52:
  `customfield/classes/handler.php:813-815`, `api.php:148-158` [R]). No core handler overrides it,
  so the type is offered in every area once enabled.

### 4.3 Instance data (`data_controller`)

- `instance_form_definition(\MoodleQuickForm $mform)` (abstract, core52 :350 [R]):
  - Adds `widget::render()` HTML, then a group named `get_form_element_name()` (`customfield_<shortname>`, :128 [R]) holding the hidden input.
  - Error id: `fgroup_id_error_customfield_<shortname>`.
  - Wrapper id: `customfield-repeatable-<fieldid>-<instanceid>`, unique when several fields share a form.
  - Issues `js_call_amd('local_repeatable/repeatable', 'init', [widget::js_config(...)])`.
- `instance_form_before_set_data(\stdClass $instance)`: puts
  `payload::encode($validator->for_editing(normalise(stored)))` under the element name, and sets the
  labels data attribute from `widget::labels()`. The latter runs in
  `instance_form_definition_after_data()` (core52 :295 [R]), reading the element value so that a
  failed validation keeps them, as `edit_after_data()` does today (`field.class.php:317-327`).
- `instance_form_validation(array $data, array $files)` (core52 :278 [R]), called only for editable
  fields (core52:`customfield/classes/handler.php:750-756` [V]):
  - Runs `validator::raw_error()`, then `validator::error()` with `fieldrequired` from the field's `required` config.
  - The stored payload is `$this->get_value()` of the controller the handler built for the instance being edited: the recorded fact, never the submission.
- `instance_form_save(\stdClass $datanew)` (core52 :200 [R]):
  1. Opens a delegated transaction.
  2. Replaces the submitted element value with `encode(for_storage(normalise(...)))`.
  3. Calls `parent::instance_form_save($datanew)`. Core writes `value`, stamps component, area and itemid on 5.x (:213-217) but not on 4.5 [R], so this step keeps both versions correct.
  4. Sets `valueformat`/`valuetrust` and calls `projector::sync()` with `$this->get('id')`.
  5. Commits.
- `delete()`: core calls it from `handler::delete_instance()` (core52:`customfield/classes/handler.php:928-933` [V]), which runs on course deletion (core52:`lib/moodlelib.php:4733`) and cohort deletion (core52:`cohort/lib.php:141`) [V]. The override deletes the data id's projection rows, then calls the parent.
- **Backup/restore**:
  - Course backup serialises `value`, `valueformat`, `valuetrust` (core52:`backup/moodle2/backup_stepslib.php:556-558` [R]); the JSON travels in `value`, so nothing extra is backed up.
  - On restore, `course_handler::restore_instance_data_from_backup()` writes `value` directly and saves (core52:`course/classes/customfield/course_handler.php:183-205` [V]), bypassing the type. Core then calls `restore_define_structure($step, $newid, $oldid)` on **every** data controller of the instance (core52:`customfield/classes/handler.php:629-638`; restore step core52:`backup/moodle2/restore_stepslib.php:2202-2209`; core45 handler :523-530, step :2119 [V]).
  - The override acts only when `$this->get('id') === $newid` and reprojects that record (`projector::reproject_record`). The daily reprojection task is the safety net for any other direct writer.

### 4.4 Areas

All areas are verified [R], and none blocks a non-core type.

| Area (component/area) | Versions | Instance | Notes |
|---|---|---|---|
| `core_course/course` | 4.5, 5.1, 5.2, 5.3 | course id | **First scope.** Visibility config `NOTVISIBLE`/`VISIBLETOTEACHERS`/`VISIBLETOALL` decides `can_view()` (core52:`course/classes/customfield/course_handler.php:84-93` [V]). Restore path covered (§4.3). |
| `core_cohort/cohort` | all | cohort id | Follow-up: core datasource uses the helper (core52:`cohort/classes/reportbuilder/local/entities/cohort.php:71` [R]). |
| `core_group/group`, `core_group/grouping` | all | group / grouping id | Follow-up. They have their own restore paths (`group_handler.php:141`, `grouping_handler.php:142` [R]); check that `restore_define_structure` runs for them before relying on it. |
| `core_contentbank/content` | all | content id | Follow-up. |
| `qbank_customfields/question` | all | question id | Follow-up. The handler renders its own table (`question_handler.php:222` [R]). Question versioning copies were not checked. |
| `core_reportbuilder/report` | 5.1+ | report id | Follow-up. The report edit form is a modal (dynamic-form JS works, §4.2). |
| `core_customfield/shared` | 5.1+ | per area | Follow-up. The projection join must add `component`/`area` through `customfield_data` (§3). Core's own helper joins on `fieldid` and `instanceid` only (core52:`reportbuilder/classes/local/helpers/custom_fields.php:94-96` [V]). |

The type is usable in every area from D1 on. "Scope" below means Report Builder one-row-per-set
support and Behat coverage.

### 4.5 Report Builder

What core gives a custom field is one column `customfield_<shortname>`:

- Its SQL reads `alias.<datafield>` (core52:`reportbuilder/classes/local/helpers/custom_fields.php:113-117` [V]).
- Its type comes from a hard-coded map with no per-type hook (:178- [R]), so `value` gives `TYPE_LONGTEXT`. That column is not sortable on 4.5 [R].
- It is formatted by `export_value()` (:150-161 [V]) and available per `can_view($field, 0)` (:162-164 [V]).
- Its filter is `text` over the raw JSON (:213-245 [V], filter class map [R]).

Datasources using it: courses (core52:`reportbuilder/classes/local/entities/course.php:86`), cohorts,
groups, groupings, and `reports_list` in 5.2/5.3 [R].

So in core datasources a repeatable custom field shows the `export_value()` summary and a text
filter over JSON. That is acceptable but not "one row per set".

**One row per set needs an own datasource per area.**

- A single generic datasource cannot choose a meaningful main table, because course, cohort and group
  rows differ.
- Recommended first scope: **course area only**, `customfield_repeatable\reportbuilder\datasource\course_sets`:
  - Main table `course` with core's `course` entity; mirror the core courses datasource's base condition (site course excluded; verify in D2).
  - Plus one engine `sets` entity per course-area repeatable field, named `repeatable_<shortname>` (shortnames are unique within a handler).
  - Join: `LEFT JOIN {customfield_repeatable_set} s ON s.instanceid = c.id AND s.fieldid = <id>`.
  - Availability: `handler->can_view($field, 0)`, exactly as core.
  - Same DB-family allowlist as the profile datasource (postgres, mysql; `repeatable_sets.php:56-64`).
  - One repeatable field per report (cross-join warning carried over, CLAUDE.md:229).
- Follow-ups: one datasource each for cohort, group/grouping and content bank, reusing the entity. Shared fields wait for the join in §3.

### 4.6 Privacy

Core's custom-field provider:

- exports `export_value()` (core52:`customfield/classes/privacy/provider.php` about :470 [R]);
- then calls the type's `customfield_provider` callbacks (`export_customfield_data`, `before_delete_data`, `before_delete_fields`; interface core52:`customfield/classes/privacy/customfield_provider.php` [R]).

Core's text and textarea types are `null_provider` plus `customfield_provider` [R].

`customfield_repeatable` follows that shape:

- `export_customfield_data()` writes the sets through `set_exporter` as plain text, instead of the summary HTML.
- `before_delete_data($dataidstest, $params, $contextids)` deletes projection rows `WHERE dataid <test>`.
- `before_delete_fields(...)` deletes them by `fieldid`.

The projection holds no user id. The plugin's own `component_is_compliant()` test decides whether
`null_provider` is accepted with a table present. If it is not, declare the table through a metadata
provider [I: confirm in D1].

### 4.7 Lang and versions

- Own strings: `pluginname`, `privacy:metadata`, task names, `datasourcecoursesets`, the entity title.
- Everything else comes from `local_repeatable`.
- `version.php` declares `supported` per branch and `dependencies = ['local_repeatable' => <branch version>]`.
  Core types carry no `supported` line (core52:`customfield/field/textarea/version.php` [R]), but the fleet rule requires one.

---

## 5. Versions, branches and the r1 → r2 upgrade

### 5.1 Numbering

- **`local_repeatable`** (new). Development on `main` = 5.2, starting at `2026042000`. At release it
  is split into four branches, each at counter 00 of its namespace:

  | Branch | Version |
  |---|---|
  | `MOODLE_405_STABLE` | `2024100700` |
  | `MOODLE_501_STABLE` | `2025100600` |
  | `MOODLE_502_STABLE` = `main` | `20260420XX` (counter as reached in development) |
  | `MOODLE_503_STABLE` | `2026100500` |

  Releases `v4.5-r1` … `v5.3-r1`. MATURITY_STABLE only after the stage E gates.
- **`profilefield_repeatable` r2**:
  - `main` bumps per merged stage (`2026042001`, `…02`, …).
  - The other branches get one bump each at release: `2024100701`, `2025100601`, `2026100501`.
  - Every branch's `version.php` gains `$plugin->dependencies = ['local_repeatable' => <that branch's engine version>]`.
  - Releases `v4.5-r2` … `v5.3-r2`.
- **`customfield_repeatable`** (new): same scheme as the engine, releases `v<x.y>-r1`.
- **No upgrade step is needed in r2 with option (P).** If a later change needs one, it is numbered
  in its own branch's namespace and never above that branch's `version.php` (fleet rule).

### 5.2 What an r1 site sees when it upgrades

1. **Install order.** The admin places `local/repeatable` and the r2 field code. Core checks
   dependencies against the version **on disk** (core52:`lib/classes/plugin_manager.php:750`, core45
   :737 [V]), so the upgrade starts. It then upgrades `profilefield_repeatable` **before** installing
   `local_repeatable`, because `local` is always last (§3 citations). Therefore neither the field's
   `upgrade.php` nor `customfield_repeatable`'s `install.php` may depend on engine DB state. With (P)
   there is none. Engine **classes** autoload because they are on disk (CLAUDE.md:111-113).
   Without the engine on disk the upgrade stops on the missing dependency.
2. **Data.** `{user_info_data}`, `{profilefield_repeatable_data}` and its rows are untouched. The JSONB column, the GIN index
   and the `pfrd_f<id>_<hash>` expression indexes are gone: r2 sites already ran step 2026042001 of the field, which removed them.
3. **Config.** `legacykeys_<fieldid>` (plugin `profilefield_repeatable`) is unchanged and still read by
   the adapter's `field_schema::from_field()`. A queued `rekey_payloads` adhoc
   task keeps its class name (`reconcile_indexes` was removed in stage A0). The scheduled `reproject_sets` keeps its name.
4. **AMD.** The old modules disappear with the version bump's cache purge, and nothing stored names
   them. CSS class names change (§1.2), so the upgrade notes mention theme overrides.
5. **Saved Report Builder reports keep working.**
   - The datasource class `profilefield_repeatable\reportbuilder\datasource\repeatable_sets` stays (`reportbuilder_report.source`).
   - Columns and filters are persisted by `uniqueidentifier` = `<entity name>:<column name>` (core52:`reportbuilder/classes/local/report/column.php:207-208`, `filter.php:148-149` [V]).
   - The entity name `repeatable_<shortname>` and the column names (`<key>`, `<key>_name`, `<key>_code`, `setnumber`) do not change.
   - Column titles come from lang strings, which are not persisted.
   - **Gate:** a test lists the datasource's column and filter unique identifiers for a fixture schema and compares them with a literal list captured from r1. It must exist before stage B3 merges.
6. **Uninstalling the engine while the field is installed** is refused by core
   (core52:`lib/classes/plugin_manager.php:1296-1300` [V]).
7. **Rolling back to r1** is a version downgrade and is refused. The way back is a backup restore.
   Say so in the release notes.

### 5.3 CI, mounts and companions

- Field `ci.yml`: add `uaiblaine/moodle-local_repeatable,<branch>` to `plugin-dependencies`
  (`main` while developing, `MOODLE_502_STABLE` at release; `ci.yml:40-43` today).
- `local_dictionaries` `ci.yml:33-43` installs `profilefield_repeatable,MOODLE_502_STABLE` as its
  consumer [V]. From r2 on it must also install `local_repeatable`, or its CI goes red. Do this in the
  release stage, together with the docblock at `local_dictionaries/classes/resolver.php:27`, which
  names the consumer.
- `moodle-dev/plugins.conf`: add per-branch lines for `local_repeatable` and `customfield_repeatable`
  in the explicit-list form used for the field and dictionaries (`plugins.conf:34-43` [V]). Run
  `mdl mounts`, `mdl up`, `mdl upgrade`, `mdl phpunit-init`, `mdl behat-init` on the affected stacks.
- 4.5 branches:
  - The pattern bridge is always absent (customfield_pattern supports [501, 503], `version.php:30` [V]).
  - The engine inherits the field's "no Bootstrap 5 utility at all" rule and its scanning test.
  - customfield_repeatable on 4.5 has no `report`/`shared` areas [R], and `customfield_data` lacks `component`/`area`/`itemid`. The design never reads them.
- MDL Shield: each new repository needs `.mdlshield/config.yml` and `context.md` on its default branch
  before its first review. Each repository is pinned to one Moodle version, 5.2.

---

## 6. Stages

"Effective lines" are estimates [I]: measure each PR with `mdl mdlshield size <repo>` before asking
for a review. The soft limit is 3,000. Every stage: lang en and pt_br in lockstep, `version.php`
bump plus CHANGELOG in the same commit as AMD builds, `mdl ci <repo> --matrix --behat` from the main
session, mutation sweep on both DB families.

**Pure moves.** A pure move is code moved with only namespace, component and class-prefix renames. It
is checked by a **move-identity check**: a fleet-checker diff of each moved function, old against new,
normalised for the renames, with zero residual lines. A full MDL Shield review of a pure move re-reads
code the field's r1 review already covered (§8 D5).

### Stage A: seams inside the field (field repo, `main`; no new plugin)

- **Goal.** The field is rearranged into the engine's shape behind its own namespace. Behaviour is
  identical, and the later stages become moves.
- **New classes**, all under `profilefield_repeatable\`:
  - `local\validator`, `form\widget`, `form\column_editor`, `local\projection\{store,source,projector}`, `reportbuilder\local\entities\sets` (abstract), `privacy\set_exporter`;
  - `local\field_schema` (v1 reader);
  - `local\source` (the profile implementation of `source`).
- **Trimmed**: `field.class.php`, `define.class.php`, `display_renderer.php`, `repeatable_set.php`,
  `projection.php`, `helper.php`, `privacy/provider.php`.
- **Tests and gates**: tests are retargeted at the new classes, keeping the counts. `gates.conf` and
  its `.pl` scripts are repointed to the new files, and the sweep runs before the commit that edits
  the gates.
- **Measurement**: S7 (`EXPLAIN` of the Report Builder filters) is done; it removed the index layer in stage A0 (§8 D3).
- **Size**: about 1,800-2,200 effective lines.
- **Security-sensitive**: yes. It touches the restricted-dictionary oracle rules (B11-B18), Report
  Builder availability and escaping, and SQL. Run `security-diff-read`, then `!mdlshield review full`
  (owner's command).
- **Before merge**: all existing tests green with the same counts; every gate reddens its named test
  on pgsql and MariaDB; the new saved-report identifier test exists.

### As built in stage A (2026-10-14)

Stage A was split into A1 (validator, `field_schema`, `source`, projection classes; PR #22) and A2
(widget, `column_editor`, `sets_renderer`, `sets` entity, `set_exporter`) because together they were
5,070 effective lines. Deviations from §2 and the list above:

- `projection\source` has no `field_ids()`; the reprojection task stays adapter code and reads its own field records.
- `projector::reproject_field(source, fieldid, ?schema)` takes an optional schema; read counts are unchanged.
- `source::canonical()` returns `{id, fieldid, ownerid, data}`; a custom-field adapter aliases `value AS data`.
- The PostgreSQL upsert binds `:ownerid`; the projector's debugging message says "owner N".
- The widget and the validator are instance objects built per call by the field (`get_widget()`, `get_validator()`); the validator holds the stored payload.
- `column_editor::attributes(usage, patternavailable)`; the v1 display stays in the adapter.
- `sets_renderer` takes a sync-rows callable (the projection query runs only when a set is shown), and was created in stage A, not C1.
- The `sets` entity uses abstract `get_schema()` and `is_available()` instead of a constructor boolean. Every adapter must implement and TEST `is_available()` (the profile one uses `\core\user::SUPPORT_USER`). `name_filter_options()` gained a leading `$table` parameter.
- `stored_values` and the payload, SQL and dictionaries helpers in `helper.php` are unchanged: they are B1 moves.
- The class `local\projection` (adapter, `reproject_user`) and the namespace `local\projection\...` (engine) coexist.
- The saved-report pin test is `repeatable_sets_test::test_column_and_filter_identifiers_are_stable`, with the literal list `repeatable_langs:{cpf, lang_code, lang_name, level_code, level_name, name, setnumber}` (`cpf` only with `customfield_pattern`).


### Stage B: `local_repeatable` library half, and the field switches to it

- **B1 (new repo `moodle-local_repeatable`)**:
  - Skeleton from `mdl new-plugin`: `CLAUDE.md`, `ci.yml`, release workflow, `.gitattributes`, privacy `null_provider`, `pluginname`.
  - `local\schema`, `local\payload`, `local\sql`, `bridge\dictionaries`, `bridge\pattern`, `local\stored_values`, `privacy\set_exporter`.
  - Their tests, the engine generator (`schema_json()`), the lang strings these classes use, and the matching gates.
  - `ci.yml` installs local_dictionaries and customfield_pattern as optional companions.
  - Size: about 1,600 source + 1,000 tests ≈ 2,600.
  - Security: the code contains SQL builders and the restricted-dictionary bridge. If the
    move-identity check is clean, a full review is optional (§8 D5).
- **B2 (engine)**:
  - `local\validator` with its tests (the validator part of `dictionary_codes_test`, `pattern_field_test` and `field_class_test`) and gates B1-B8, B11-B18, RQ1, RQ2, PT7, LR4.
  - Size: about 450 + 900 ≈ 1,350.
  - Security: yes, it carries the oracle rules. Run `security-diff-read`; MDL Shield only if it is not a pure move.
- **B3 (field)**:
  - Deletes the moved classes and calls the engine.
  - `version.php` gets `dependencies` and a bump; `ci.yml` gets the dependency.
  - Moved lang strings are deleted. Tests and gates are reduced to the adapter's set.
  - Size: about −3,000/+300. The deletions dominate; measure.
  - Security: wiring only. Run `security-diff-read` on the call sites that pass `$canread` and the stored payload.
- **Before merge**: the moved-test count reconciles. A fleet-checker confirms that r1 count equals
  engine count plus field count for the moved files. Both repos' matrices are green with the engine
  on `main`, and both repos' sweeps are clean.

### Stage C: UI, projection and Report Builder move

- **C1 (engine)**:
  - `form\widget`, `form\column_editor`, `output\sets_renderer` (created in stage A); the three templates; `styles.css` renamed to `local-repeatable-*`; `bootstrap_compat_test`.
  - The widget, editor and display lang strings; gates ES1-ES5, PT12, PT13, X1.
  - Size: about 1,000 PHP + 667 templates/CSS + 600 tests ≈ 2,300.
- **C2 (engine)**:
  - The five AMD modules renamed to `local_repeatable/*` and built.
  - A Behat-free JS check: `mdl grunt` and eslint at zero warnings.
  - Size: about 1,850, with `amd/build` excluded from the estimate.
- **C3 (engine)**:
  - `projection\{store,source,projector}`, `reportbuilder\local\entities\sets` (`pg\jsonb` and `pg\index_reconciler` were removed in stage A0).
  - Tests, with gates J1-J3, J6-J9, E1-E6, PT6 and R1 (the reconcile ownership gates were removed in stage A0):
    - Projector tests run against a **temporary table**, `$dbman->create_temp_table()` (core52:`lib/ddl/database_manager.php:459` [V]), built from a fixture XMLDB definition.
    - The first test of C3 must prove that the upsert branches and `{table}` placeholders work on a temp table on both families. If they do not, the projector tests stay in the adapters and their gates stay there too [I].
    - Entity gates are held by unit tests on column and filter objects (`format_value`, `get_is_available`); no SQL is needed.
  - Size: about 1,100 + 700 ≈ 1,800.
  - Security: yes (SQL, DDL quoting, Report Builder availability and escaping). Run `security-diff-read`; MDL Shield if it is not a pure move.
- **C4 (field)**:
  - Deletes the moved UI, projection and Report Builder code, switches the `js_call_amd` calls (§2.3), deletes `amd/`, `templates/`, `styles.css` and the moved strings.
  - Keeps the thin `repeatable_set` subclass and the datasource; bump.
  - Size: about −3,500/+250.
  - Before merge: Behat on m502 for all four features, with scenario counts read; the saved-report identifier test is green.

### Stage D: `customfield_repeatable` (new repo `moodle-customfield_repeatable`)

- **D1 (type)**:
  - `field_controller` (§4.2), `data_controller` (§4.3), `local\source`.
  - `db/install.xml` (`customfield_repeatable_set`), `db/uninstall.php`.
  - Course-area observers (`course_created`, `course_updated`, `course_restored` → reproject the instance [I: confirm which fire after the data write]) and the daily `reproject_sets` task.
  - Privacy (§4.6), lang en and pt_br, generator, tests, Behat (course edit form: add sets, a failed validation keeps the labels, and a course listing shows the summary), gates.
  - Size: about 1,200 source + 1,300 tests ≈ 2,500.
  - Security: **yes**. It touches privacy, SQL, restore, the handler's capability decisions and the validation oracle. Run `security-diff-read`, then `!mdlshield review full`.
  - Before merge:
    - the privacy `component_is_compliant()` test;
    - a backup-and-restore test that asserts the projection rows of the restored course;
    - an `export_value()` test with JSON holding `<script>` and Markdown asterisks;
    - locked-keys and restricted-dictionary tests through `instance_form_validation()`;
    - a field-delete test asserting the projection rows are gone.
- **D2 (Report Builder: course, cohort, group/grouping; owner decision D2)**:
  - `datasource\course_sets` plus the entity subclass.
  - Tests for escaping, availability per course-field visibility (`NOTVISIBLE`, `VISIBLETOTEACHERS`, `VISIBLETOALL`), the DB allowlist and identifier stability. One Behat scenario.
  - Size: about 350 + 450 ≈ 800.
  - Security: yes (Report Builder access). Run `security-diff-read`; an MDL Shield review can be combined with D1 if they land as one PR under 3,000 lines.

### Stage E: release

- Cut `MOODLE_502_STABLE` from `main` in the two new repos. Create the 405, 501 and 503 branches with
  their `version.php`, `ci.yml` and README.
- Merge field `main` into `MOODLE_405/501/503_STABLE`, keeping each branch's `version.php` and
  `ci.yml` (fleet rule), and set `dependencies` per branch.
- Update `local_dictionaries` `ci.yml` and docblock (§5.3), `plugins.conf` and the READMEs. Write
  CHANGELOGs with the r1 → r2 upgrade notes (§5.2, items 1, 4, 7, and §1.3).
- Gate: `mdl ci <repo> --matrix --behat` on all four branches of all three repos and both DB
  families, every leg log read for `: FAILED`, then tags and `git archive` zips.
- Size: version, `ci.yml` and docs only. No MDL Shield review (fleet rule: version-only/docs).

---

## 7. Names

| Name | moodle.org directory | GitHub `uaiblaine` | Verdict |
|---|---|---|---|
| `local_repeatable` | `https://moodle.org/plugins/local_repeatable` → HTTP 404; `https://marketplace.moodle.com/plugins/local_repeatable` → HTTP 404 [V] | `gh repo view uaiblaine/moodle-local_repeatable` → "Could not resolve to a Repository" [V] | Free |
| `customfield_repeatable` | `moodle.org/plugins/customfield_repeatable` → 404; marketplace → 404 [V] | `gh repo view uaiblaine/moodle-customfield_repeatable` → not found [V] | Free |

- **Control.** `moodle.org/plugins/local_mail`, an existing plugin, answers 303 to
  `marketplace.moodle.com/plugins/local_mail`, which shows the plugin "Mail" [V]. So a 404 on both
  hosts means absent from the directory.
- `gh search repos local_repeatable` and `customfield_repeatable` returned no Moodle plugin [V].
- `~/dev` has no checkout under either name [V].
- The directory lists published plugins only, so unpublished code elsewhere could use the name. The
  only consequence would be a clash on a site that installs both.
- No alternative is needed. If one were wanted later: `local_repeatablesets`.

---

## 8. Open decisions for the owner

| # | Decision | Recommendation | Cost of the alternative |
|---|---|---|---|
| D1 | Projection storage (§3) | One table per adapter. The field keeps `profilefield_repeatable_data`, and r2 needs no data migration. | Engine table: copy code in `local_repeatable/db/install.php`, because `local` installs after the field upgrades; the old table can be dropped only a release later; a privacy split inside one table; adapter discovery for the index sweep. |
| D2 | Report Builder scope of `customfield_repeatable` r1 | Course area only (one datasource, §4.5). Other areas still store, edit and display the type, and show the `export_value()` summary in core datasources. | Each further area is about 300 lines plus tests: cohort, group/grouping, content bank, and later report and shared (5.1+, which also needs the component/area join). |
| D3 | Keep the PostgreSQL expression-index layer (S7) | Measure in stage A. If the filters do not use the indexes, drop the adhoc task, expression indexes and REINDEX **before** the move. Then the engine and the custom field carry about 400 fewer lines and one task fewer each. Keep JSONB and GIN only if `EXPLAIN` shows the GIN index used. | Keeping it unmeasured moves the largest complexity-for-nothing candidate into two plugins and an engine API (`indexed_columns_enabled`). Done: removed in stage A0. |
| D4 | What `export_value()` shows for the custom field: course listings, the core Report Builder column, web services `value` | A compact escaped list: one line per set, the title value plus the subline values. Web-service clients still get the raw JSON as `valueraw` [I]. | Full plain layout: heavier course listings. A count only ("3 sets"): useless in core reports. |
| D5 | MDL Shield on pure-move PRs (B1, C1-C3) | Skip them when the move-identity check is clean. Review A, D1 (+D2), and any move PR that changes behaviour. That is about three reviews of the 100/month. | Reviewing every PR costs about seven reviews and re-reads code the r1 review already covered. |
| D6 | `customfield_repeatable` on 4.5 | Ship all four branches. No API blocker was found: the 4.5 data and handler hooks used are present [R/V]. The 4.5 Report Builder column is not sortable (core behaviour). | 5.1+ only: one branch fewer to maintain, and 4.5 sites get the profile field only. |

### Owner decisions (2026-10-14)

- **D1**: one projection table per adapter, as recommended.
- **D2**: Report Builder in `customfield_repeatable` r1 covers **course, cohort and group/grouping**
  (three datasources, about 600 lines more in stage D than the course-only estimate). Content bank,
  and the 5.1+ report and shared areas, stay follow-ups.
- **D3**: measured 2026-10-14 (PostgreSQL 18.6, 50,000 users, 125,000 projection rows): no Report
  Builder filter or listing uses the expression indexes or the GIN index (`ILIKE '%v%'`,
  `LOWER(expr) = LOWER(v)` against an index on the bare expression, partial predicates Report
  Builder never emits, and no `@>` anywhere). The layer is removed in stage A0, before any code moves.
- **D4**: the recommended compact escaped list.
- **D5**: MDL Shield skipped on pure-move PRs whose move-identity check is clean; reviewed on stage
  A, stage D and any move that changes behaviour.
- **D6**: `customfield_repeatable` ships on all four branches, 4.5 included.
