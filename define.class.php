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
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Class profile_define_repeatable.
 *
 * @package    profilefield_repeatable
 */
class profile_define_repeatable extends profile_define_base {
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
        $errors = [];

        $subitems = $this->parse_subitems((string)($data->param1 ?? ''));
        if (empty($subitems)) {
            $errors['param1'] = get_string('errorsubitemsrequired', 'profilefield_repeatable');
            return $errors;
        }

        $seen = [];
        foreach ($subitems as $subitem) {
            $normalised = core_text::strtolower($subitem);
            if (isset($seen[$normalised])) {
                $errors['param1'] = get_string('errorsubitemsduplicate', 'profilefield_repeatable');
                break;
            }
            $seen[$normalised] = true;
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
        $subitems = $this->parse_subitems((string)($data->param1 ?? ''));
        $data->param1 = implode("\n", $subitems);
        $data->defaultdata = '{}';

        return $data;
    }

    /**
     * Parse textarea content with one sub-item per line.
     *
     * @param string $rawsubitems
     * @return string[]
     */
    private function parse_subitems(string $rawsubitems): array {
        $lines = preg_split('/\R/u', $rawsubitems) ?: [];
        $subitems = [];

        foreach ($lines as $line) {
            $subitem = trim($line);
            if ($subitem === '') {
                continue;
            }
            $subitems[] = $subitem;
        }

        return $subitems;
    }
}
