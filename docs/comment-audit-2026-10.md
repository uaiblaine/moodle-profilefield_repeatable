# Comment audit: code findings (2026-10)

Comment audit of `profilefield_repeatable` (fleet kit `moodle-dev/ci/comment-audit`, 2 batches, 12 agents). The audit edited comments only; the findings below are about code and were not acted on. Each bug or risk finding went to an independent refuter; the verdict is quoted. Line numbers are at the audit's base (main after PR #11).

## 1. [bug] user_updated observer runs before auth_ldap writes the payload

`db/events.php:37` (batch b00)

**Evidence.** The observers declare no 'internal' => false, so core\event\manager calls them immediately at trigger time. lib/authlib.php update_user_record() (5.2:713-714, 4.5 same) runs user_update_user($newuser, false, $triggerevent), which triggers user_updated, BEFORE profile_save_custom_fields(). auth_ldap's sync (auth/ldap/auth.php:978-990) wraps each user in a delegated transaction. observer::user_updated therefore reprojects from the old payload, and the projection stays stale until the daily reproject_sets. The class docblock in classes/observer.php describes the intended behaviour (dispatch after the transaction). tests/observer_test.php triggers the event after the write, outside a transaction, so it cannot catch this.

**Suggestion.** Add 'internal' => false to the user_updated and user_created observers so core buffers them until the sync's transaction commits. Outside a transaction they still run at once. Add a test that triggers user_updated inside a delegated transaction before profile_save_custom_fields() and asserts the projection after commit.

**Refuter verdict:** holds (bug). The finding holds on both paths, and the observer docblock is wrong about the order of writes. Update path: auth_ldap `update_users()` opens `start_delegated_transaction()` and calls `update_user_record(..., $triggerevent = true, ...)`. In lib/authlib.php (5.2, lines 713-714), `user_update_user($newuser, false, $triggerevent)` runs first and triggers `user_updated`. Only after that does `profile_save_custom_fields()` rewrite `{user_info_data}`. `db/events.php` declares no 'internal' key, so core defaults the observer to internal. In `manager::process_buffers()`, internal observers run at once even inside a transaction; only non-internal ones are held in `$extbuffer` until commit. So `observer::user_updated` calls `projection::reproject_user()`, which reads the old `user_info_data` payload, finds the projection current, and writes nothing. The new payload is saved afterwards and no event follows, so the projection stays stale until the `reproject_sets` task. The 4.5 authlib has the same ordering. Create path: auth_ldap `user_create_user($user, false)` triggers `user_created` with the default `$triggerevent = true`, before the later `update_user_record($user->username, $this->get_profile_keys(true), false)` saves the profile fields. That call passes `$triggerevent = false`, so no event follows it, and a new LDAP user's repeatable data is also not projected until the daily task. The class docblock in classes/observer.php says the sync writes before `user_updated` is dispatched, which is the opposite of the real order. The suggested fix is sound: with 'internal' => false the events are buffered until `allow_commit()`, then dispatched after the profile data exists. The severity is a bug rather than anything worse because the daily `reproject_sets` task self-heals the projection.

## 2. [bug] Sub-item and field names are escaped twice in Mustache output

`field.class.php:59` (batch b00)

**Evidence.** format_string($subitem, true, ['context' => $context]) escapes by default: a bare & becomes &amp;, and < > become entities when striptags is on. The result then goes into double stashes ({{name}}, {{fieldlabel}} in edit_form; {{key}} in display_plain; {{label}} and the {{fieldname}} aria-label in display_accordion), which escape again. A sub-item called 'R&D' renders as 'R&amp;D'. Same at field.class.php:63 and display_renderer.php:135, 246 and 305. datasource repeatable_sets.php:88 feeds the same escaped name into a lang_string entity title.

**Suggestion.** Pass 'escape' => false to these format_string() calls, because the templates escape. Pin it with a test that renders a sub-item containing '&' and '<'.

**Refuter verdict:** holds (bug). The finding holds. field.class.php:59 calls `format_string($subitem, true, ['context' => $context])` with no `'escape' => false`. In core 5.2 (`lib/classes/formatting.php`), `$escape` defaults to true. For "R&D", `replace_ampersands_not_followed_by_entity()` runs and the result is "R&amp;D". With the striptags setting on, `<` and `>` also become `&lt;` and `&gt;`. The result goes into `edit_form.mustache` as `{{name}}` and `{{fieldlabel}}`, both double stashes, which escape again to `R&amp;amp;D`. The browser then shows a literal "R&amp;D". The same pattern appears at field.class.php:63 (`fieldlabel`) and at display_renderer.php:135, 246 and 305. Those values feed `{{key}}` in display_plain.mustache, `{{label}}` in display_accordion.mustache and `aria-label="{{fieldname}}"` in display_accordion.mustache. I found no guard elsewhere. The sub-item names come from admin-entered field config, so `&` is a reachable input. The fleet rule says Mustache double stashes want the plain spelling, which means `escape => false`. One narrow point: repeatable_sets.php:88 passes the name into a `lang_string` parameter that goes into an entity title. Whether that title is escaped again depends on how report builder renders it, so I did not verify it. The core part of the finding still stands. Severity is a minor display bug, with no security impact. Double escaping is safe against XSS, and the only effect is wrong visible text on names containing `&`, `<` or `>`.

## 3. [bug] Ampersands in field and sub-item names are escaped twice

`field.class.php:59` (batch b01)

**Evidence.** 'fieldlabel' => format_string($this->field->name) and 'name' => format_string($subitem, true, [...]) feed {{fieldlabel}} / {{name}} in templates/edit_form.mustache. classes/output/display_renderer.php:133 ('key'), :244 ('label') and :303 ('fieldname', also in aria-label="{{fieldname}}") do the same for the display templates. format_string() escapes '&' to '&amp;' (lib/classes/formatting.php:108, replace_ampersands_not_followed_by_entity), and Mustache's escape 's' runs htmlspecialchars again (renderer_base.php:122), so a field named 'R&D' shows as 'R&amp;D'.

**Suggestion.** Pass ['escape' => false] (with the context) to format_string() for every value that goes into a double stash. Pin both a '&' and a '<' case with a test, as the fleet rule on format_string asks.

**Refuter verdict:** holds (bug). The double escaping is real. field.class.php:59 and :63 call the global format_string(), which escapes by default; core's formatting::format_string has `bool $escape = true` and runs replace_ampersands_not_followed_by_entity() ("R&D" becomes "R&amp;D"). The result goes into edit_form.mustache as {{fieldlabel}} and {{name}}, and the Mustache engine is built with 'escape' => 's' (renderer_base), so the "&" is escaped again, giving "R&amp;amp;D" in the HTML. The browser then shows the literal text "R&amp;D". The same happens for display_renderer.php:135 ('key' into {{key}} in display_plain.mustache:42), :246 ('label' into {{label}} in display_accordion.mustache:74) and :305 ('fieldname' into {{fieldname}} in the accordion's aria-label at line 54). No call passes 'escape' => false, and no template uses a triple stash for these values. The error is cosmetic: it garbles the visible text only for names containing "&" (and an "&" before an entity-like token). There is no security impact, because over-escaping is the safe direction.

## 4. [bug] Sync-footer absence assertion can never fail

`tests/field_class_test.php:410` (batch b01)

**Evidence.** $this->assertStringNotContainsString('profilefield-repeatable-display-sync', $output); but templates/display_accordion.mustache:80 renders the footer as <p class="profilefield-repeatable-sync">. No markup ever contains 'profilefield-repeatable-display-sync', so the test passes even if the footer renders.

**Suggestion.** Assert on the class the template emits: assertStringNotContainsString('profilefield-repeatable-sync', $output). Then check that forcing hassync true in build_accordion_template_context() makes the test fail.

**Refuter verdict:** holds (bug). The test at tests/field_class_test.php:410 asserts the absence of the substring 'profilefield-repeatable-display-sync'. A repo-wide search finds that string only at that line. The template renders the footer as `<p class="profilefield-repeatable-sync">` (templates/display_accordion.mustache:80), inside `{{#hassync}}`. styles.css:159 also uses `.profilefield-repeatable-sync`. The renderer sets `'hassync' => !empty($timemodified)` (classes/output/display_renderer.php:293), so the footer is a real conditional and the test's intent is valid. Because no output can contain the asserted string, the test passes even if the footer renders. The test name promises the footer is hidden without a timestamp, and nothing actually checks it. This is a vacuous assertion rather than a production defect, so I would rate it a test bug of low-to-medium severity. The suggested fix is correct: assert on 'profilefield-repeatable-sync'.

## 5. [risk] auth_ldap sync fires user events before writing the payload, so the observers reproject stale data

`classes/observer.php:27` (batch b01)

**Evidence.** db/events.php declares the user_updated and user_created observers without 'internal' => false, so they run synchronously (lib/classes/event/manager.php:274-277, 129). auth_ldap update_users() -> update_user_record(..., true) -> user_update_user() triggers user_updated (user/lib.php:253) before profile_save_custom_fields() (lib/authlib.php:713-714). sync_users() new users: user_create_user() triggers user_created (auth/ldap/auth.php:926) before update_user_record() saves the fields (:941). The observer class docblock says the opposite ('so the observers below see the new payload').

**Suggestion.** Declare the user_updated/user_created observers with 'internal' => false. auth_ldap wraps each user in a delegated transaction (auth/ldap/auth.php:978, ~920), so the events would then be delivered after commit, after the write. Otherwise correct the observer docblock and rely on task\reproject_sets for LDAP sync. Either way, add a test that triggers the event before profile_save_custom_fields() inside a transaction.

**Refuter verdict:** holds (risk). I confirmed this in the Moodle 5.2 checkout. The observers in db/events.php have no 'internal' key. lib/classes/event/manager.php:274-277 defaults a missing key to internal=true, and internal observers run at once even inside a DB transaction. Only external observers are buffered until commit (manager.php:135-147). Both LDAP paths therefore fire the event before the profile payload is written.

Update path: auth_ldap update_users() opens a delegated transaction (auth/ldap/auth.php:978) and calls update_user_record($username, $updatekeys, true, ...). In lib/authlib.php:713-714 that function calls user_update_user($newuser, false, $triggerevent) and only then profile_save_custom_fields(). user/lib.php:252-253 triggers user_updated from inside user_update_user. The synchronous observer runs projection::reproject_user(), which reads user_info_data, so it sees the old payload. The new payload then lands with no projection rewrite.

Create path: sync_users() at auth/ldap/auth.php:926 calls user_create_user($user, false) with the default triggerevent=true. The user_created observer runs when the account has no user_info_data. Fields are saved only afterwards, at auth/ldap/auth.php:941, via update_user_record(..., false), which fires no event.

Net effect: for LDAP-synced repeatable fields, the observers never see the new payload, so the projection stays stale until the daily task\reproject_sets repairs it. Report Builder is wrong for up to about a day, and the observer docblock claims the opposite. Impact is bounded because the scheduled task is the backstop, so this is a risk, not a bug.

The suggested fix is sound. With 'internal' => false the events are buffered while the delegated transaction is open and delivered on commit, after profile_save_custom_fields() has run. The alternative of correcting the docblock is also valid. I read only the 5.2 checkout. The 4.5 grep only matched profile_save_custom_fields at lib/authlib.php:714 and did not match the user_create_user call in auth/ldap/auth.php, so I did not check 4.5 further.

## 6. [risk] Sub-item literals in the index DDL can be parsed as DML placeholders

`classes/task/reconcile_indexes.php:287` (batch b00)

**Evidence.** $literal = $this->quote_literal($subitem) is interpolated into CREATE INDEX ... (data ->> $literal) ... jsonb_exists(data, $literal), then run through $DB->execute($sql). moodle_database::fix_sql_params() scans the whole string, quoted literals included, for '?', ':name' and '$1'. An indexed sub-item such as 'Start:date' or 'Name?' makes execute() throw invalidqueryparam or mixedtypesqlparam. The catch reports it only through debugging(), so the index is silently never created.

**Suggestion.** Reject ':', '?' and '$' in indexed sub-items (param2) in define_validate_specific(), or emit the literal as an E'' string with those characters written as \x3a, \x3f and \x24.

**Refuter verdict:** holds (risk). The finding holds. Sub-items are free text: validate_subitems_config() only trims, checks duplicates and requires at least one. parse_subitems() adds no character filter, and nothing rejects ':', '?' or '$'. A sub-item such as "Name?" or "Start:date" can therefore be listed in param1 and flagged in param2. get_desired_index_map() passes it on unchanged. create_index_concurrently() splices it in as '...' through quote_literal(), which only doubles single quotes, and calls $DB->execute($sql) with no params. pgsql_native_moodle_database::execute() runs fix_sql_params() first (line 855), and that scans the whole SQL string, quoted literals included. "Name?" gives q_count=1 and "Start:date" matches the named-parameter regex; both give count>0 with 0 params, so it throws dml_exception invalidqueryparam. A sub-item such as "$1" would hit the dollar regex the same way. The literal appears twice in the SQL, so the count is at least 2 and the throw is certain. The catch (\Throwable) reports only through debugging(DEBUG_DEVELOPER), so on production the index is silently never created. The result is a missing performance index with no data loss or security impact, hence risk rather than bug. 'a::b' casts are exempt only because of the (?<!:) lookbehind.

## 7. [question] The planner may never use the partial expression indexes

`classes/task/reconcile_indexes.php:290` (batch b00)

**Evidence.** Each index is (fieldid, (data ->> 'k')) WHERE fieldid = N AND jsonb_exists(data, 'k'). Report Builder filters on ((alias.data)::jsonb ->> :param) through the core text filter (LIKE or LOWER comparisons) or the autocomplete filter (IN). PostgreSQL's predicate prover cannot derive jsonb_exists(data, 'k') from a condition on data ->> 'k', so the partial index does not qualify. A text-filter comparison wrapped in LOWER() also cannot use a plain expression index.

**Suggestion.** Check with EXPLAIN on a seeded PostgreSQL site. If confirmed, use the predicate (data ->> 'k') IS NOT NULL, which a strict comparison on the same expression implies, and consider an index that matches how the filters actually compare.

## 8. [question] show_field_content() false also removes the field from web-service user details

`field.class.php:302` (batch b00)

**Evidence.** Core's user_get_user_details() (user/lib.php:422) and core_myprofile_navigation (lib/myprofilelib.php:334) both gate on show_field_content(). Returning false hides the field from core_user_get_users_by_field and from the Moodle App profile as well as from the inline contact list.

**Suggestion.** Confirm this is intended. If the App or web services should see the field, override only the profile-page path, or document the omission in README.

## 9. [improvement] REINDEX right after CREATE INDEX does nothing useful

`classes/helper.php:405` (batch b00)

**Evidence.** ensure_postgres_jsonb_support() creates the GIN index and immediately calls maybe_optimize_gin_deduplication(), which runs REINDEX INDEX on PostgreSQL 14+. The rebuild produces the same index on the same server and takes a lock that blocks writes. POSTGRES_FEATURE_JSONPATH (line 28) has no caller apart from the switch in postgres_supports_feature().

**Suggestion.** Remove maybe_optimize_gin_deduplication(), the GIN_DEDUP flag and the unused JSONPATH flag, or document a real reason to rebuild.

## 10. [improvement] Dead 'raw' fallback in prefetch_reference_labels()

`classes/output/display_renderer.php:508` (batch b00)

**Evidence.** $values = (array)($set['values'] ?? $set['raw'] ?? []); but convert_payload_to_display_sets() always sets 'values' and nothing ever sets 'raw'.

**Suggestion.** Use $set['values'] directly.

## 11. [improvement] Resolved-name column queries the resolver per row for unresolved codes

`classes/reportbuilder/local/entities/repeatable_set.php:161` (batch b00)

**Evidence.** The column callback calls self::resolve_label($domain, $value) for each row, which calls resolver::resolve(). The companion caches only labels it found (MUC). Each row whose code has no label costs two queries (domain id, then item lookup), so a large report has N+1 queries. The display renderer avoids this with a bulk prefetch.

**Suggestion.** Memoise per domain and code inside the entity, including misses, for example in a static array keyed "<domain>\n<code>".

## 12. [improvement] Informational debugging() call on every task run

`classes/task/reconcile_indexes.php:67` (batch b00)

**Evidence.** debugging('profilefield_repeatable: reconcile indexes running on PostgreSQL ' . $pgmajor, DEBUG_DEVELOPER); runs unconditionally on PostgreSQL. It adds noise to developer-mode cron output, and every PHPUnit test that executes the task has to reset debugging.

**Suggestion.** Use mtrace(), or remove the line.

## 13. [improvement] Dead wrappers and an install step that never does anything

`db/lib.php:82` (batch b00)

**Evidence.** profilefield_repeatable_is_postgres, _parse_subitems, _normalise_payload, _encode_payload, _postgres_index_exists and _quote_identifier have no caller in the repo (rg). profilefield_repeatable_upgrade_ensure_storage_table() only creates a missing table, but core has already built it from install.xml before xmldb_profilefield_repeatable_install() runs (lib/upgradelib.php:729/741). Its hand-written schema duplicates install.xml and can drift from it.

**Suggestion.** Delete the unused wrappers and the ensure_storage_table call and function. Keep only what install.php and future upgrade steps need.

## 14. [improvement] Private normalise_payload() is only reached by a reflection test

`field.class.php:335` (batch b00)

**Evidence.** private function normalise_payload($rawpayload) has no production caller. tests/field_class_test.php:177 calls it through ReflectionMethod.

**Suggestion.** Delete it and test helper::normalise_payload() directly.

## 15. [improvement] Accordion palette is a literal light theme with no theme-token fallback

`styles.css:10` (batch b01)

**Evidence.** --pfr-text: #1f2937; --pfr-bg: #ffffff; --pfr-bg-soft: #f9fafb; --pfr-border: #e5e7eb; ... --pfr-accent: #1d4ed8; Under Moodle 5.x dark mode (:root[data-bs-theme=dark] moves the --bs-* tokens) the profile accordion keeps white cards on a dark page and ignores the theme's primary colour.

**Suggestion.** Define each --pfr-* token through the theme's tokens with a fallback chain, e.g. --pfr-bg: var(--bs-body-bg, #fff); --pfr-text: var(--bs-body-color, #1f2937); --pfr-border: var(--bs-border-color, #e5e7eb); --pfr-accent: var(--bs-primary, var(--primary, #0f6cbf)). This keeps 4.5 (no --bs-*) on the fallbacks.

## Facts to relocate or correct outside the code comments

- `CLAUDE.md`: 'Edit form' says the hidden input is a value that repeatable.js 'keeps in step with the widget'. The JS reads it once on init and writes it only on form submit.
- `CLAUDE.md`: 'Canonical payload and projection' presents the user_updated/user_created observers as repairing auth_ldap sync drift. As registered (internal observers), user_updated fires before profile_save_custom_fields() in update_user_record(), so auth_ldap updates are currently repaired only by the daily reproject_sets task (see the db/events.php code finding).
- `tests/observer_test.php`: The user_event_provider() docblock claims the events are 'dispatched after profile_save_custom_fields() by auth_ldap's sync'. In core, user_updated is triggered inside user_update_user() before profile_save_custom_fields(). This file belongs to the other batch; flag it to that agent.
- `CLAUDE.md`: 'Drift is repaired by the user_updated/user_created observers and the daily task\reproject_sets' leaves out auth_ldap. Its sync dispatches user_updated/user_created before profile_save_custom_fields() writes (lib/authlib.php:713-714, auth/ldap/auth.php:926/941), so LDAP-synced changes also wait for reproject_sets unless the observers become non-internal.
- `styles.css`: The symptom on 4.5 before the sticky-head classes were owned (the head scrolled away unstuck and unpainted) was removed as history. Git keeps it; it is only worth relocating if docs/ keeps a Bootstrap-compat incident log.
- `CLAUDE.md` (from the fixer of b00): e wrappers already delegate to \profilefield_repeatable\helper, so install steps depend on a plugin class anyway. Relocation note for the owner: the repo CLAUDE.md line 102 ('Install and upgrade steps call only the db/lib.php procedural wrappers: no autoloading there.') states the same contradicted fact. It should be corrected or reduced to a convention without the 'no autoloading' reason. No edit made to db/lib.php.
