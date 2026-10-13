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

namespace profilefield_repeatable\local;

/**
 * Tests for the bridge to customfield_pattern.
 *
 * Metadata stays in docblocks: the plugin supports Moodle 4.5, whose moodle-cs cannot read
 * PHPUnit attributes. The tests that need customfield_pattern skip without it, and the ones that
 * describe its absence skip with it: each CI leg runs one half (customfield_pattern installs on
 * Moodle 5.1 and later only).
 *
 * @package    profilefield_repeatable
 * @category   test
 * @covers     \profilefield_repeatable\local\pattern_columns
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pattern_columns_test extends \advanced_testcase {
    /**
     * A column's configuration keeps the known settings that are scalars, as trimmed strings.
     */
    public function test_config(): void {
        $this->assertSame(
            ['preset' => 'custom', 'mask' => 'AA-99', 'regex' => '1', 'storeformat' => 'raw'],
            pattern_columns::config(['mode' => 'pattern', 'pattern' => [
                'storeformat' => 'raw',
                'preset' => ' custom ',
                'mask' => 'AA-99',
                'regex' => 1,
                'regexerror' => ['not', 'a', 'string'],
                'future' => 'ignored',
            ]])
        );
        $this->assertSame([], pattern_columns::config(['mode' => 'pattern']));
        $this->assertSame([], pattern_columns::config(['mode' => 'pattern', 'pattern' => 'cpf']));
    }

    /**
     * Without customfield_pattern nothing is checked, changed or formatted.
     */
    public function test_without_the_plugin(): void {
        if (pattern_columns::available()) {
            $this->markTestSkipped('customfield_pattern is installed; its absence is tested on Moodle 4.5 and 5.0.');
        }
        $config = ['preset' => 'cpf', 'storeformat' => 'raw'];

        $this->assertNull(pattern_columns::validate($config, '529.982.247-24'));
        $this->assertSame('529.982.247-25', pattern_columns::normalise($config, '529.982.247-25'));
        $this->assertSame('52998224725', pattern_columns::format($config, '52998224725'));
        $this->assertSame([], pattern_columns::input_attributes($config));
        $this->assertSame([], pattern_columns::config_errors(['preset' => 'nonsense']));
        $this->assertSame([], pattern_columns::presets());
    }

    /**
     * With customfield_pattern every call is its API's answer.
     */
    public function test_with_the_plugin(): void {
        if (!pattern_columns::available()) {
            $this->markTestSkipped('customfield_pattern with its public API is not installed.');
        }
        $config = ['preset' => 'cpf', 'storeformat' => 'raw'];

        $this->assertSame(get_string('errorcpf', 'customfield_pattern'), pattern_columns::validate($config, '529.982.247-24'));
        $this->assertNull(pattern_columns::validate($config, '529.982.247-25'));
        $this->assertSame('52998224725', pattern_columns::normalise($config, '529.982.247-25'));
        $this->assertSame('529.982.247-25', pattern_columns::format($config, '52998224725'));
        $this->assertSame('999.999.999-99', pattern_columns::input_attributes($config)['data-customfield-pattern-mask']);
        $this->assertArrayHasKey('preset', pattern_columns::config_errors(['preset' => 'nonsense']));

        $presets = array_column(pattern_columns::presets(), null, 'value');
        $this->assertSame(['none', 'cpf', 'cnpj', 'cep', 'phone_br', 'custom'], array_keys($presets));
        $this->assertSame('999.999.999-99', $presets['cpf']['mask']);
        $this->assertSame('(99) 9999-9999|(99) 99999-9999', $presets['phone_br']['mask']);
        $this->assertSame('', $presets['custom']['mask']);
        $this->assertSame(get_string('presetcpf', 'customfield_pattern'), $presets['cpf']['label']);
    }

    /**
     * The bridge asks for the configuration version this field writes.
     */
    public function test_config_version_matches(): void {
        if (!class_exists(pattern_columns::API)) {
            $this->markTestSkipped('customfield_pattern with its public API is not installed.');
        }
        $this->assertSame(pattern_columns::CONFIG_VERSION, \customfield_pattern\api::CONFIG_VERSION);
        $this->assertTrue(pattern_columns::available());
    }
}
