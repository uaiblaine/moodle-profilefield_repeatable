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

use local_repeatable\local\bridge\dictionaries;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->dirroot . '/user/profile/field/repeatable/field.class.php');
require_once($CFG->dirroot . '/user/profile/definelib.php');
require_once($CFG->dirroot . '/user/profile/field/repeatable/define.class.php');

/**
 * Dictionary columns on the edit form: the autocomplete's data and the code validation (stage B).
 *
 * Metadata stays in docblocks: the plugin supports Moodle 4.5, whose moodle-cs cannot read
 * PHPUnit attributes. Every test needs the companion's lookup API and skips without it.
 *
 * @package    profilefield_repeatable
 * @covers     \profile_field_repeatable
 * @covers     \local_repeatable\form\widget
 * @covers     \profile_define_repeatable
 * @covers     \local_repeatable\form\column_editor
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dictionary_codes_test extends \advanced_testcase {
    /**
     * Skip without the companion's lookup API, then create the dictionaries.
     *
     * 'langs' (open to logged-in users): pt, es, and xx (inactive). 'uf' (open): SP, RJ.
     * 'cities' (restricted to local/dictionaries:view), whose items have their state in 'uf' as
     * parent: 3550308 (SP), 3304557 (RJ); 0000000 has no parent. The tests run as the admin
     * unless they say otherwise.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        if (!dictionaries::lookup_available()) {
            $this->markTestSkipped('local_dictionaries with api::lookup() is not installed.');
        }
        $this->resetAfterTest();

        /** @var \local_dictionaries_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_dictionaries');
        $generator->create_domain(['shortname' => 'langs', 'visibility' => 1]);
        $generator->create_item(['domain' => 'langs', 'code' => 'pt', 'label' => 'Portuguese']);
        $generator->create_item(['domain' => 'langs', 'code' => 'es', 'label' => 'Spanish']);
        $generator->create_item(['domain' => 'langs', 'code' => 'xx', 'label' => 'Retired', 'active' => 0]);
        $generator->create_domain(['shortname' => 'uf', 'visibility' => 1]);
        $generator->create_item(['domain' => 'uf', 'code' => 'SP', 'label' => 'São Paulo']);
        $generator->create_item(['domain' => 'uf', 'code' => 'RJ', 'label' => 'Rio de Janeiro']);
        $generator->create_domain(['shortname' => 'cities', 'visibility' => 0]);
        $generator->create_item(['domain' => 'cities', 'code' => '3550308', 'label' => 'São Paulo', 'parentcode' => 'SP',
            'parentdomain' => 'uf']);
        $generator->create_item(['domain' => 'cities', 'code' => '3304557', 'label' => 'Rio', 'parentcode' => 'RJ',
            'parentdomain' => 'uf']);
        $generator->create_item(['domain' => 'cities', 'code' => '0000000', 'label' => 'Nowhere']);
        $this->setAdminUser();
    }

    /**
     * The columns of the test field: strict language, open level, strict state, city depending on
     * the state, and an open column on the restricted cities.
     *
     * @param string $cityparent Key of the city column's parent.
     * @return array
     */
    private function columns(string $cityparent = 'state'): array {
        return [
            ['key' => 'lang', 'label' => 'Language', 'mode' => 'dictionary', 'domain' => 'langs'],
            ['key' => 'level', 'label' => 'Level', 'mode' => 'dictionary_open', 'domain' => 'langs'],
            ['key' => 'state', 'label' => 'State', 'mode' => 'dictionary', 'domain' => 'uf'],
            ['key' => 'city', 'label' => 'City', 'mode' => 'dictionary', 'domain' => 'cities', 'parent' => $cityparent],
            ['key' => 'othercity', 'label' => 'Other city', 'mode' => 'dictionary_open', 'domain' => 'cities'],
        ];
    }

    /**
     * Create the test field.
     *
     * @return \stdClass The field record.
     */
    private function create_field_record(): \stdClass {
        /** @var \profilefield_repeatable_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('profilefield_repeatable');
        return $generator->create_field($this->columns());
    }

    /**
     * Validate one payload of sets for a user.
     *
     * @param \profile_field_repeatable $field
     * @param array $sets List of sets (key => code).
     * @return array Errors from edit_validate_field().
     */
    private function validate(\profile_field_repeatable $field, array $sets): array {
        $payload = [];
        foreach (array_values($sets) as $index => $set) {
            $payload['@@ID#' . ($index + 1)] = $set;
        }
        return $field->edit_validate_field((object)['id' => (int)$field->userid, $field->inputname => json_encode($payload)]);
    }

    /**
     * The expected error of one code.
     *
     * @param string $identifier
     * @param int $set
     * @param string $column
     * @param string $code
     * @param array $extra More placeholders (parent, parentcode).
     * @return string
     */
    private function code_error(string $identifier, int $set, string $column, string $code, array $extra = []): string {
        return get_string($identifier, 'local_repeatable', (object)(['set' => $set, 'column' => $column,
            'code' => $code] + $extra));
    }

    /**
     * Render the test edit form around a field, submitting a payload for it.
     *
     * @param \profile_field_repeatable $field
     * @param string $payload
     * @return string The form's HTML.
     */
    private function render_form(\profile_field_repeatable $field, string $payload = '{}'): string {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/user/profile/field/repeatable/tests/fixtures/edit_form.php');

        $PAGE->set_url('/user/edit.php');
        \profilefield_repeatable_test_edit_form::mock_submit([$field->inputname => $payload]);
        return (new \profilefield_repeatable_test_edit_form(null, ['field' => $field]))->render();
    }

    /**
     * The columns the edit form hands the widget.
     *
     * @param string $html
     * @return array
     */
    private function widget_columns(string $html): array {
        $this->assertSame(1, preg_match('~data-columns="([^"]*)"~', $html, $matches));
        return json_decode(html_entity_decode($matches[1]), true);
    }

    /**
     * A deactivated code the user already has stored under that column stays accepted; only there.
     */
    public function test_stored_inactive_code_is_kept(): void {
        global $DB;

        $record = $this->create_field_record();
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('user_info_data', [
            'userid' => $user->id,
            'fieldid' => $record->id,
            'data' => '{"@@ID#1":{"lang":"XX","level":"","state":"","city":""}}',
            'dataformat' => 0,
        ]);
        $field = new \profile_field_repeatable((int)$record->id, (int)$user->id);

        $this->assertSame([], $this->validate($field, [['lang' => 'xx'], ['lang' => 'pt']]));
        // Control: the stored code does not open the column to other inactive or unknown codes.
        $this->assertSame(
            [$field->inputname => $this->code_error('errorcodeunknown', 2, 'Language', 'zz')],
            $this->validate($field, [['lang' => 'xx'], ['lang' => 'zz']])
        );
        // Control: another user holding nothing is refused the same code.
        $other = new \profile_field_repeatable((int)$record->id, (int)$this->getDataGenerator()->create_user()->id);
        $this->assertSame(
            [$other->inputname => $this->code_error('errorcodeinactive', 1, 'Language', 'xx')],
            $this->validate($other, [['lang' => 'xx']])
        );
    }

    /**
     * Without the companion the codes are text: nothing is checked and no column is searched.
     */
    public function test_without_the_companion_codes_are_text(): void {
        $record = $this->create_field_record();
        $field = new class ((int)$record->id, 0) extends \profile_field_repeatable {
            /**
             * Run as if the companion were absent.
             *
             * @return bool
             */
            protected function companion_available(): bool {
                return false;
            }
        };

        $this->assertSame([], $this->validate($field, [['lang' => 'zz', 'state' => 'SP', 'city' => '3304557']]));

        $this->setAdminUser();
        $html = $this->render_form($field, '{"@@ID#1":{"lang":"pt","level":"","state":"","city":""}}');
        $this->assertSame([false, false, false, false, false], array_column($this->widget_columns($html), 'autocomplete'));
        $this->assertStringContainsString('data-labels="{}"', $html);
    }

    /**
     * The widget gets each dictionary column's domain, mode, parent, and whether it is searched.
     *
     * The cities domain is restricted: a user without local/dictionaries:view gets it as text,
     * because the search service would refuse them.
     */
    public function test_widget_columns_and_restricted_domain(): void {
        $record = $this->create_field_record();
        // Each user edits their own profile, which they may.
        $columns = function () use ($record): array {
            global $USER;
            return $this->widget_columns($this->render_form(new \profile_field_repeatable((int)$record->id, (int)$USER->id)));
        };

        $this->setUser($this->getDataGenerator()->create_user());
        $plain = $columns();
        $this->assertSame([
            'key' => 'lang', 'label' => 'Language', 'mode' => 'dictionary', 'required' => false,
            'domain' => 'langs', 'open' => false, 'autocomplete' => true,
        ], $plain[0]);
        $this->assertTrue($plain[1]['open']);
        $this->assertSame([
            'key' => 'city', 'label' => 'City', 'mode' => 'dictionary', 'required' => false,
            'domain' => 'cities', 'open' => false, 'autocomplete' => false,
            'parent' => 'state', 'parentdomain' => 'uf', 'parentlabel' => 'State',
        ], $plain[3]);

        $this->setAdminUser();
        $this->assertTrue($columns()[3]['autocomplete'], 'The admin may search the restricted domain');
    }

    /**
     * The labels of the codes the form holds ride on the hidden input, so no request is needed to show them.
     */
    public function test_edit_form_preloads_labels(): void {
        $this->setAdminUser();
        $record = $this->create_field_record();
        $field = new \profile_field_repeatable((int)$record->id, 0);
        $html = $this->render_form($field, '{"@@ID#1":{"lang":"pt","level":"new1","state":"SP","city":"3550308"}}');

        $this->assertSame(1, preg_match('~<input[^>]*name="' . preg_quote($field->inputname, '~') . '"[^>]*>~', $html, $input));
        $this->assertSame(1, preg_match('~data-labels="([^"]*)"~', $input[0], $matches));
        $this->assertSame([
            'langs' => ['pt' => 'Portuguese'],
            'uf' => ['SP' => 'São Paulo'],
            'cities' => ['3550308' => 'São Paulo'],
        ], json_decode(html_entity_decode($matches[1]), true));
    }

    /**
     * The definition form refuses a parent column on another dictionary than the one holding the parents.
     */
    public function test_define_refuses_a_parent_on_the_wrong_dictionary(): void {
        $define = new \profile_define_repeatable();
        $schema = function (string $cityparent): string {
            /** @var \profilefield_repeatable_generator $generator */
            $generator = $this->getDataGenerator()->get_plugin_generator('profilefield_repeatable');
            return $generator->schema_json($this->columns($cityparent));
        };

        $this->assertSame([], $define->define_validate_specific((object)['param1' => $schema('state')], []));
        $this->assertSame(
            ['param1' => get_string('errorparentmismatch', 'local_repeatable', (object)[
                'column' => 'City',
                'domain' => 'cities',
                'parentdomain' => 'uf',
            ])],
            $define->define_validate_specific((object)['param1' => $schema('lang')], [])
        );
    }

    /**
     * Store a payload for a user, as their saved profile value.
     *
     * @param int $fieldid
     * @param int $userid
     * @param array $set One set: key => code.
     * @return void
     */
    private function store(int $fieldid, int $userid, array $set): void {
        global $DB;
        $DB->insert_record('user_info_data', [
            'userid' => $userid,
            'fieldid' => $fieldid,
            'data' => json_encode(['@@ID#1' => $set]),
            'dataformat' => 0,
        ]);
    }

    /**
     * A user who may not read a dictionary gets no label for a code they submit, only for the ones stored.
     */
    public function test_restricted_domain_labels_only_stored_codes(): void {
        $record = $this->create_field_record();
        $user = $this->getDataGenerator()->create_user();
        $this->store((int)$record->id, (int)$user->id, ['city' => '3550308']);
        $this->setUser($user);
        $field = new \profile_field_repeatable((int)$record->id, (int)$user->id);

        $html = $this->render_form($field, json_encode([
            '@@ID#1' => ['state' => 'SP', 'city' => '3550308'],
            '@@ID#2' => ['state' => 'RJ', 'city' => '3304557', 'othercity' => '0000000'],
        ]));
        $this->assertSame(1, preg_match('~<input[^>]*name="' . preg_quote($field->inputname, '~') . '"[^>]*>~', $html, $input));
        $this->assertSame(1, preg_match('~data-labels="([^"]*)"~', $input[0], $matches));
        $this->assertSame([
            'uf' => ['SP' => 'São Paulo', 'RJ' => 'Rio de Janeiro'],
            'cities' => ['3550308' => 'São Paulo'],
        ], json_decode(html_entity_decode($matches[1]), true));

        // Control: the admin, who may read the dictionary, gets every label.
        $this->setAdminUser();
        $html = $this->render_form(new \profile_field_repeatable((int)$record->id, (int)$user->id), json_encode([
            '@@ID#1' => ['state' => 'RJ', 'city' => '3304557'],
        ]));
        $this->assertStringContainsString('&quot;3304557&quot;:&quot;Rio&quot;', $html);
    }

    /**
     * A user who may not read a dictionary cannot tell its codes apart: every code not stored gets one message.
     */
    public function test_restricted_domain_refuses_every_unstored_code_alike(): void {
        $record = $this->create_field_record();
        $user = $this->getDataGenerator()->create_user();
        $this->store((int)$record->id, (int)$user->id, ['city' => '3550308']);
        $this->setUser($user);
        $field = new \profile_field_repeatable((int)$record->id, (int)$user->id);

        $expected = [$field->inputname => $this->code_error('errorcoderestricted', 1, 'City', '')];
        $this->assertSame($expected, $this->validate($field, [['state' => 'RJ', 'city' => '3304557']]), 'An existing code');
        $this->assertSame($expected, $this->validate($field, [['state' => 'RJ', 'city' => 'nope']]), 'An unknown code');
        $this->assertSame($expected, $this->validate($field, [['state' => 'RJ', 'city' => '0000000']]), 'A code with no parent');
        // The stored code stays, and no parent check reaches the user: RJ is not its state.
        $this->assertSame([], $this->validate($field, [['state' => 'RJ', 'city' => '3550308']]));
        // An open column on the restricted dictionary takes any code as text.
        $this->assertSame([], $this->validate($field, [['othercity' => 'anything']]));

        // Control: the admin gets the dictionary's answers.
        $this->setAdminUser();
        $admin = new \profile_field_repeatable((int)$record->id, (int)$user->id);
        $this->assertSame(
            [$admin->inputname => $this->code_error('errorcodeunknown', 1, 'City', 'nope')],
            $this->validate($admin, [['state' => 'RJ', 'city' => 'nope']])
        );
    }

    /**
     * A dependent column is neither searched nor checked against its parent when the parent column's dictionary cannot be read.
     */
    public function test_widget_needs_the_parent_domain_too(): void {
        /** @var \local_dictionaries_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_dictionaries');
        $generator->create_domain(['shortname' => 'secret', 'visibility' => 0]);
        /** @var \profilefield_repeatable_generator $fieldgenerator */
        $fieldgenerator = $this->getDataGenerator()->get_plugin_generator('profilefield_repeatable');
        $record = $fieldgenerator->create_field([
            ['key' => 'zone', 'label' => 'Zone', 'mode' => 'dictionary', 'domain' => 'secret'],
            ['key' => 'town', 'label' => 'Town', 'mode' => 'dictionary_open', 'domain' => 'langs', 'parent' => 'zone'],
            ['key' => 'lang', 'label' => 'Language', 'mode' => 'dictionary', 'domain' => 'langs'],
        ], ['shortname' => 'zones']);
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $field = new \profile_field_repeatable((int)$record->id, (int)$user->id);
        $columns = $this->widget_columns($this->render_form($field));
        $this->assertSame([false, false, true], array_column($columns, 'autocomplete'));

        // Nor is its item checked against the unreadable parent: a blank parent would tell that it has one.
        $generator->create_item(['domain' => 'secret', 'code' => 'Z1', 'label' => 'Zone one']);
        $generator->create_item(['domain' => 'langs', 'code' => 'tw', 'label' => 'Town', 'parentcode' => 'Z1',
            'parentdomain' => 'secret']);
        $this->assertSame([], $this->validate($field, [['town' => 'tw']]));
    }
}
