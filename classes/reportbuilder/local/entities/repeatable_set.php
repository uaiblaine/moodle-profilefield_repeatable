<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace profilefield_repeatable\reportbuilder\local\entities;

use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\autocomplete;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\filters\text;
use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use lang_string;
use profilefield_repeatable\helper;
use profilefield_repeatable\local\pattern_columns;
use profilefield_repeatable\local\schema;

/**
 * Report Builder entity exposing one repeatable profile field as one row per set.
 *
 * Every column of the field's schema yields columns and filters named after its key, so renaming
 * a column keeps saved reports working: a free column "<key>"; a dictionary column "<key>_name"
 * (the companion's label, with an autocomplete filter over the codes present) and "<key>_code";
 * a pattern column "<key>", shown as customfield_pattern formats it, filtered on the stored value.
 * {@see schema::report_identifiers()} names them. The owning datasource joins the storage table
 * per field so a user with N sets yields N rows.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repeatable_set extends base {
    /** @var array Resolved label per "domain\ncode" key, filled by {@see self::resolve_label()}. */
    private static array $labelmemo = [];

    /** @var \stdClass The user_info_field record this entity represents. */
    private \stdClass $field;

    /**
     * Constructor.
     *
     * @param \stdClass $field The user_info_field record (datatype repeatable).
     */
    public function __construct(\stdClass $field) {
        $this->field = $field;
    }

    /**
     * Database tables used by this entity.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return ['profilefield_repeatable_data'];
    }

    /**
     * The default title for this entity.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entityrepeatableset', 'profilefield_repeatable');
    }

    /**
     * Initialise the entity, registering its columns and filters.
     *
     * @return base
     */
    public function initialise(): base {
        $schema = schema::from_field($this->field);
        $available = $this->is_field_visible();

        foreach ($this->get_all_columns($schema) as $column) {
            $this->add_column($column->set_is_available($available));
        }

        foreach ($this->get_all_filters($schema) as $filter) {
            $this->add_filter($filter->set_is_available($available));
        }

        return $this;
    }

    /**
     * Whether the current user may see this profile field in reports.
     *
     * Same gate as core's own profile-field columns and filters
     * ({@see \core_reportbuilder\local\helpers\user_profile_fields}): the field's visibility
     * setting checked at system level, for a field instance bound to a user id that matches nobody.
     * Core passes {@see \core\user::SUPPORT_USER} for the same reason: with user id 0 the TEACHERS
     * branch checks moodle/site:viewuseridentity in the global course instead of
     * moodle/user:viewalldetails, and TEACHERS and PRIVATE fields are visible when $USER->id is 0.
     *
     * @return bool
     */
    protected function is_field_visible(): bool {
        global $CFG;

        require_once($CFG->dirroot . '/user/profile/lib.php');

        $profilefield = profile_get_user_field('repeatable', (int)$this->field->id, \core\user::SUPPORT_USER, $this->field);
        return $profilefield->is_visible(\core\context\system::instance());
    }

    /**
     * The plain column title of a key: its label through format_string(), which core escapes.
     *
     * @param schema $schema
     * @param string $key
     * @return string
     */
    private function column_label(schema $schema, string $key): string {
        return format_string($schema->label($key), true, [
            'context' => \core\context\system::instance(),
            'escape' => false,
        ]);
    }

    /**
     * Build all columns for the schema's columns.
     *
     * @param schema $schema
     * @return column[]
     */
    protected function get_all_columns(schema $schema): array {
        global $DB;

        $tablealias = $this->get_table_alias('profilefield_repeatable_data');
        $domainmap = $schema->domain_map();
        $columns = [];

        $columns[] = (new column(
            schema::SETNUMBER_IDENTIFIER,
            new lang_string('setnumber', 'profilefield_repeatable'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$tablealias}.set_id")
            ->set_is_sortable(true);

        foreach ($schema->keys() as $index => $key) {
            $label = $this->column_label($schema, $key);
            $identifiers = $schema->report_identifiers($key);
            [$valuesql, $valueparams] = helper::sql_column_value(
                $DB,
                "{$tablealias}.data",
                $schema,
                $key,
                database::generate_param_name(),
                database::generate_param_name()
            );

            if (!isset($identifiers['value'])) {
                $domain = $domainmap[$key] ?? '';

                $columns[] = (new column(
                    $identifiers['name'],
                    new lang_string('columnsubitemname', 'profilefield_repeatable', $label),
                    $this->get_entity_name()
                ))
                    ->add_joins($this->get_joins())
                    ->set_type(column::TYPE_TEXT)
                    ->add_field($valuesql, 'val' . $index, $valueparams)
                    ->set_is_sortable(true)
                    ->add_callback(static function (?string $value) use ($domain): string {
                        return s($domain === '' ? (string)$value : self::resolve_label($domain, $value));
                    });

                [$codesql, $codeparams] = helper::sql_column_value(
                    $DB,
                    "{$tablealias}.data",
                    $schema,
                    $key,
                    database::generate_param_name(),
                    database::generate_param_name()
                );

                $columns[] = (new column(
                    $identifiers['code'],
                    new lang_string('columnsubitemcode', 'profilefield_repeatable', $label),
                    $this->get_entity_name()
                ))
                    ->add_joins($this->get_joins())
                    ->set_type(column::TYPE_TEXT)
                    ->add_field($codesql, 'code' . $index, $codeparams)
                    ->set_is_sortable(true)
                    ->add_callback(static function (?string $value): string {
                        return s($value);
                    });
            } else if ($schema->pattern_config($key) !== null) {
                // A pattern value is shown as customfield_pattern formats it (as stored without it), then escaped.
                $patternconfig = $schema->pattern_config($key);
                $columns[] = (new column(
                    $identifiers['value'],
                    new lang_string('columnsubitem', 'profilefield_repeatable', $label),
                    $this->get_entity_name()
                ))
                    ->add_joins($this->get_joins())
                    ->set_type(column::TYPE_TEXT)
                    ->add_field($valuesql, 'val' . $index, $valueparams)
                    ->set_is_sortable(true)
                    ->add_callback(static function (?string $value) use ($patternconfig): string {
                        return s(pattern_columns::format($patternconfig, (string)$value));
                    });
            } else {
                $columns[] = (new column(
                    $identifiers['value'],
                    new lang_string('columnsubitem', 'profilefield_repeatable', $label),
                    $this->get_entity_name()
                ))
                    ->add_joins($this->get_joins())
                    ->set_type(column::TYPE_TEXT)
                    ->add_field($valuesql, 'val' . $index, $valueparams)
                    ->set_is_sortable(true)
                    ->add_callback(static function (?string $value): string {
                        return s($value);
                    });
            }
        }

        return $columns;
    }

    /**
     * Build all filters for the schema's columns.
     *
     * @param schema $schema
     * @return filter[]
     */
    protected function get_all_filters(schema $schema): array {
        global $DB;

        $tablealias = $this->get_table_alias('profilefield_repeatable_data');
        $fieldid = (int)$this->field->id;
        $domainmap = $schema->domain_map();
        $filters = [];

        $filters[] = (new filter(
            number::class,
            schema::SETNUMBER_IDENTIFIER,
            new lang_string('setnumber', 'profilefield_repeatable'),
            $this->get_entity_name(),
            "{$tablealias}.set_id"
        ))
            ->add_joins($this->get_joins());

        foreach ($schema->keys() as $key) {
            $label = $this->column_label($schema, $key);
            $identifiers = $schema->report_identifiers($key);
            [$valuesql, $valueparams] = helper::sql_column_value(
                $DB,
                "{$tablealias}.data",
                $schema,
                $key,
                database::generate_param_name(),
                database::generate_param_name()
            );

            if (!isset($identifiers['value'])) {
                $domain = $domainmap[$key] ?? '';

                $filters[] = (new filter(
                    autocomplete::class,
                    $identifiers['name'],
                    new lang_string('columnsubitemname', 'profilefield_repeatable', $label),
                    $this->get_entity_name(),
                    $valuesql,
                    $valueparams
                ))
                    ->add_joins($this->get_joins())
                    ->set_options_callback(static function () use ($fieldid, $schema, $key, $domain): array {
                        return self::name_filter_options($fieldid, $schema, $key, $domain);
                    });

                [$codesql, $codeparams] = helper::sql_column_value(
                    $DB,
                    "{$tablealias}.data",
                    $schema,
                    $key,
                    database::generate_param_name(),
                    database::generate_param_name()
                );

                $filters[] = (new filter(
                    text::class,
                    $identifiers['code'],
                    new lang_string('columnsubitemcode', 'profilefield_repeatable', $label),
                    $this->get_entity_name(),
                    $codesql,
                    $codeparams
                ))
                    ->add_joins($this->get_joins());
            } else {
                $filters[] = (new filter(
                    text::class,
                    $identifiers['value'],
                    new lang_string('columnsubitem', 'profilefield_repeatable', $label),
                    $this->get_entity_name(),
                    $valuesql,
                    $valueparams
                ))
                    ->add_joins($this->get_joins());
            }
        }

        return $filters;
    }

    /**
     * Resolve a stored code to its reference label, falling back to the code.
     *
     * Memoised per domain and code for the request, misses included: the resolver caches only the
     * labels it finds, so an unresolved code would otherwise cost the same queries on every row.
     *
     * @param string $domain Reference domain shortname.
     * @param string|null $code Stored code value.
     * @return string Resolved label, or the raw code when unresolved.
     */
    protected static function resolve_label(string $domain, ?string $code): string {
        $code = trim((string)$code);
        if ($code === '') {
            return '';
        }

        // The newline cannot occur in a domain shortname, so the key has no ambiguity.
        $key = $domain . "\n" . $code;
        if (!array_key_exists($key, self::$labelmemo)) {
            $label = static::lookup_label($domain, $code);
            self::$labelmemo[$key] = ($label !== null && trim($label) !== '') ? $label : $code;
        }

        return self::$labelmemo[$key];
    }

    /**
     * Forget the memoised labels (a test seeds a domain after an earlier lookup missed).
     */
    public static function reset_label_memo(): void {
        self::$labelmemo = [];
    }

    /**
     * Ask the companion dictionary plugin for the label of a code.
     *
     * @param string $domain Reference domain shortname.
     * @param string $code Non-empty stored code value.
     * @return string|null The label, or null when the companion is absent, throws or has none.
     */
    protected static function lookup_label(string $domain, string $code): ?string {
        if (!class_exists('\local_dictionaries\resolver')) {
            return null;
        }

        try {
            $label = \local_dictionaries\resolver::resolve($domain, $code);
        } catch (\Throwable $e) {
            return null;
        }

        return is_string($label) ? $label : null;
    }

    /**
     * Build name-filter options from the codes present in the data, labelled via the resolver.
     *
     * The option keys are the stored codes (so filtering happens on the code), while the
     * displayed labels are the resolved names, falling back to the code when unresolved.
     * Labels are escaped here: core's autocomplete element prints option text unescaped.
     *
     * @param int $fieldid The profile field id.
     * @param schema $schema The field's schema.
     * @param string $key Column key whose codes are listed.
     * @param string $domain Reference domain shortname; '' lists the codes unresolved.
     * @return array<string, string> Map of code to display label.
     */
    protected static function name_filter_options(int $fieldid, schema $schema, string $key, string $domain): array {
        global $DB;

        [$valuesql, $params] = helper::sql_column_value(
            $DB,
            'data',
            $schema,
            $key,
            database::generate_param_name(),
            database::generate_param_name()
        );
        $params['fieldid'] = $fieldid;

        $codes = $DB->get_fieldset_sql(
            "SELECT DISTINCT {$valuesql}
               FROM {profilefield_repeatable_data}
              WHERE fieldid = :fieldid",
            $params
        );

        $cleancodes = [];
        foreach ($codes as $code) {
            $code = trim((string)$code);
            if ($code !== '') {
                $cleancodes[$code] = $code;
            }
        }

        if (empty($cleancodes)) {
            return [];
        }

        $labels = [];
        if ($domain !== '' && class_exists('\local_dictionaries\resolver')) {
            try {
                $labels = \local_dictionaries\resolver::resolve_bulk($domain, array_values($cleancodes));
            } catch (\Throwable $e) {
                $labels = [];
            }
        }

        $options = [];
        foreach ($cleancodes as $code) {
            $label = $labels[$code] ?? null;
            $options[$code] = s((is_string($label) && trim($label) !== '') ? $label : $code);
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }
}
