# Changelog

All notable changes to this project will be documented in this file.

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

