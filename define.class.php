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

/**
 * Repeatable profile field definition.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine (anderson@blaine.com.br)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Class profile_define_repeatable.
 *
 * @package    profilefield_repeatable
 */
class profile_define_repeatable extends profile_define_base {
    /** @var int Maximum number of indexed sub-items allowed per field. */
    private const MAX_INDEXED_SUBITEMS = 3;

    /** @var int Maximum number of sub-items allowed in the collapsed subline. */
    private const MAX_SUBLINE_SUBITEMS = 3;

    /** @var string Domain shortname pattern for reference mapping. */
    private const DOMAIN_PATTERN = '/^[a-z0-9_]+$/';

    /**
     * Add elements for creating/editing a repeatable profile field.
     *
     * @param MoodleQuickForm $form
     */
    public function define_form_specific($form) {
        $form->addElement('textarea', 'param1', get_string('subitems', 'profilefield_repeatable'), [
            'rows' => 8,
            'cols' => 60,
        ]);
        $form->setType('param1', PARAM_TEXT);
        $form->addHelpButton('param1', 'subitems', 'profilefield_repeatable');

        $form->addElement('textarea', 'param2', get_string('indexedsubitems', 'profilefield_repeatable'), [
            'rows' => 5,
            'cols' => 60,
        ]);
        $form->setType('param2', PARAM_TEXT);
        $form->addHelpButton('param2', 'indexedsubitems', 'profilefield_repeatable');

        $form->addElement('textarea', 'param3', get_string('referencesubitemdomains', 'profilefield_repeatable'), [
            'rows' => 5,
            'cols' => 60,
        ]);
        $form->setType('param3', PARAM_TEXT);
        $form->addHelpButton('param3', 'referencesubitemdomains', 'profilefield_repeatable');

        $form->addElement('textarea', 'param4', get_string('sublinesubitems', 'profilefield_repeatable'), [
            'rows' => 4,
            'cols' => 60,
        ]);
        $form->setType('param4', PARAM_TEXT);
        $form->addHelpButton('param4', 'sublinesubitems', 'profilefield_repeatable');

        $form->addElement('hidden', 'defaultdata', '{}');
        $form->setType('defaultdata', PARAM_RAW_TRIMMED);
    }

    /**
     * Validate repeatable profile field configuration.
     *
     * @param stdClass|array $data
     * @param array $files
     * @return array
     */
    public function define_validate_specific($data, $files) {
        $result = $this->validate_subitems_config($data);
        if (!empty($result['errors'])) {
            return $result['errors'];
        }

        $errors = $this->validate_indexed_subitems_config((string)($data->param2 ?? ''), $result['subitems']);
        if (!empty($errors)) {
            return $errors;
        }

        $errors = $this->validate_reference_mappings_config((string)($data->param3 ?? ''), $result['subitems']);
        if (!empty($errors)) {
            return $errors;
        }

        return $this->validate_subline_subitems_config((string)($data->param4 ?? ''), $result['subitems']);
    }

    /**
     * Validate sub-items configuration (param1).
     *
     * @param object $data
     * @return array
     */
    private function validate_subitems_config(object $data): array {
        $lines = preg_split('/\R/u', (string)($data->param1 ?? '')) ?: [];
        $subitems = [];
        $seen = [];

        foreach ($lines as $line) {
            $subitem = trim($line);
            if ($subitem === '') {
                continue;
            }

            $normalised = core_text::strtolower($subitem);
            if (isset($seen[$normalised])) {
                return [
                    'subitems' => $subitems,
                    'errors' => ['param1' => get_string('errorsubitemsduplicate', 'profilefield_repeatable')],
                ];
            }

            $seen[$normalised] = true;
            $subitems[] = $subitem;
        }

        if (empty($subitems)) {
            return [
                'subitems' => [],
                'errors' => ['param1' => get_string('errorsubitemsrequired', 'profilefield_repeatable')],
            ];
        }

        return ['subitems' => $subitems, 'errors' => []];
    }

    /**
     * Validate indexed sub-items configuration (param2).
     *
     * @param string $raw
     * @param string[] $subitems
     * @return array
     */
    private function validate_indexed_subitems_config(string $raw, array $subitems): array {
        $errors = [];
        $unknown = [];
        $indexedsubitems = $this->parse_indexed_subitems($raw, $subitems, $unknown);

        if (!empty($unknown)) {
            $errors['param2'] = get_string('errorindexedsubitemunknown', 'profilefield_repeatable', implode(', ', $unknown));
        } else if (count($indexedsubitems) > self::MAX_INDEXED_SUBITEMS) {
            $errors['param2'] = get_string('errorindexedsubitemlimit', 'profilefield_repeatable', self::MAX_INDEXED_SUBITEMS);
        }

        return $errors;
    }

    /**
     * Validate subline sub-items configuration (param4).
     *
     * @param string $raw
     * @param string[] $subitems
     * @return array
     */
    private function validate_subline_subitems_config(string $raw, array $subitems): array {
        $errors = [];
        $unknown = [];
        $duplicates = [];
        $sublinesubitems = $this->parse_subline_subitems($raw, $subitems, $unknown, $duplicates);

        if (!empty($unknown)) {
            $errors['param4'] = get_string(
                'errorsublinesubitemunknown',
                'profilefield_repeatable',
                implode(', ', array_values(array_unique($unknown)))
            );
            return $errors;
        }

        if (!empty($duplicates)) {
            $errors['param4'] = get_string(
                'errorsublinesubitemduplicate',
                'profilefield_repeatable',
                implode(', ', array_values(array_unique($duplicates)))
            );
            return $errors;
        }

        if (!empty($sublinesubitems) && !empty($subitems)) {
            $primarynormalised = \core_text::strtolower($subitems[0]);
            foreach ($sublinesubitems as $candidate) {
                if (\core_text::strtolower($candidate) === $primarynormalised) {
                    $errors['param4'] = get_string(
                        'errorsublineincludesprimary',
                        'profilefield_repeatable',
                        $subitems[0]
                    );
                    return $errors;
                }
            }
        }

        if (count($sublinesubitems) > self::MAX_SUBLINE_SUBITEMS) {
            $errors['param4'] = get_string(
                'errorsublinesubitemlimit',
                'profilefield_repeatable',
                self::MAX_SUBLINE_SUBITEMS
            );
        }

        return $errors;
    }

    /**
     * Validate reference domain mappings configuration (param3).
     *
     * @param string $raw
     * @param string[] $subitems
     * @return array
     */
    private function validate_reference_mappings_config(string $raw, array $subitems): array {
        $errors = [];
        $unknownsubitems = [];
        $invaliddomains = [];
        $duplicatesubitems = [];
        $formaterrors = [];
        $referencemappings = $this->parse_reference_mappings(
            $raw,
            $subitems,
            $unknownsubitems,
            $invaliddomains,
            $duplicatesubitems,
            $formaterrors
        );

        if (!empty($formaterrors)) {
            $errors['param3'] = get_string('errordomainmapformat', 'profilefield_repeatable', implode(', ', $formaterrors));
        } else if (!empty($unknownsubitems)) {
            $errors['param3'] = get_string(
                'errordomainmapunknownsubitem',
                'profilefield_repeatable',
                implode(', ', array_values(array_unique($unknownsubitems)))
            );
        } else if (!empty($duplicatesubitems)) {
            $errors['param3'] = get_string(
                'errordomainmapduplicatesubitem',
                'profilefield_repeatable',
                implode(', ', array_values(array_unique($duplicatesubitems)))
            );
        } else if (!empty($invaliddomains)) {
            $errors['param3'] = get_string(
                'errordomainmapinvaliddomain',
                'profilefield_repeatable',
                implode(', ', array_values(array_unique($invaliddomains)))
            );
        } else if (!empty($referencemappings) && $this->is_local_reference_plugin_available()) {
            $missingdomains = $this->get_missing_reference_domains(array_values($referencemappings));
            if (!empty($missingdomains)) {
                $errors['param3'] = get_string(
                    'errordomainmapmissingdomain',
                    'profilefield_repeatable',
                    implode(', ', $missingdomains)
                );
            }
        }

        return $errors;
    }

    /**
     * Preprocess field configuration before save.
     *
     * @param array|stdClass $data
     * @return array|stdClass
     */
    public function define_save_preprocess($data) {
        $subitems = \profilefield_repeatable\helper::parse_subitems((string)($data->param1 ?? ''));
        $data->param1 = implode("\n", $subitems);
        $indexedsubitems = $this->parse_indexed_subitems((string)($data->param2 ?? ''), $subitems);
        $data->param2 = implode("\n", array_slice($indexedsubitems, 0, self::MAX_INDEXED_SUBITEMS));
        $referencemappings = $this->parse_reference_mappings((string)($data->param3 ?? ''), $subitems);
        $data->param3 = $this->encode_reference_mappings($referencemappings, $subitems);
        $sublinesubitems = $this->parse_subline_subitems((string)($data->param4 ?? ''), $subitems);
        $data->param4 = implode("\n", array_slice($sublinesubitems, 0, self::MAX_SUBLINE_SUBITEMS));
        $data->defaultdata = '{}';

        return $data;
    }

    /**
     * Save repeatable field configuration and enqueue index reconciliation.
     *
     * @param array|stdClass $data
     */
    public function define_save($data) {
        global $DB;

        parent::define_save($data);
        if ($DB->get_dbfamily() !== 'postgres' || empty($data->id)) {
            return;
        }

        \profilefield_repeatable\task\reconcile_indexes::schedule_for_field((int)$data->id);
    }

    /**
     * Clean up PostgreSQL indexes after a repeatable field is deleted.
     *
     * Standard Moodle does not call this automatically. The sweep in
     * {@see \profilefield_repeatable\task\reconcile_indexes::execute()} provides
     * resilient cleanup; call this method for immediate removal when integrating
     * with custom deletion workflows.
     *
     * @param int $fieldid
     */
    public static function define_after_delete(int $fieldid): void {
        \profilefield_repeatable\task\reconcile_indexes::drop_all_for_field($fieldid);
    }

    /**
     * Parse indexed sub-items and return canonical labels from param1.
     *
     * @param string $rawindexedsubitems
     * @param string[] $subitems
     * @param array $unknown
     * @return string[]
     */
    private function parse_indexed_subitems(string $rawindexedsubitems, array $subitems, array &$unknown = []): array {
        $unknown = [];
        if (empty($subitems)) {
            return [];
        }

        $canonicalmap = [];
        foreach ($subitems as $subitem) {
            $canonicalmap[core_text::strtolower($subitem)] = $subitem;
        }

        $indexedsubitems = [];
        $seen = [];
        $lines = preg_split('/\R/u', $rawindexedsubitems) ?: [];

        foreach ($lines as $line) {
            $candidate = trim($line);
            if ($candidate === '') {
                continue;
            }

            $normalised = core_text::strtolower($candidate);
            if (isset($seen[$normalised])) {
                continue;
            }
            $seen[$normalised] = true;

            if (!isset($canonicalmap[$normalised])) {
                $unknown[] = $candidate;
                continue;
            }

            $indexedsubitems[] = $canonicalmap[$normalised];
        }

        return $indexedsubitems;
    }

    /**
     * Parse subline sub-items (param4) into canonical labels from param1.
     *
     * @param string $rawsublinesubitems
     * @param string[] $subitems
     * @param array $unknown
     * @param array $duplicates
     * @return string[]
     */
    private function parse_subline_subitems(
        string $rawsublinesubitems,
        array $subitems,
        array &$unknown = [],
        array &$duplicates = []
    ): array {
        $unknown = [];
        $duplicates = [];
        if (empty($subitems)) {
            return [];
        }

        $canonicalmap = [];
        foreach ($subitems as $subitem) {
            $canonicalmap[\core_text::strtolower($subitem)] = $subitem;
        }

        $sublinesubitems = [];
        $seen = [];
        $lines = preg_split('/\R/u', $rawsublinesubitems) ?: [];

        foreach ($lines as $line) {
            $candidate = trim($line);
            if ($candidate === '') {
                continue;
            }

            $normalised = \core_text::strtolower($candidate);
            if (!isset($canonicalmap[$normalised])) {
                $unknown[] = $candidate;
                continue;
            }

            $canonical = $canonicalmap[$normalised];
            if (isset($seen[$normalised])) {
                $duplicates[] = $canonical;
                continue;
            }
            $seen[$normalised] = true;

            $sublinesubitems[] = $canonical;
        }

        return $sublinesubitems;
    }

    /**
     * Parse sub-item/domain mapping definitions (subitem|domain).
     *
     * @param string $rawmappings
     * @param string[] $subitems
     * @param array $unknownsubitems
     * @param array $invaliddomains
     * @param array $duplicatesubitems
     * @param array $formaterrors
     * @return array
     */
    private function parse_reference_mappings(
        string $rawmappings,
        array $subitems,
        array &$unknownsubitems = [],
        array &$invaliddomains = [],
        array &$duplicatesubitems = [],
        array &$formaterrors = []
    ): array {
        $unknownsubitems = [];
        $invaliddomains = [];
        $duplicatesubitems = [];
        $formaterrors = [];

        if (empty($subitems)) {
            return [];
        }

        $canonicalmap = [];
        foreach ($subitems as $subitem) {
            $canonicalmap[core_text::strtolower($subitem)] = $subitem;
        }

        $mappings = [];
        $lines = preg_split('/\R/u', $rawmappings) ?: [];
        foreach ($lines as $line) {
            $error = $this->parse_single_mapping_line($line, $canonicalmap, $mappings);
            if ($error === null) {
                continue;
            }

            switch ($error['category']) {
                case 'format':
                    $formaterrors[] = $error['value'];
                    break;
                case 'unknown':
                    $unknownsubitems[] = $error['value'];
                    break;
                case 'duplicate':
                    $duplicatesubitems[] = $error['value'];
                    break;
                case 'invalid':
                    $invaliddomains[] = $error['value'];
                    break;
                default:
                    break;
            }
        }

        return $mappings;
    }

    /**
     * Parse a single mapping line and either add to mappings or return an error descriptor.
     *
     * @param string $line
     * @param array $canonicalmap
     * @param array $mappings
     * @return array|null Null on success or skip, error descriptor on failure.
     */
    private function parse_single_mapping_line(string $line, array $canonicalmap, array &$mappings): ?array {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        $validformat = (substr_count($line, '|') === 1);
        if ($validformat) {
            [$rawsubitem, $rawdomain] = array_map('trim', explode('|', $line, 2));
            $validformat = ($rawsubitem !== '' && $rawdomain !== '');
        }
        if (!$validformat) {
            return ['category' => 'format', 'value' => $line];
        }

        $normalisedsubitem = core_text::strtolower($rawsubitem);
        $canonicalsubitem = $canonicalmap[$normalisedsubitem] ?? null;
        $domain = core_text::strtolower($rawdomain);

        $error = null;
        if ($canonicalsubitem === null) {
            $error = ['category' => 'unknown', 'value' => $rawsubitem];
        } else if (isset($mappings[$canonicalsubitem])) {
            $error = ['category' => 'duplicate', 'value' => $canonicalsubitem];
        } else if (!preg_match(self::DOMAIN_PATTERN, $domain)) {
            $error = ['category' => 'invalid', 'value' => $rawdomain];
        } else {
            $mappings[$canonicalsubitem] = $domain;
        }

        return $error;
    }

    /**
     * Encode mappings preserving sub-item declaration order.
     *
     * @param array $mappings
     * @param string[] $subitems
     * @return string
     */
    private function encode_reference_mappings(array $mappings, array $subitems): string {
        if (empty($mappings) || empty($subitems)) {
            return '';
        }

        $lines = [];
        foreach ($subitems as $subitem) {
            if (!isset($mappings[$subitem])) {
                continue;
            }
            $lines[] = $subitem . '|' . $mappings[$subitem];
        }

        return implode("\n", $lines);
    }

    /**
     * Return whether local reference plugin is present.
     *
     * @return bool
     */
    private function is_local_reference_plugin_available(): bool {
        global $DB;

        if (!class_exists('\local_profilefield_repeatable\Resolver')) {
            return false;
        }

        return $DB->get_manager()->table_exists(new xmldb_table('local_profilefield_repeatable_domain'));
    }

    /**
     * Return missing domain shortnames for current mapping.
     *
     * @param string[] $domains
     * @return string[]
     */
    private function get_missing_reference_domains(array $domains): array {
        global $DB;

        $domains = array_values(array_unique(array_map(
            static fn(string $domain): string => core_text::strtolower(trim($domain)),
            $domains
        )));
        if (empty($domains)) {
            return [];
        }

        if (!$DB->get_manager()->table_exists(new xmldb_table('local_profilefield_repeatable_domain'))) {
            return $domains;
        }

        [$insql, $params] = $DB->get_in_or_equal($domains, SQL_PARAMS_NAMED, 'domain');
        $existing = $DB->get_fieldset_select(
            'local_profilefield_repeatable_domain',
            'shortname',
            "shortname $insql",
            $params
        );

        $existing = array_values(array_unique(array_map(
            static fn(string $domain): string => core_text::strtolower(trim($domain)),
            $existing
        )));

        return array_values(array_diff($domains, $existing));
    }
}
