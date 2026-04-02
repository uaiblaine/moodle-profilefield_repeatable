moodle-profilefield_repeatable
==============================

[![Moodle Plugin CI](https://github.com/uaiblaine/moodle-profilefield_repeatable/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/uaiblaine/moodle-profilefield_repeatable/actions/workflows/ci.yml?query=branch%3Amain)

A Moodle user profile field plugin that stores repeatable field data as sets of sub-items with efficient PostgreSQL JSONB indexing and optional reference resolution via the local_profilefield_repeatable plugin.


Requirements
------------

This plugin requires Moodle 4.05+

**Recommended**: PostgreSQL database for optimized JSONB storage and indexing. Supports fallback JSON storage on MySQL/MariaDB.


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

On PostgreSQL, the plugin automatically creates GIN expression indexes on indexed sub-items:

```sql
CREATE INDEX CONCURRENTLY pfrd_f{fieldid}_{subitem_hash}
ON customfield_repeat_data USING GIN ((data ->> '{subitem}'))
WHERE fieldid = {fieldid}
```

Indexes are managed automatically via an adhoc task when field configuration changes.

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

```bash
./vendor/bin/phpunit user/profile/field/repeatable/tests/
```

All critical paths covered: field definition, storage, display, privacy, migrations.


Troubleshooting
---------------

#### Data appears empty after upgrade
Ensure the `customfield_repeat_data` table was created. Check upgrade logs in Site Administration > Logs.

#### Indexes not created on PostgreSQL
Check Site Administration > Scheduled tasks > profilefield_repeatable > Reconcile indexes for recent runs. Verify PostgreSQL version ≥ 9.4 (JSONB required).

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
