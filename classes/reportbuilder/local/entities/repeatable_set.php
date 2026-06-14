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

/**
 * Report Builder entity exposing one repeatable profile field as one row per set.
 *
 * Each configured sub-item becomes a column and a filter; sub-items mapped to a
 * reference domain (param3) additionally expose a resolved-name column plus a
 * name filter whose options are the labels present in the data (filtering on the
 * stored code). The owning datasource joins the storage table per field so a user
 * with N sets yields N rows.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine (anderson@blaine.com.br)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repeatable_set extends base {
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
        $subitems = helper::parse_subitems((string)($this->field->param1 ?? ''));
        $domainmap = helper::parse_subitem_domain_map((string)($this->field->param3 ?? ''), $subitems);

        foreach ($this->get_all_columns($subitems, $domainmap) as $column) {
            $this->add_column($column);
        }

        foreach ($this->get_all_filters($subitems, $domainmap) as $filter) {
            $this->add_filter($filter);
        }

        return $this;
    }

    /**
     * Build all columns for the configured sub-items.
     *
     * @param string[] $subitems Canonical configured sub-items.
     * @param array $domainmap Map of sub-item to reference domain (canonical sub-item => domain).
     * @return column[]
     */
    protected function get_all_columns(array $subitems, array $domainmap): array {
        global $DB;

        $tablealias = $this->get_table_alias('profilefield_repeatable_data');
        $columns = [];

        $columns[] = (new column(
            'setnumber',
            new lang_string('setnumber', 'profilefield_repeatable'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("{$tablealias}.set_id")
            ->set_is_sortable(true);

        $index = 0;
        foreach ($subitems as $subitem) {
            $index++;
            [$valuesql, $valueparams] = helper::sql_subitem_value(
                $DB,
                "{$tablealias}.data",
                $subitem,
                database::generate_param_name()
            );

            if (isset($domainmap[$subitem])) {
                $domain = $domainmap[$subitem];

                $columns[] = (new column(
                    'subitemname' . $index,
                    new lang_string('columnsubitem', 'profilefield_repeatable', $subitem),
                    $this->get_entity_name()
                ))
                    ->add_joins($this->get_joins())
                    ->set_type(column::TYPE_TEXT)
                    ->add_field($valuesql, 'val' . $index, $valueparams)
                    ->set_is_sortable(true)
                    ->add_callback(static function (?string $value) use ($domain): string {
                        return self::resolve_label($domain, $value);
                    });

                [$codesql, $codeparams] = helper::sql_subitem_value(
                    $DB,
                    "{$tablealias}.data",
                    $subitem,
                    database::generate_param_name()
                );

                $columns[] = (new column(
                    'subitemcode' . $index,
                    new lang_string('columnsubitemcode', 'profilefield_repeatable', $subitem),
                    $this->get_entity_name()
                ))
                    ->add_joins($this->get_joins())
                    ->set_type(column::TYPE_TEXT)
                    ->add_field($codesql, 'code' . $index, $codeparams)
                    ->set_is_sortable(true);
            } else {
                $columns[] = (new column(
                    'subitem' . $index,
                    new lang_string('columnsubitem', 'profilefield_repeatable', $subitem),
                    $this->get_entity_name()
                ))
                    ->add_joins($this->get_joins())
                    ->set_type(column::TYPE_TEXT)
                    ->add_field($valuesql, 'val' . $index, $valueparams)
                    ->set_is_sortable(true);
            }
        }

        return $columns;
    }

    /**
     * Build all filters for the configured sub-items.
     *
     * @param string[] $subitems Canonical configured sub-items.
     * @param array $domainmap Map of sub-item to reference domain (canonical sub-item => domain).
     * @return filter[]
     */
    protected function get_all_filters(array $subitems, array $domainmap): array {
        global $DB;

        $tablealias = $this->get_table_alias('profilefield_repeatable_data');
        $fieldid = (int)$this->field->id;
        $filters = [];

        $filters[] = (new filter(
            number::class,
            'setnumber',
            new lang_string('setnumber', 'profilefield_repeatable'),
            $this->get_entity_name(),
            "{$tablealias}.set_id"
        ))
            ->add_joins($this->get_joins());

        $index = 0;
        foreach ($subitems as $subitem) {
            $index++;
            [$valuesql, $valueparams] = helper::sql_subitem_value(
                $DB,
                "{$tablealias}.data",
                $subitem,
                database::generate_param_name()
            );

            if (isset($domainmap[$subitem])) {
                $domain = $domainmap[$subitem];

                $filters[] = (new filter(
                    autocomplete::class,
                    'subitemname' . $index,
                    new lang_string('columnsubitemname', 'profilefield_repeatable', $subitem),
                    $this->get_entity_name(),
                    $valuesql,
                    $valueparams
                ))
                    ->add_joins($this->get_joins())
                    ->set_options_callback(static function () use ($fieldid, $subitem, $domain): array {
                        return self::name_filter_options($fieldid, $subitem, $domain);
                    });

                [$codesql, $codeparams] = helper::sql_subitem_value(
                    $DB,
                    "{$tablealias}.data",
                    $subitem,
                    database::generate_param_name()
                );

                $filters[] = (new filter(
                    text::class,
                    'subitemcode' . $index,
                    new lang_string('columnsubitemcode', 'profilefield_repeatable', $subitem),
                    $this->get_entity_name(),
                    $codesql,
                    $codeparams
                ))
                    ->add_joins($this->get_joins());
            } else {
                $filters[] = (new filter(
                    text::class,
                    'subitem' . $index,
                    new lang_string('columnsubitem', 'profilefield_repeatable', $subitem),
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
     * @param string $domain Reference domain shortname.
     * @param string|null $code Stored code value.
     * @return string Resolved label, or the raw code when unresolved.
     */
    protected static function resolve_label(string $domain, ?string $code): string {
        $code = trim((string)$code);
        if ($code === '') {
            return '';
        }

        if (!class_exists('\local_profilefield_repeatable\resolver')) {
            return $code;
        }

        try {
            $label = \local_profilefield_repeatable\resolver::resolve($domain, $code);
        } catch (\Throwable $e) {
            $label = null;
        }

        return (is_string($label) && trim($label) !== '') ? $label : $code;
    }

    /**
     * Build name-filter options from the codes present in the data, labelled via the resolver.
     *
     * The option keys are the stored codes (so filtering happens on the code), while the
     * displayed labels are the resolved names, falling back to the code when unresolved.
     *
     * @param int $fieldid The profile field id.
     * @param string $subitem Sub-item key whose codes are listed.
     * @param string $domain Reference domain shortname.
     * @return array<string, string> Map of code to display label.
     */
    protected static function name_filter_options(int $fieldid, string $subitem, string $domain): array {
        global $DB;

        [$valuesql, $params] = helper::sql_subitem_value($DB, 'data', $subitem, database::generate_param_name());
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
        if (class_exists('\local_profilefield_repeatable\resolver')) {
            try {
                $labels = \local_profilefield_repeatable\resolver::resolve_bulk($domain, array_values($cleancodes));
            } catch (\Throwable $e) {
                $labels = [];
            }
        }

        $options = [];
        foreach ($cleancodes as $code) {
            $label = $labels[$code] ?? null;
            $options[$code] = (is_string($label) && trim($label) !== '') ? $label : $code;
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }
}
