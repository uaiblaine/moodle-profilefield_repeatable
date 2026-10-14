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
use local_repeatable\local\payload;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->dirroot . '/user/profile/field/repeatable/field.class.php');
require_once($CFG->dirroot . '/user/profile/definelib.php');
require_once($CFG->dirroot . '/user/profile/field/repeatable/define.class.php');

/**
 * Tests for profile_field_repeatable.
 *
 * @package    profilefield_repeatable
 * @covers     \profile_field_repeatable
 * @covers     \profilefield_repeatable\form\widget
 * @covers     \profilefield_repeatable\output\sets_renderer
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class field_class_test extends \advanced_testcase {
    /** @var string Script path for profile page. */
    private const SCRIPT_PROFILE = '/user/profile.php';

    /**
     * The schema JSON of some columns ({@see \profilefield_repeatable_generator::schema_json()}).
     *
     * @param array $columns
     * @return string
     */
    private function schema(array $columns): string {
        return $this->getDataGenerator()->get_plugin_generator('profilefield_repeatable')->schema_json($columns);
    }

    /**
     * A free "codigo_diretoria" column on the "diretoria" dictionary, then a free "codigo_escola".
     *
     * @return string
     */
    private function dictionary_schema(): string {
        return $this->schema([
            ['label' => 'codigo_diretoria', 'mode' => 'dictionary', 'domain' => 'diretoria'],
            'codigo_escola',
        ]);
    }

    /**
     * Create repeatable profile field instance.
     *
     * @param array $extradata
     * @return \profile_field_repeatable
     */
    protected function create_field(array $extradata = []): \profile_field_repeatable {
        $fielddata = $this->getDataGenerator()->create_custom_profile_field(array_merge([
            'datatype' => 'repeatable',
            'name' => 'Repeatable test',
            'shortname' => 'repeatable_test',
            'required' => 0,
            'forceunique' => 0,
            'defaultdata' => '{}',
            'param1' => $this->schema(['name', 'role']),
        ], $extradata));

        return new \profile_field_repeatable(0, 0, $fielddata);
    }

    /**
     * Validate required behaviour for repeatable profile field.
     */
    public function test_edit_validate_field_required_rule(): void {
        $this->resetAfterTest();

        $field = $this->create_field(['required' => 1]);
        $inputname = $field->inputname;

        $errors = $field->edit_validate_field((object) [
            'id' => 0,
            $inputname => '{}',
        ]);
        $this->assertArrayHasKey($inputname, $errors);

        $errors = $field->edit_validate_field((object) [
            'id' => 0,
            $inputname => '{"@@ID#1":{"name":"Alice","role":""}}',
        ]);
        $this->assertArrayNotHasKey($inputname, $errors);
    }

    /**
     * Ensure preprocessing drops empty sets and renumbers the remaining ones from @@ID#1.
     */
    public function test_edit_save_data_preprocess_normalises_payload(): void {
        $this->resetAfterTest();

        $field = $this->create_field();

        $processed = $field->edit_save_data_preprocess(
            '{"@@ID#10":{"name":"","role":""},"@@ID#20":{"name":"Alice","role":"Manager"},"@@ID#30":{"name":"Bob","role":""}}',
            new \stdClass()
        );

        $this->assertSame(
            '{"@@ID#1":{"name":"Alice","role":"Manager"},"@@ID#2":{"name":"Bob","role":""}}',
            $processed
        );
    }

    /**
     * Ensure preprocessing fills missing sub-items with empty strings.
     */
    public function test_edit_save_data_preprocess_fills_missing_subitems_with_empty_string(): void {
        $this->resetAfterTest();

        $field = $this->create_field([
            'param1' => $this->schema(['diretoria', 'escola', 'turma', 'disciplina']),
        ]);

        $processed = $field->edit_save_data_preprocess(
            '{"@@ID#1":{"diretoria":"16154","escola":"45","turma":"3"}}',
            new \stdClass()
        );

        $this->assertSame(
            '{"@@ID#1":{"diretoria":"16154","escola":"45","turma":"3","disciplina":""}}',
            $processed
        );
    }

    /**
     * Ensure preprocessing ignores unknown sub-items.
     */
    public function test_edit_save_data_preprocess_ignores_unknown_subitems(): void {
        $this->resetAfterTest();

        $field = $this->create_field([
            'param1' => $this->schema(['diretoria', 'escola', 'turma', 'disciplina']),
        ]);

        $processed = $field->edit_save_data_preprocess(
            '{"@@ID#1":{"diretoria":"16154","escola":"45","turma":"3","disciplina":"654","extra":"ignored"}}',
            new \stdClass()
        );

        $this->assertSame(
            '{"@@ID#1":{"diretoria":"16154","escola":"45","turma":"3","disciplina":"654"}}',
            $processed
        );
    }

    /**
     * Ensure locked field ignores tampered save attempts by users without update capability.
     */
    public function test_edit_save_data_locked_user_ignores_changes(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $fielddata = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'repeatable',
            'name' => 'Locked repeatable test',
            'shortname' => 'repeatable_locked',
            'required' => 0,
            'forceunique' => 0,
            'locked' => 1,
            'defaultdata' => '{}',
            'param1' => $this->schema(['name', 'role']),
        ]);
        $user = $this->getDataGenerator()->create_user();

        $DB->insert_record('user_info_data', (object) [
            'userid' => $user->id,
            'fieldid' => $fielddata->id,
            'data' => '{"@@ID#1":{"name":"Original","role":"Teacher"}}',
            'dataformat' => 0,
        ]);

        $field = new \profile_field_repeatable(0, $user->id, $fielddata);
        $inputname = $field->inputname;

        $this->setUser($user);
        $field->edit_save_data((object) [
            'id' => $user->id,
            $inputname => '{"@@ID#1":{"name":"Changed","role":"Manager"}}',
        ]);

        $saved = $DB->get_record('user_info_data', [
            'userid' => $user->id,
            'fieldid' => $fielddata->id,
        ], '*', MUST_EXIST);

        $this->assertSame('{"@@ID#1":{"name":"Original","role":"Teacher"}}', $saved->data);
    }

    /**
     * Ensure save performs upsert semantics and removes orphaned sets.
     */
    public function test_edit_save_data_upserts_sets_and_removes_orphans(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $field = $this->create_field([
            'param1' => $this->schema(['name', 'role']),
        ]);
        $user = $this->getDataGenerator()->create_user();
        $inputname = $field->inputname;

        $field->edit_save_data((object) [
            'id' => $user->id,
            $inputname => '{"@@ID#1":{"name":"Alice","role":"Manager"},"@@ID#2":{"name":"Bob","role":"Teacher"}}',
        ]);

        $fieldrecord = $DB->get_record('user_info_field', ['shortname' => 'repeatable_test'], 'id', MUST_EXIST);
        $dataid = (int)$DB->get_field('user_info_data', 'id', [
            'userid' => $user->id,
            'fieldid' => $fieldrecord->id,
        ]);
        $this->assertGreaterThan(0, $dataid);

        $rows = $DB->get_records('profilefield_repeatable_data', ['dataid' => $dataid], 'set_id ASC');
        $this->assertCount(2, $rows);

        $firstrow = reset($rows);
        $originaltimecreated = (int)$firstrow->timecreated;
        $this->assertGreaterThan(0, $originaltimecreated);
        $this->assertSame($originaltimecreated, (int)$firstrow->timemodified);

        $DB->set_field('profilefield_repeatable_data', 'timemodified', 1, ['id' => $firstrow->id]);

        $field->edit_save_data((object) [
            'id' => $user->id,
            $inputname => '{"@@ID#1":{"name":"Alice Updated","role":"Lead"}}',
        ]);

        $rows = $DB->get_records('profilefield_repeatable_data', ['dataid' => $dataid], 'set_id ASC');
        $this->assertCount(1, $rows);
        $updatedrow = reset($rows);
        $this->assertSame(1, (int)$updatedrow->set_id);
        $this->assertSame($originaltimecreated, (int)$updatedrow->timecreated);
        $this->assertGreaterThan(1, (int)$updatedrow->timemodified);

        $decoded = json_decode((string)$updatedrow->data, true);
        $this->assertSame([
            'name' => 'Alice Updated',
            'role' => 'Lead',
        ], $decoded);
    }

    /**
     * Ensure non-profile pages keep the legacy plain display format.
     */
    public function test_display_data_non_profile_context_keeps_plain_format(): void {
        global $SCRIPT;

        $this->resetAfterTest();

        $field = $this->create_field([
            'param1' => $this->schema(['codigo_turma', 'codigo_diretoria', 'codigo_escola']),
        ]);
        $field->data = '{"@@ID#1":{"codigo_turma":"101-A","codigo_diretoria":"DIR","codigo_escola":"EE"}}';

        $originalscript = $SCRIPT ?? null;
        try {
            $SCRIPT = '/user/edit.php';
            $output = $field->display_data();
        } finally {
            $SCRIPT = $originalscript;
        }

        $this->assertStringContainsString('profilefield-repeatable-display', $output);
        $this->assertStringNotContainsString('profilefield-repeatable-display-accordion', $output);
        $this->assertStringContainsString(get_string('repeatableset', 'profilefield_repeatable', 1), $output);
    }

    /**
     * Ensure profile pages render accordion and include sync timestamp from storage.
     */
    public function test_display_data_profile_context_renders_accordion_with_sync_timestamp(): void {
        global $DB, $SCRIPT;

        $this->resetAfterTest();
        $this->setAdminUser();

        $fielddata = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'repeatable',
            'name' => 'Repeatable display test',
            'shortname' => 'repeatable_display_test',
            'required' => 0,
            'forceunique' => 0,
            'defaultdata' => '{}',
            'param1' => $this->schema(['codigo_turma', 'codigo_diretoria', 'codigo_escola']),
        ]);
        $user = $this->getDataGenerator()->create_user();

        $fallbackjson = '{"@@ID#1":{"codigo_turma":"101-A","codigo_diretoria":"DIR-SP","codigo_escola":"EE Alpha"}}';
        $dataid = (int)$DB->insert_record('user_info_data', (object)[
            'userid' => $user->id,
            'fieldid' => $fielddata->id,
            'data' => $fallbackjson,
            'dataformat' => 0,
        ]);
        $timemodified = time();
        $DB->insert_record('profilefield_repeatable_data', (object)[
            'dataid' => $dataid,
            'fieldid' => $fielddata->id,
            'userid' => $user->id,
            'set_id' => 1,
            'data' => '{"codigo_turma":"101-A","codigo_diretoria":"DIR-SP","codigo_escola":"EE Alpha"}',
            'timecreated' => $timemodified,
            'timemodified' => $timemodified,
        ]);

        // Loaded the way core loads it: the payload comes from user_info_data.
        $field = new \profile_field_repeatable((int)$fielddata->id, (int)$user->id);

        $originalscript = $SCRIPT ?? null;
        try {
            $SCRIPT = self::SCRIPT_PROFILE;
            $output = $field->display_data();
        } finally {
            $SCRIPT = $originalscript;
        }

        $this->assertStringContainsString('profilefield-repeatable-display-accordion', $output);
        $this->assertStringContainsString('<details class="profilefield-repeatable-item" open>', $output);
        $this->assertStringContainsString('profilefield-repeatable-summary', $output);
        $this->assertStringContainsString('101-A', $output);
        $this->assertStringContainsString(
            userdate($timemodified, get_string('strftimedatetimeshort', 'langconfig')),
            $output
        );

        $titlelabel = format_string('codigo_turma', true, ['context' => \context_system::instance()]);
        $this->assertSame(0, substr_count($output, $titlelabel));

        $bodylabel = format_string('codigo_diretoria', true, ['context' => \context_system::instance()]);
        $this->assertSame(1, substr_count($output, $bodylabel));
    }

    /**
     * Assert that a name written with markup renders escaped exactly once.
     *
     * @param string $html Rendered output.
     * @param string $label The plain label the name starts with.
     * @param int $expected How many times the escaped label is expected.
     */
    private function assert_escaped_once(string $html, string $label, int $expected): void {
        $this->assertSame($expected, substr_count($html, $label . ' R&amp;D'), $label . ' must appear escaped once');
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    /**
     * The edit form escapes the field and sub-item names once: the template does it.
     */
    public function test_edit_form_escapes_names_once(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $field = $this->create_field([
            'name' => 'Field R&D <b>x</b>',
            'param1' => $this->schema(['Item R&D <b>x</b>', 'role']),
        ]);
        $html = $this->submit_edit_form($field, '{}')->render();

        $this->assert_escaped_once($html, 'Field', 1);
        $this->assert_escaped_once($html, 'Item', 1);
    }

    /**
     * Both display paths (plain list and profile accordion) escape the names once.
     */
    public function test_display_escapes_names_once(): void {
        global $SCRIPT;

        $this->resetAfterTest();

        $field = $this->create_field([
            'name' => 'Field R&D <b>x</b>',
            'param1' => $this->schema(['role', 'Item R&D <b>x</b>']),
        ]);
        $field->data = '{"@@ID#1":{"role":"first","item_r_d_b_x_b":"second"}}';

        $originalscript = $SCRIPT ?? null;
        try {
            $SCRIPT = '/user/edit.php';
            $plain = $field->display_data();
            $SCRIPT = '/user/view.php';
            $accordion = $field->display_data();
        } finally {
            $SCRIPT = $originalscript;
        }

        // The plain list prints the label of each filled column; the accordion titles the set with the title
        // column's value, labels the other rows with their names and puts the field name in its aria-label.
        $this->assertStringContainsString('profilefield-repeatable-display-accordion', $accordion);
        $this->assertSame(1, substr_count($plain, 'Item R&amp;D'));
        $this->assertSame(1, substr_count($accordion, 'Item R&amp;D'));
        $this->assertStringNotContainsString('&amp;amp;', $plain);
        $this->assertStringNotContainsString('<b>', $plain);
        $this->assertSame(1, substr_count($accordion, 'aria-label="Field R&amp;D'));
        $this->assertStringNotContainsString('&amp;amp;', $accordion);
        $this->assertStringNotContainsString('<b>', $accordion);
    }

    /**
     * Ensure profile accordion hides sync footer when timestamp is unavailable.
     */
    public function test_display_data_profile_context_hides_sync_footer_without_timestamp(): void {
        global $SCRIPT;

        $this->resetAfterTest();

        $field = $this->create_field([
            'param1' => $this->schema(['codigo_turma', 'codigo_diretoria', 'codigo_escola']),
        ]);
        $field->data = '{"@@ID#1":{"codigo_turma":"101-A","codigo_diretoria":"DIR","codigo_escola":"EE"}}';

        $originalscript = $SCRIPT ?? null;
        try {
            $SCRIPT = '/user/view.php';
            $output = $field->display_data();
        } finally {
            $SCRIPT = $originalscript;
        }

        $this->assertStringContainsString('profilefield-repeatable-display-accordion', $output);
        $this->assertStringNotContainsString('profilefield-repeatable-sync', $output);
    }

    /**
     * Ensure profile accordion title falls back to "Set N" when first sub-item is empty.
     */
    public function test_display_data_profile_context_falls_back_to_set_title_when_first_subitem_empty(): void {
        global $SCRIPT;

        $this->resetAfterTest();

        $field = $this->create_field([
            'param1' => $this->schema(['codigo_turma', 'codigo_diretoria', 'codigo_escola']),
        ]);
        $field->data = '{"@@ID#1":{"codigo_turma":"","codigo_diretoria":"DIR","codigo_escola":"EE"}}';

        $originalscript = $SCRIPT ?? null;
        try {
            $SCRIPT = self::SCRIPT_PROFILE;
            $output = $field->display_data();
        } finally {
            $SCRIPT = $originalscript;
        }

        $this->assertStringContainsString(get_string('repeatableset', 'profilefield_repeatable', 1), $output);
    }

    /**
     * Ensure profile display falls back to code when reference label is unavailable.
     */
    public function test_display_data_profile_context_keeps_code_when_reference_not_found(): void {
        global $SCRIPT;

        $this->resetAfterTest();

        $field = $this->create_field([
            'param1' => $this->dictionary_schema(),
        ]);
        $field->data = '{"@@ID#1":{"codigo_diretoria":"16","codigo_escola":"45"}}';

        $originalscript = $SCRIPT ?? null;
        try {
            $SCRIPT = self::SCRIPT_PROFILE;
            $output = $field->display_data();
        } finally {
            $SCRIPT = $originalscript;
        }

        $this->assertStringContainsString('16', $output);
    }

    /**
     * Ensure profile display resolves label when local reference dictionary has the code.
     */
    public function test_display_data_profile_context_resolves_reference_label(): void {
        global $SCRIPT;

        $this->resetAfterTest();
        $this->setAdminUser();

        if (!class_exists('\\local_dictionaries\\resolver')) {
            $this->markTestSkipped('local_dictionaries plugin is not available.');
        }

        $generator = $this->getDataGenerator()->get_plugin_generator('local_dictionaries');
        $generator->create_domain(['shortname' => 'diretoria', 'name' => 'Diretoria']);
        $generator->create_item(['domain' => 'diretoria', 'code' => '16', 'label' => 'Sao Paulo']);

        $field = $this->create_field([
            'param1' => $this->dictionary_schema(),
        ]);
        $field->data = '{"@@ID#1":{"codigo_diretoria":"16","codigo_escola":"45"}}';

        $originalscript = $SCRIPT ?? null;
        try {
            $SCRIPT = self::SCRIPT_PROFILE;
            $output = $field->display_data();
        } finally {
            $SCRIPT = $originalscript;
        }

        $this->assertStringContainsString('Sao Paulo', $output);
    }

    /**
     * Ensure non-profile (plain) display resolves reference labels when local dictionary is available.
     */
    public function test_display_data_non_profile_context_resolves_reference_label(): void {
        global $SCRIPT;

        $this->resetAfterTest();
        $this->setAdminUser();

        if (!class_exists('\\local_dictionaries\\resolver')) {
            $this->markTestSkipped('local_dictionaries plugin is not available.');
        }

        $generator = $this->getDataGenerator()->get_plugin_generator('local_dictionaries');
        $generator->create_domain(['shortname' => 'diretoria', 'name' => 'Diretoria']);
        $generator->create_item(['domain' => 'diretoria', 'code' => '16', 'label' => 'Sao Paulo']);

        $field = $this->create_field([
            'param1' => $this->dictionary_schema(),
        ]);
        $field->data = '{"@@ID#1":{"codigo_diretoria":"16","codigo_escola":"45"}}';

        $originalscript = $SCRIPT ?? null;
        try {
            $SCRIPT = '/user/edit.php';
            $output = $field->display_data();
        } finally {
            $SCRIPT = $originalscript;
        }

        $this->assertStringContainsString('Sao Paulo', $output);
        $this->assertStringNotContainsString('>16<', $output);
    }

    /**
     * Ensure non-profile (plain) display falls back to raw code when reference label is unavailable.
     */
    public function test_display_data_non_profile_context_keeps_code_when_reference_not_found(): void {
        global $SCRIPT;

        $this->resetAfterTest();

        $field = $this->create_field([
            'param1' => $this->dictionary_schema(),
        ]);
        $field->data = '{"@@ID#1":{"codigo_diretoria":"16","codigo_escola":"45"}}';

        $originalscript = $SCRIPT ?? null;
        try {
            $SCRIPT = '/user/edit.php';
            $output = $field->display_data();
        } finally {
            $SCRIPT = $originalscript;
        }

        $this->assertStringContainsString('16', $output);
    }

    /**
     * Ensure plain display respects subitem ordering from param1 rather than payload key order.
     */
    public function test_display_data_non_profile_context_respects_subitem_order(): void {
        global $SCRIPT;

        $this->resetAfterTest();

        $field = $this->create_field([
            'param1' => $this->schema(['codigo_turma', 'codigo_diretoria', 'codigo_escola']),
        ]);
        $field->data = '{"@@ID#1":{"codigo_escola":"EE","codigo_diretoria":"DIR","codigo_turma":"101"}}';

        $originalscript = $SCRIPT ?? null;
        try {
            $SCRIPT = '/user/edit.php';
            $output = $field->display_data();
        } finally {
            $SCRIPT = $originalscript;
        }

        $posturma = strpos($output, '101');
        $posdiretoria = strpos($output, 'DIR');
        $posescola = strpos($output, 'EE');
        $this->assertNotFalse($posturma);
        $this->assertNotFalse($posdiretoria);
        $this->assertNotFalse($posescola);
        $this->assertLessThan($posdiretoria, $posturma);
        $this->assertLessThan($posescola, $posdiretoria);
    }

    /**
     * Ensure the accordion resolves labels of a schema saved through define_save_preprocess(), domains lower-cased.
     */
    public function test_display_data_profile_resolves_reference_with_preprocessed_schema(): void {
        global $SCRIPT;

        $this->resetAfterTest();
        $this->setAdminUser();

        if (!class_exists('\\local_dictionaries\\resolver')) {
            $this->markTestSkipped('local_dictionaries plugin is not available.');
        }

        $generator = $this->getDataGenerator()->get_plugin_generator('local_dictionaries');
        $generator->create_domain(['shortname' => 'diretorias', 'name' => 'Diretorias']);
        $generator->create_item(['domain' => 'diretorias', 'code' => '16', 'label' => 'Sao Paulo']);
        $generator->create_domain(['shortname' => 'escolas', 'name' => 'Escolas']);
        $generator->create_item(['domain' => 'escolas', 'code' => '45', 'label' => 'EE Alpha']);

        // Canonicalise the schema through define_save_preprocess, as the admin form does.
        $define = new \profile_define_repeatable();
        $preprocessed = $define->define_save_preprocess((object) [
            'param1' => $this->schema([
                ['label' => 'Codigo_Diretoria', 'mode' => 'dictionary', 'domain' => 'Diretorias'],
                ['label' => 'Codigo_Escola', 'mode' => 'dictionary_open', 'domain' => 'ESCOLAS'],
            ]),
        ]);

        $fielddata = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'repeatable',
            'name' => 'Preprocessed test',
            'shortname' => 'repeatable_preproc_test',
            'required' => 0,
            'forceunique' => 0,
            'defaultdata' => '{}',
            'param1' => $preprocessed->param1,
        ]);

        $field = new \profile_field_repeatable(0, 0, $fielddata);
        $field->data = '{"@@ID#1":{"codigo_diretoria":"16","codigo_escola":"45"}}';

        $originalscript = $SCRIPT ?? null;
        try {
            $SCRIPT = self::SCRIPT_PROFILE;
            $output = $field->display_data();
        } finally {
            $SCRIPT = $originalscript;
        }

        $this->assertStringContainsString('profilefield-repeatable-display-accordion', $output);
        $this->assertStringContainsString('Sao Paulo', $output);
        $this->assertStringContainsString('EE Alpha', $output);
    }

    /**
     * is_empty() decides from the loaded payload and never queries the projection.
     */
    public function test_is_empty_reads_no_storage(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $this->create_field();
        $user = $this->getDataGenerator()->create_user();
        $fieldrecord = $DB->get_record('user_info_field', ['shortname' => 'repeatable_test'], '*', MUST_EXIST);

        $savefield = new \profile_field_repeatable(0, 0, $fieldrecord);
        $savefield->edit_save_data((object) [
            'id' => $user->id,
            $savefield->inputname => '{"@@ID#1":{"name":"Alice","role":"Manager"}}',
        ]);

        $field = new \profile_field_repeatable((int)$fieldrecord->id, (int)$user->id);
        $before = $DB->perf_get_reads();
        $this->assertFalse($field->is_empty());
        $this->assertSame(0, $DB->perf_get_reads() - $before, 'is_empty() must not query the database.');
    }

    /**
     * Profile reads follow the canonical payload after core writes user_info_data directly.
     *
     * profile_save_custom_fields() is what auth_db and auth_ldap attribute sync call: it never
     * reaches this plugin, so the per-set rows keep the previous sets.
     */
    public function test_reads_follow_payload_written_by_profile_save_custom_fields(): void {
        global $DB, $SCRIPT;

        $this->resetAfterTest();
        $this->setAdminUser();

        $savefield = $this->create_field();
        $fieldid = (int)$savefield->field->id;
        $user = $this->getDataGenerator()->create_user();
        $savefield->edit_save_data((object) [
            'id' => $user->id,
            $savefield->inputname => '{"@@ID#1":{"name":"Alice","role":"Manager"}}',
        ]);

        profile_save_custom_fields($user->id, ['repeatable_test' => '{"@@ID#1":{"name":"Bob","role":"Teacher"}}']);

        // Precondition: the projection still holds the old set.
        $rows = $DB->get_records('profilefield_repeatable_data', ['fieldid' => $fieldid, 'userid' => $user->id]);
        $this->assertStringContainsString('Alice', (string)reset($rows)->data);

        $field = new \profile_field_repeatable($fieldid, (int)$user->id);
        $loaded = new \stdClass();
        $field->edit_load_user_data($loaded);
        $this->assertStringContainsString('Bob', $loaded->{$field->inputname});
        $this->assertStringNotContainsString('Alice', $loaded->{$field->inputname});

        $originalscript = $SCRIPT ?? null;
        try {
            $SCRIPT = '/user/edit.php';
            $output = $field->display_data();
        } finally {
            $SCRIPT = $originalscript;
        }
        $this->assertStringContainsString('Bob', $output);
        $this->assertStringNotContainsString('Alice', $output);

        // An emptied value empties the field, even though the projection still has a set.
        profile_save_custom_fields($user->id, ['repeatable_test' => '{}']);
        $this->assertTrue($DB->record_exists('profilefield_repeatable_data', ['fieldid' => $fieldid, 'userid' => $user->id]));

        $field = new \profile_field_repeatable($fieldid, (int)$user->id);
        $this->assertTrue($field->is_empty());
        $this->assertSame('', $field->display_data());
    }

    /**
     * A projection row lends its sync time only to the payload set it still mirrors.
     */
    public function test_display_renderer_takes_sync_time_from_matching_rows_only(): void {
        global $SCRIPT;

        $this->resetAfterTest();
        $this->setAdminUser();

        $fielddata = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'repeatable',
            'name' => 'Preload test',
            'shortname' => 'repeatable_preload_test',
            'required' => 0,
            'forceunique' => 0,
            'defaultdata' => '{}',
            'param1' => $this->schema(['name', 'role']),
        ]);
        $payload = '{"@@ID#1":{"name":"Alice","role":"Manager"}}';
        $synctime = 1700000000;
        $syncstring = userdate($synctime, get_string('strftimedatetimeshort', 'langconfig'));

        // Keys in another order, as PostgreSQL JSONB returns them, still match the set.
        $matching = [
            (object) ['id' => 1, 'set_id' => 1, 'data' => '{"role": "Manager", "name": "Alice"}', 'timemodified' => $synctime],
            (object) ['id' => 2, 'set_id' => 2, 'data' => '{"name": "Ghost", "role": "Stale"}', 'timemodified' => $synctime],
        ];
        $stale = [
            (object) ['id' => 1, 'set_id' => 1, 'data' => '{"name": "Old", "role": "Manager"}', 'timemodified' => $synctime],
        ];

        $originalscript = $SCRIPT ?? null;
        try {
            $SCRIPT = self::SCRIPT_PROFILE;

            // User 999999 has no rows in the database, so the sync time can only come from the preload.
            $output = (new \profilefield_repeatable\output\display_renderer($fielddata, 999999, $payload, null, null, $matching))
                ->render();
            $this->assertStringContainsString('Alice', $output);
            $this->assertStringNotContainsString('Ghost', $output);
            $this->assertStringContainsString($syncstring, $output);

            $output = (new \profilefield_repeatable\output\display_renderer($fielddata, 999999, $payload, null, null, $stale))
                ->render();
            $this->assertStringContainsString('Alice', $output);
            $this->assertStringNotContainsString('Old', $output);
            $this->assertStringNotContainsString($syncstring, $output);
        } finally {
            $SCRIPT = $originalscript;
        }
    }

    /**
     * Build the test form around a field, submitting a payload for it.
     *
     * @param \profile_field_repeatable $field
     * @param string $payload Raw submitted value of the field.
     * @return \profilefield_repeatable_test_edit_form
     */
    private function submit_edit_form(\profile_field_repeatable $field, string $payload): \profilefield_repeatable_test_edit_form {
        global $CFG, $PAGE;

        require_once($CFG->dirroot . '/user/profile/field/repeatable/tests/fixtures/edit_form.php');

        $PAGE->set_url('/user/edit.php');
        \profilefield_repeatable_test_edit_form::mock_submit([$field->inputname => $payload]);
        return new \profilefield_repeatable_test_edit_form(null, ['field' => $field]);
    }

    /**
     * A server-side validation error renders under the widget, which points at it.
     */
    public function test_edit_form_renders_validation_error_under_widget(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $field = $this->create_field();
        $oversized = str_repeat('x', payload::MAX_VALUE_LENGTH + 1);
        $form = $this->submit_edit_form($field, json_encode(['@@ID#1' => ['name' => $oversized, 'role' => '']]));

        $this->assertNull($form->get_data());
        $html = $form->render();

        $errorid = 'fgroup_id_error_' . $field->inputname;
        $message = get_string('errorvaluetoolong', 'local_repeatable', payload::MAX_VALUE_LENGTH);
        $this->assertMatchesRegularExpression(
            '~<div[^>]*id="' . preg_quote($errorid, '~') . '"[^>]*>\s*' . preg_quote($message, '~') . '\s*</div>~',
            $html
        );
        $this->assertStringContainsString('aria-describedby="' . $errorid . '"', $html);
    }

    /**
     * The required error of an empty payload renders too, and a valid payload round-trips once.
     */
    public function test_edit_form_required_error_and_value_round_trip(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $fielddata = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'repeatable',
            'name' => 'Repeatable test',
            'shortname' => 'repeatable_test',
            'required' => 1,
            'defaultdata' => '{}',
            'param1' => $this->schema(['name', 'role']),
        ]);
        // Own profile, so core also adds its client-side required rule to the element.
        $field = new \profile_field_repeatable((int)$fielddata->id, (int)$USER->id);

        $form = $this->submit_edit_form($field, '{}');
        $this->assertNull($form->get_data());
        $html = $form->render();
        $errorid = 'fgroup_id_error_' . $field->inputname;
        $this->assertMatchesRegularExpression(
            '~id="' . preg_quote($errorid, '~') . '"[^>]*>\s*' . preg_quote(get_string('required'), '~') . '~',
            $html
        );

        $payload = '{"@@ID#1":{"name":"Alice","role":"Manager"}}';
        $form = $this->submit_edit_form($field, $payload);
        $data = $form->get_data();
        $this->assertNotNull($data);
        $this->assertSame($payload, $data->{$field->inputname});
        $this->assertSame(1, preg_match_all('~<input[^>]* name="' . preg_quote($field->inputname, '~') . '"~', $form->render()));
    }

    /**
     * Every set with a value must fill the required columns; an empty optional field passes.
     */
    public function test_edit_validate_field_required_columns(): void {
        $this->resetAfterTest();

        $field = $this->create_field(['param1' => $this->schema(['name', ['label' => 'Role', 'required' => true]])]);
        $inputname = $field->inputname;
        $validate = fn(string $payload): array => $field->edit_validate_field((object)['id' => 0, $inputname => $payload]);

        $this->assertSame([], $validate('{}'), 'The field itself is optional');
        $this->assertSame([], $validate('{"@@ID#1":{"name":"Alice","role":"Lead"},"@@ID#2":{"name":"","role":"Lead"}}'));

        $errors = $validate('{"@@ID#1":{"name":"Alice","role":"Lead"},"@@ID#2":{"name":"","role":""},'
            . '"@@ID#3":{"name":"Bob","role":"  "}}');
        $this->assertSame(
            [$inputname => get_string('errorcolumnrequired', 'local_repeatable', (object)['set' => 2, 'column' => 'Role'])],
            $errors,
            'The emptied set is dropped, so Bob is set 2'
        );
    }

    /**
     * The required column error renders under the widget, and its column name is escaped.
     */
    public function test_edit_form_renders_required_column_error(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $field = $this->create_field(['param1' => $this->schema(['name', ['label' => 'R&D', 'required' => true]])]);
        $form = $this->submit_edit_form($field, '{"@@ID#1":{"name":"Alice","r_d":""}}');

        $this->assertNull($form->get_data());
        $message = get_string('errorcolumnrequired', 'local_repeatable', (object)['set' => 1, 'column' => 'R&amp;D']);
        $this->assertMatchesRegularExpression(
            '~id="' . preg_quote('fgroup_id_error_' . $field->inputname, '~') . '"[^>]*>\s*' . preg_quote($message, '~') . '~',
            $form->render()
        );
    }

    /**
     * The edit form gives the widget each column's key, plain label, required flag and dictionary properties,
     * and marks required headers.
     */
    public function test_edit_form_hands_the_columns_to_the_widget(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $field = $this->create_field(['param1' => $this->schema([
            ['key' => 'lang', 'label' => 'Language', 'mode' => 'dictionary', 'domain' => 'iso639', 'required' => true],
            'Notes',
        ])]);
        $html = $this->submit_edit_form($field, '{}')->render();

        $this->assertSame(1, preg_match('~data-columns="([^"]*)"~', $html, $matches));
        $this->assertSame([
            [
                'key' => 'lang', 'label' => 'Language', 'mode' => 'dictionary', 'required' => true,
                // The admin may search any domain, so the column is searched whenever the companion can.
                'domain' => 'iso639', 'open' => false, 'autocomplete' => dictionaries::lookup_available(),
            ],
            ['key' => 'notes', 'label' => 'Notes', 'mode' => 'free', 'required' => false],
        ], json_decode(html_entity_decode($matches[1]), true));
        $this->assertSame(1, substr_count($html, 'class="profilefield-repeatable-required"'));
    }

    /**
     * A payload still keyed by version 1 labels reads like its re-keyed form, through the legacy labels.
     */
    public function test_reads_accept_label_keyed_payloads_while_legacy_labels_exist(): void {
        global $SCRIPT;

        $this->resetAfterTest();
        $this->setAdminUser();

        $field = $this->create_field([
            'param1' => $this->schema([
                ['key' => 'full_name', 'label' => 'Full name'],
                'role',
                ['label' => 'Required x', 'required' => true],
            ]),
        ]);
        $legacy = ['full_name' => 'Name (old)', 'required_x' => 'Req'];
        set_config(local\field_schema::legacy_config_name((int)$field->field->id), json_encode($legacy), 'profilefield_repeatable');
        $field = new \profile_field_repeatable(0, 0, $field->field);

        // Set 1 has the version 1 shape; set 2 was saved after the conversion, keyed by key.
        $field->data = '{"@@ID#1":{"Name (old)":"Alice","role":"Lead","Req":"yes"},'
            . '"@@ID#2":{"full_name":"Bob","role":"","required_x":"no","Name (old)":"ignored"}}';

        $loaded = new \stdClass();
        $field->edit_load_user_data($loaded);
        $this->assertSame(
            '{"@@ID#1":{"full_name":"Alice","role":"Lead","required_x":"yes"},'
                . '"@@ID#2":{"full_name":"Bob","role":"","required_x":"no"}}',
            $loaded->{$field->inputname}
        );
        $this->assertFalse($field->is_empty());

        $originalscript = $SCRIPT ?? null;
        try {
            $SCRIPT = '/user/edit.php';
            $output = $field->display_data();
        } finally {
            $SCRIPT = $originalscript;
        }
        $this->assertStringContainsString('Alice', $output);
        $this->assertStringNotContainsString('ignored', $output);

        // The raw caps see a value under its legacy label too.
        $oversized = str_repeat('a', payload::MAX_VALUE_LENGTH + 1);
        $errors = $field->edit_validate_field((object)[
            'id' => 0,
            $field->inputname => json_encode(['@@ID#1' => ['Req' => $oversized]]),
        ]);
        $this->assertSame(
            get_string('errorvaluetoolong', 'local_repeatable', payload::MAX_VALUE_LENGTH),
            $errors[$field->inputname]
        );

        // Without the legacy labels, the version 1 set is only "role".
        unset_config(local\field_schema::legacy_config_name((int)$field->field->id), 'profilefield_repeatable');
        $field = new \profile_field_repeatable(0, 0, $field->field);
        $field->data = '{"@@ID#1":{"Name (old)":"Alice","role":"Lead"}}';
        $loaded = new \stdClass();
        $field->edit_load_user_data($loaded);
        $this->assertSame('{"@@ID#1":{"full_name":"","role":"Lead","required_x":""}}', $loaded->{$field->inputname});
    }

    /**
     * A field still on the version 1 parameters reads its label-keyed payloads.
     */
    public function test_reads_accept_a_v1_field(): void {
        $this->resetAfterTest();

        $field = $this->create_field(['param1' => "Full name\nRole", 'param4' => 'Role']);
        $field->data = '{"@@ID#1":{"Full name":"Alice","Role":"Lead"}}';

        $loaded = new \stdClass();
        $field->edit_load_user_data($loaded);
        $this->assertSame('{"@@ID#1":{"full_name":"Alice","role":"Lead"}}', $loaded->{$field->inputname});
    }

    /**
     * A JSON null under a key counts as absent, so the legacy label is read, as the SQL reader does.
     */
    public function test_null_under_a_key_falls_back_to_the_legacy_label(): void {
        $this->resetAfterTest();

        $field = $this->create_field(['param1' => $this->schema([['key' => 'name', 'label' => 'Name'], 'role'])]);
        $config = local\field_schema::legacy_config_name((int)$field->field->id);
        set_config($config, '{"name":"Old name"}', 'profilefield_repeatable');
        $field = new \profile_field_repeatable(0, 0, $field->field);
        $field->data = '{"@@ID#1":{"name":null,"Old name":"Alice","role":null}}';

        $loaded = new \stdClass();
        $field->edit_load_user_data($loaded);
        $this->assertSame('{"@@ID#1":{"name":"Alice","role":""}}', $loaded->{$field->inputname});
    }
}
