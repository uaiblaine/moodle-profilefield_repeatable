# Changelog

All notable changes to this project will be documented in this file.

## [1.0.0] - 2026-04-01

### Added
- Initial release of the Repeatable profile field plugin
- Hybrid storage: JSON fallback in `user_info_data` + JSON-per-set in `customfield_repeat_data` table
- PostgreSQL JSONB support with optional GIN expression indexes on up to 3 indexed sub-items
- AMD modules for edit form interactivity (`profilefield_repeatable/repeatable`) and display accordion (`profilefield_repeatable/displayaccordion`)
- Optional reference resolution via `local_profilefield_repeatable` plugin for domain mapping
- Full privacy API compliance: export and delete support for both storage tables
- Async index reconciliation via adhoc task for PostgreSQL index management
- Comprehensive PHPUnit test suite (600+ LOC) covering field definition, storage, display, migrations, and privacy
- Mustache templates for edit form, accordion display (profile pages), and plain list display (other contexts)
- Support for Moodle 4.05+ with PostgreSQL fallback to MySQL/MariaDB JSON storage

### Fixed
- Backward compatibility layer ensuring migrations and install steps work without autoloading

---

## Version History

| Version | Release Date | Notes |
|---------|--------------|-------|
| 1.0.0   | 2026-04-01   | Initial stable release |

