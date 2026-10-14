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

use local_repeatable\local\bridge\pattern;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->dirroot . '/user/profile/field/repeatable/field.class.php');
require_once($CFG->dirroot . '/user/profile/definelib.php');
require_once($CFG->dirroot . '/user/profile/field/repeatable/define.class.php');

/**
 * Pattern columns (stage C) on the edit form, the definition form and the profile display.
 *
 * Metadata stays in docblocks: the plugin supports Moodle 4.5, whose moodle-cs cannot read
 * PHPUnit attributes. Tests that need customfield_pattern skip without it; the absence of the
 * plugin is tested through the field's and the definition's pattern_available() seam, which
 * runs on every leg, and the display's through the plugin really being absent.
 *
 * @package    profilefield_repeatable
 * @covers     \profile_field_repeatable
 * @covers     \profilefield_repeatable\form\widget
 * @covers     \profile_define_repeatable
 * @covers     \profilefield_repeatable\form\column_editor
 * @covers     \profilefield_repeatable\output\display_renderer
 * @covers     \profilefield_repeatable\output\sets_renderer
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pattern_field_test extends \advanced_testcase {
    /** @var string A valid CPF, masked. */
    private const CPF = '529.982.247-25';

    /** @var string The same CPF, digits only. */
    private const CPF_RAW = '52998224725';

    /**
     * Skip a test that needs customfield_pattern without it.
     *
     * @return void
     */
    private function require_plugin(): void {
        if (!pattern::available()) {
            $this->markTestSkipped('customfield_pattern with its public API is not installed (Moodle 5.1 or later).');
        }
    }

    /**
     * The columns of the test field: a free title, a CPF stored raw, a CPF stored masked, a regex.
     *
     * @return array
     */
    private function columns(): array {
        return [
            ['key' => 'name', 'label' => 'Name', 'title' => true],
            ['key' => 'cpf', 'label' => 'CPF', 'mode' => 'pattern', 'pattern' => ['preset' => 'cpf', 'storeformat' => 'raw']],
            ['key' => 'cpfmasked', 'label' => 'Masked CPF', 'mode' => 'pattern', 'pattern' => ['preset' => 'cpf']],
            ['key' => 'code', 'label' => 'Code', 'mode' => 'pattern',
                'pattern' => ['regex' => '[A-Z]{3}', 'regexerror' => 'Three capitals & nothing else']],
        ];
    }

    /**
     * Create the test field.
     *
     * @return \stdClass The field record.
     */
    private function create_field_record(): \stdClass {
        return $this->getDataGenerator()->get_plugin_generator('profilefield_repeatable')->create_field($this->columns());
    }

    /**
     * A field instance as if customfield_pattern were absent.
     *
     * @param \stdClass $record
     * @param int $userid
     * @return \profile_field_repeatable
     */
    private function field_without_plugin(\stdClass $record, int $userid = 0): \profile_field_repeatable {
        return new class ((int)$record->id, $userid) extends \profile_field_repeatable {
            /**
             * Customfield_pattern is absent.
             *
             * @return bool
             */
            protected function pattern_available(): bool {
                return false;
            }
        };
    }

    /**
     * Validate one payload of sets.
     *
     * @param \profile_field_repeatable $field
     * @param array $sets List of sets (key => value).
     * @return array Errors from edit_validate_field().
     */
    private function validate(\profile_field_repeatable $field, array $sets): array {
        return $field->edit_validate_field((object)['id' => (int)$field->userid, $field->inputname => $this->payload($sets)]);
    }

    /**
     * The JSON payload of some sets.
     *
     * @param array $sets List of sets (key => value).
     * @return string
     */
    private function payload(array $sets): string {
        $payload = [];
        foreach (array_values($sets) as $index => $set) {
            $payload['@@ID#' . ($index + 1)] = $set;
        }
        return json_encode($payload);
    }

    /**
     * The payload stored for a user.
     *
     * @param \stdClass $record The field record.
     * @param int $userid
     * @return array
     */
    private function stored(\stdClass $record, int $userid): array {
        global $DB;
        return json_decode($DB->get_field('user_info_data', 'data', ['fieldid' => $record->id, 'userid' => $userid]), true);
    }

    /**
     * Render the test edit form around a field.
     *
     * @param \profile_field_repeatable $field
     * @return string The form's HTML.
     */
    private function render_form(\profile_field_repeatable $field): string {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/user/profile/field/repeatable/tests/fixtures/edit_form.php');

        $PAGE->set_url('/user/edit.php');
        return (new \profilefield_repeatable_test_edit_form(null, ['field' => $field]))->render();
    }

    /**
     * The columns the edit form hands the widget, by key.
     *
     * @param string $html
     * @return array
     */
    private function widget_columns(string $html): array {
        $this->assertSame(1, preg_match('~data-columns="([^"]*)"~', $html, $matches));
        return array_column(json_decode(html_entity_decode($matches[1]), true), null, 'key');
    }

    /**
     * Whether the page asked for customfield_pattern's formatter.
     *
     * @return bool
     */
    private function formatter_required(): bool {
        global $PAGE;
        $amdjscode = (new \ReflectionProperty($PAGE->requires, 'amdjscode'))->getValue($PAGE->requires);
        return str_contains(implode('', $amdjscode), "require(['customfield_pattern/mask']");
    }

    /**
     * Values are stored as the column's "Store as" says, and edited with their mask.
     */
    public function test_values_are_stored_raw_or_masked(): void {
        $this->require_plugin();
        $this->resetAfterTest();
        $record = $this->create_field_record();
        $user = $this->getDataGenerator()->create_user();
        $field = new \profile_field_repeatable((int)$record->id, (int)$user->id);

        $field->edit_save_data((object)[
            'id' => $user->id,
            $field->inputname => $this->payload([['name' => 'A', 'cpf' => self::CPF, 'cpfmasked' => ' ' . self::CPF_RAW]]),
        ]);

        $stored = $this->stored($record, (int)$user->id);
        $this->assertSame(self::CPF_RAW, $stored['@@ID#1']['cpf']);
        $this->assertSame(self::CPF, $stored['@@ID#1']['cpfmasked']);

        // The edit form shows the raw value with its mask; saving it again stores it raw again.
        $loaded = new \stdClass();
        (new \profile_field_repeatable((int)$record->id, (int)$user->id))->edit_load_user_data($loaded);
        $this->assertSame(self::CPF, json_decode($loaded->{$field->inputname}, true)['@@ID#1']['cpf']);
    }

    /**
     * The uniqueness check compares the values as they would be stored.
     */
    public function test_validation_hands_the_stored_shape_to_the_uniqueness_check(): void {
        $this->require_plugin();
        $this->resetAfterTest();
        $field = new \profile_field_repeatable((int)$this->create_field_record()->id, 0);
        $usernew = (object)['id' => 0, $field->inputname => $this->payload([['cpf' => self::CPF]])];

        $this->assertSame([], $field->edit_validate_field($usernew));
        $this->assertSame(self::CPF_RAW, json_decode($usernew->{$field->inputname}, true)['@@ID#1']['cpf']);
    }

    /**
     * Pattern inputs carry the plugin's attributes, and the page loads its formatter.
     */
    public function test_edit_form_gives_pattern_inputs_their_attributes(): void {
        $this->require_plugin();
        $this->resetAfterTest();
        $this->setAdminUser();

        $field = new \profile_field_repeatable((int)$this->create_field_record()->id, 0);
        $columns = $this->widget_columns($this->render_form($field));

        $this->assertSame([
            'maxlength' => 14,
            'placeholder' => '999.999.999-99',
            'data-customfield-pattern-mask' => '999.999.999-99',
            'inputmode' => 'numeric',
        ], $columns['cpf']['inputattributes']);
        $this->assertArrayNotHasKey('data-customfield-pattern-mask', $columns['code']['inputattributes']);
        $this->assertArrayNotHasKey('inputattributes', $columns['name'], 'A free column');
        $this->assertTrue($this->formatter_required());
    }

    /**
     * Without the plugin a pattern column is plain text: nothing refused, changed or loaded.
     */
    public function test_without_the_plugin_values_are_kept_as_typed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $record = $this->create_field_record();
        $user = $this->getDataGenerator()->create_user();
        $field = $this->field_without_plugin($record, (int)$user->id);

        $this->assertSame([], $this->validate($field, [['cpf' => '529.982.247-24', 'code' => 'abc']]));
        $field->edit_save_data((object)[
            'id' => $user->id,
            $field->inputname => $this->payload([['cpf' => self::CPF, 'cpfmasked' => self::CPF_RAW]]),
        ]);
        $stored = $this->stored($record, (int)$user->id);
        $this->assertSame(self::CPF, $stored['@@ID#1']['cpf']);
        $this->assertSame(self::CPF_RAW, $stored['@@ID#1']['cpfmasked']);

        $columns = $this->widget_columns($this->render_form($this->field_without_plugin($record)));
        $this->assertArrayNotHasKey('inputattributes', $columns['cpf']);
        $this->assertFalse($this->formatter_required());
    }

    /**
     * The profile shows a raw-stored value with its mask, escaped.
     */
    public function test_display_formats_pattern_values(): void {
        global $SCRIPT;
        $this->require_plugin();
        $this->resetAfterTest();
        $field = new \profile_field_repeatable((int)$this->create_field_record()->id, 0);
        $field->data = $this->payload([['name' => 'A', 'cpf' => self::CPF_RAW, 'code' => '<b>']]);

        $originalscript = $SCRIPT ?? null;
        try {
            $SCRIPT = '/user/edit.php';
            $output = $field->display_data();
        } finally {
            $SCRIPT = $originalscript;
        }

        $this->assertStringContainsString(self::CPF, $output);
        $this->assertStringNotContainsString(self::CPF_RAW, $output);
        $this->assertStringContainsString('&lt;b&gt;', $output);
    }

    /**
     * Without the plugin the profile shows the value as stored.
     */
    public function test_display_without_the_plugin_shows_the_stored_value(): void {
        global $SCRIPT;
        if (pattern::available()) {
            $this->markTestSkipped('customfield_pattern is installed; its absence is tested on Moodle 4.5 and 5.0.');
        }
        $this->resetAfterTest();
        $field = new \profile_field_repeatable((int)$this->create_field_record()->id, 0);
        $field->data = $this->payload([['name' => 'A', 'cpf' => self::CPF_RAW, 'code' => '<b>x</b>']]);

        $originalscript = $SCRIPT ?? null;
        try {
            $SCRIPT = '/user/edit.php';
            $output = $field->display_data();
        } finally {
            $SCRIPT = $originalscript;
        }

        $this->assertStringContainsString(self::CPF_RAW, $output);
        $this->assertStringNotContainsString(self::CPF, $output);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $output);
        $this->assertStringNotContainsString('<b>x', $output);
    }

    /**
     * With the plugin the definition checks each pattern column's configuration.
     */
    public function test_define_checks_the_configuration_with_the_plugin(): void {
        $this->require_plugin();
        $this->resetAfterTest();
        $define = new \profile_define_repeatable();
        $schema = fn(array $pattern): string => $this->getDataGenerator()->get_plugin_generator('profilefield_repeatable')
            ->schema_json(['Name', ['label' => 'Doc', 'mode' => 'pattern', 'pattern' => $pattern]]);

        $this->assertSame([], $define->define_validate_specific((object)['param1' => $schema(['preset' => 'cpf'])], []));
        $this->assertSame(['param1' => get_string('errorpatternconfig', 'local_repeatable', (object)[
            'column' => 'Doc',
            'error' => get_string('errorconfigmaskrequired', 'customfield_pattern'),
        ])], $define->define_validate_specific((object)['param1' => $schema(['preset' => 'custom'])], []));
    }

    /**
     * Without the plugin the definition keeps the stored pattern columns and refuses new ones.
     */
    public function test_define_without_the_plugin(): void {
        $this->resetAfterTest();
        $record = $this->create_field_record();
        $define = new class extends \profile_define_repeatable {
            /**
             * Customfield_pattern is absent.
             *
             * @return bool
             */
            protected function pattern_available(): bool {
                return false;
            }
        };
        $validate = fn(array $columns, int $id): array => $define->define_validate_specific((object)[
            'id' => $id,
            'param1' => $this->getDataGenerator()->get_plugin_generator('profilefield_repeatable')->schema_json($columns),
        ], []);
        $columns = $this->columns();

        $this->assertSame([], $validate($columns, (int)$record->id), 'The stored schema, saved again');

        $columns[] = ['key' => 'cep', 'label' => 'CEP', 'mode' => 'pattern', 'pattern' => ['preset' => 'cep']];
        $this->assertSame(
            ['param1' => get_string('errorpatternunavailable', 'local_repeatable', 'CEP')],
            $validate($columns, (int)$record->id),
            'A new pattern column'
        );
        $unavailable = array_map(
            static fn(string $name): string => get_string('errorpatternunavailable', 'local_repeatable', $name),
            ['CPF', 'Masked CPF', 'Code', 'CEP']
        );
        $this->assertSame(['param1' => implode('<br>', $unavailable)], $validate($columns, 0), 'A new field has no stored column');

        $changed = $this->columns();
        $changed[1]['pattern']['storeformat'] = 'masked';
        $this->assertSame(
            ['param1' => get_string('errorpatternunavailable', 'local_repeatable', 'CPF')],
            $validate($changed, (int)$record->id),
            'A changed configuration'
        );
    }

    /**
     * The column editor is told whether pattern columns can be configured, and the presets.
     */
    public function test_define_after_data_tells_the_editor_about_patterns(): void {
        $this->resetAfterTest();
        $record = $this->create_field_record();
        $mform = new \MoodleQuickForm('profilefield', 'post', '');
        $mform->addElement('hidden', 'id', $record->id);
        $mform->setType('id', PARAM_INT);
        $define = new \profile_define_repeatable();
        $define->define_form_specific($mform);
        $mform->setDefault('param1', $record->param1);
        $define->define_after_data($mform);
        $element = $mform->getElement('param1');

        $available = pattern::available();
        $this->assertSame($available ? '1' : '0', (string)$element->getAttribute('data-pattern'));
        $presets = json_decode($element->getAttribute('data-presets'), true);
        $this->assertSame($available ? pattern::presets() : [], $presets);
        // The stored configuration reaches the editor as it is.
        $this->assertSame(
            ['preset' => 'cpf', 'storeformat' => 'raw'],
            json_decode($element->getValue(), true)['columns'][1]['pattern']
        );
    }
}
