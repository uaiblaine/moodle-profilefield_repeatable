# Changelog

All notable changes to this project will be documented in this file.

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
| 2.0.0   | 2026-04-24   | Clean install baseline — no legacy migrations |
| 1.0.1   | 2026-04-02   | Documentation updates |
| 1.0.0   | 2026-04-01   | Initial stable release |

