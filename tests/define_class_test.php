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

namespace profilefield_repeatable;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->dirroot . '/user/profile/definelib.php');
require_once($CFG->dirroot . '/user/profile/field/repeatable/define.class.php');
require_once($CFG->dirroot . '/user/profile/field/repeatable/field.class.php');
require_once($CFG->libdir . '/formslib.php');

/**
 * Tests for profile_define_repeatable.
 *
 * The schema rules themselves are tested in {@see \profilefield_repeatable\local\schema_test}; this
 * covers how the definition form reads, reports and saves them.
 *
 * @package    profilefield_repeatable
 * @covers     \profile_define_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class define_class_test extends \advanced_testcase {
    /**
     * The data generator of this plugin.
     *
     * @return \profilefield_repeatable_generator
     */
    private function generator(): \profilefield_repeatable_generator {
        return $this->getDataGenerator()->get_plugin_generator('profilefield_repeatable');
    }

    /**
     * Every broken rule is reported under param1, one per line.
     */
    public function test_define_validate_specific_lists_every_error_under_param1(): void {
        $this->resetAfterTest();
        $define = new \profile_define_repeatable();

        $errors = $define->define_validate_specific((object)[
            'param1' => json_encode(['version' => 2, 'columns' => [
                ['key' => 'name', 'label' => 'Name', 'mode' => 'free'],
                ['key' => 'Bad', 'label' => 'Role', 'mode' => 'pattern'],
            ]]),
        ], []);

        $this->assertSame([
            'param1' => implode('<br>', [
                get_string('errorkeyinvalid', 'profilefield_repeatable', 'Bad'),
                get_string('errormodeinvalid', 'profilefield_repeatable', 'Role'),
                get_string('errortitlecount', 'profilefield_repeatable'),
            ]),
        ], $errors);
    }

    /**
     * Version 1 text and an empty value are not a schema.
     */
    public function test_define_validate_specific_rejects_text_that_is_not_a_schema(): void {
        $this->resetAfterTest();
        $define = new \profile_define_repeatable();
        $expected = ['param1' => get_string('errorschemainvalid', 'profilefield_repeatable')];

        $this->assertSame($expected, $define->define_validate_specific((object)['param1' => "name\nrole"], []));
        $this->assertSame($expected, $define->define_validate_specific(['param1' => ''], []));
    }

    /**
     * A valid schema passes.
     */
    public function test_define_validate_specific_accepts_a_valid_schema(): void {
        $this->resetAfterTest();
        $define = new \profile_define_repeatable();

        $this->assertSame([], $define->define_validate_specific((object)[
            'param1' => $this->generator()->schema_json(['Name', ['label' => 'Role', 'required' => true, 'indexed' => true]]),
        ], []));
    }

    /**
     * With the companion installed, a dictionary column must name an existing domain.
     */
    public function test_define_validate_specific_checks_domains_with_the_companion(): void {
        if (!class_exists('\local_dictionaries\resolver')) {
            $this->markTestSkipped('local_dictionaries is not installed.');
        }
        $this->resetAfterTest();
        $this->getDataGenerator()->get_plugin_generator('local_dictionaries')
            ->create_domain(['shortname' => 'diretorias', 'name' => 'Diretorias']);
        $define = new \profile_define_repeatable();
        $schema = fn(string $domain): string => $this->generator()->schema_json([
            ['label' => 'Diretoria', 'mode' => 'dictionary', 'domain' => $domain],
        ]);

        $this->assertSame([], $define->define_validate_specific((object)['param1' => $schema('diretorias')], []));
        $this->assertSame(
            ['param1' => get_string('errordomainmissing', 'profilefield_repeatable', 'escolas')],
            $define->define_validate_specific((object)['param1' => $schema('escolas')], [])
        );
    }

    /**
     * Saving rewrites param1 canonically and clears the version 1 parameters.
     */
    public function test_define_save_preprocess_canonicalises_and_clears_v1_params(): void {
        $define = new \profile_define_repeatable();

        $processed = $define->define_save_preprocess((object)[
            'param1' => "{\n  \"version\": 2,\n  \"columns\": [{\"label\": \" Name \", \"key\": \"name\", \"title\": 1,"
                . " \"mode\": \"free\", \"domain\": \"x\"}]\n}",
            'param2' => 'Name',
            'param3' => 'Name|names',
            'param4' => 'Name',
            'defaultdata' => 'x',
        ]);

        $this->assertSame('{"version":2,"columns":[{"key":"name","label":"Name","mode":"free","title":true}]}', $processed->param1);
        $this->assertSame('', $processed->param2);
        $this->assertSame('', $processed->param3);
        $this->assertSame('', $processed->param4);
        $this->assertSame('{}', $processed->defaultdata);
    }
}
