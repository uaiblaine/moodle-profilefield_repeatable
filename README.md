moodle-profilefield_repeatable
==============================

[![Moodle Plugin CI](https://github.com/uaiblaine/moodle-profilefield_repeatable/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/uaiblaine/moodle-profilefield_repeatable/actions/workflows/ci.yml?query=branch%3Amain)

A Moodle user profile field plugin that stores repeatable field data as sets of sub-items with efficient PostgreSQL JSONB indexing and optional reference resolution via the local_profilefield_repeatable plugin.


Requirements
------------

This plugin requires Moodle 4.5+

Database baseline follows Moodle requirements:

- PostgreSQL 13+
- MySQL 8.0+ / MariaDB (supported fallback mode)

Recommended for best performance: PostgreSQL 13+.


Motivation for this plugin
--------------------------

Standard Moodle profile fields support only simple text values. This plugin provides:

1. **Repeatable sets** — Store multiple related data values as structured records (e.g., language proficiencies, certifications, work experience)
2. **Efficient indexing** — PostgreSQL GIN expression indexes on selected sub-items for fast queries
3. **Reference resolution** — Optional mapping to external domains (via local_profilefield_repeatable) for rich display values
4. **Backward compatibility** — Falls back to JSON storage in user_info_data when storage table unavailable
5. **Privacy compliance** — Full GDPR export/delete support


Installation
------------

Install the plugin like any other plugin to folder
/user/profile/field/repeatable

See http://docs.moodle.org/en/Installing_plugins for details on installing Moodle plugins.

**Optional dependency**: If you want to use reference resolution in repeatable fields, also install [moodle-local_profilefield_repeatable](https://github.com/uaiblaine/moodle-local_profilefield_repeatable).


Usage & Configuration
---------------------

After installing, navigate to **Site Administration > Plugins > Moodle plugins > User profile fields > Profile fields**.

### Creating a Repeatable Field

1. Click "Create a new profile field"
2. Select type **Repeatable**
3. Configure:
   - **Field name** — Unique identifier (e.g., `languages`, `certifications`)
   - **Field description** — Shown when editing profile
   - **Sub-items** — Newline-delimited list of column names (required)
     ```
     Language
     Proficiency
     Certification
     ```
   - **Indexed sub-items** — (Optional) Mark up to 3 sub-items for PostgreSQL GIN indexing, for faster filtering
     ```
     Language
     Proficiency
     ```
   - **Reference field mappings** — (Optional) Map sub-items to external domains for rich display
     ```
     Certification|local_certifications
     Language|iso639
     ```

### Display Modes

- **Profile pages** (`/user/profile.php`, `/user/view.php`) — Accordion display with collapsible sets
- **Other contexts** — Plain list table format

### Editing

Users can:
- Add new sets via "Add new set" button
- Edit all sub-item values within each set
- Remove sets via action column

Empty sets are removed automatically on save. At least one empty set remains in the form.


Advanced Features
-----------------

### PostgreSQL Expression Indexes

On PostgreSQL, the plugin automatically creates expression indexes on indexed sub-items (up to 3 per field):

```sql
CREATE INDEX CONCURRENTLY pfrd_f{fieldid}_{subitem_hash}
ON mdl_profilefield_repeatable_data (fieldid, ((data ->> '{subitem}')))
WHERE fieldid = {fieldid}
  AND jsonb_exists(data, '{subitem}')
```

The plugin also ensures a base JSONB GIN index for generic JSONB lookups:

```sql
CREATE INDEX pfrd_{tablehash}_gin_ix
ON mdl_profilefield_repeatable_data USING GIN (data jsonb_path_ops)
```

Indexes are managed automatically via an adhoc task when field configuration changes.

### PostgreSQL Version Matrix

| PostgreSQL Version | Status | Notes |
|--------------------|--------|-------|
| 13+ | Active baseline | Fully supported by Moodle 4.5+ and by this plugin |
| 14+ | Optional enhancements | SQL/JSON path queries can be used in custom reporting |
| 15+ | Optional enhancements | Additional SQL features available, no plugin requirement change |
| 17+ | Optional enhancements | Recommended for newest planner/runtime improvements |

This release keeps compatibility with the Moodle baseline while documenting newer PostgreSQL opportunities.

### Reference Resolution

Indexed sub-items can be mapped to external domains (plugins implementing the resolver interface):

```text
Language|iso639              → resolve('en', 'iso639') → 'English'
Proficiency|cefrframework    → resolve('B2', 'cefrframework') → 'Upper-Intermediate'
```

Domain plugins implement `\local_profilefield_repeatable\resolver`.


API
---

### PHPUnit Testing

Tests run in CI (moodle-plugin-ci) against PostgreSQL and MariaDB. To run
them locally from an initialised Moodle PHPUnit environment (Moodle 5.x
split layout shown; drop `public/` on older layouts):

```bash
vendor/bin/phpunit public/user/profile/field/repeatable/tests/
```

Covered areas: field definition, storage upsert/read, display rendering,
privacy export/delete, and deletion-cleanup observers.


Troubleshooting
---------------

#### Data appears empty after upgrade
Ensure the `profilefield_repeatable_data` table was created. Check upgrade logs in Site Administration > Logs.

#### Indexes not created on PostgreSQL
Index reconciliation runs as an **ad hoc task** queued whenever the field configuration is saved
(it is not listed under Scheduled tasks). Check Site Administration > Server > Tasks > Ad hoc tasks
(and the task logs) for recent runs, ensure the field has configured "Indexed sub-items" values,
and verify PostgreSQL 13+ connectivity. Running cron processes the queue.

#### MySQL/MariaDB behavior
On MySQL/MariaDB the storage table (`profilefield_repeatable_data`) is populated exactly as on
PostgreSQL, with a `user_info_data` JSON fallback kept in sync. Only the PostgreSQL-specific
parts are skipped: the JSONB column conversion, the base GIN index, and the per-sub-item
expression indexes.

#### Reference resolution not working
1. Verify `moodle-local_profilefield_repeatable` is installed
2. Ensure the resolver domain plugin is installed
3. Check field's Third-party domain mappings configuration


Capabilities
------------

This plugin does not introduce new capabilities. Profile field visibility is controlled by existing user/editownprofile and user/editprofile capabilities.


License
-------

This plugin is licensed under the [GNU GPL v3 License](http://www.gnu.org/copyleft/gpl.html).

Copyright © 2026 Anderson Blaine
