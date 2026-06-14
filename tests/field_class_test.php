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
require_once($CFG->dirroot . '/user/profile/field/repeatable/field.class.php');

/**
 * Tests for profile_field_repeatable.
 *
 * @package    profilefield_repeatable
 * @covers     \profile_field_repeatable
 * @copyright  2026 Anderson Blaine (anderson@blaine.com.br)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class field_class_test extends \advanced_testcase {
    /** @var string Sub-items: name and role. */
    private const SUBITEMS_NAME_ROLE = "name\nrole";

    /** @var string Sub-items: diretoria, escola, turma, disciplina. */
    private const SUBITEMS_DIR_ESC_TUR_DISC = "diretoria\nescola\nturma\ndisciplina";

    /** @var string Sub-items: codigo_turma, codigo_diretoria, codigo_escola. */
    private const SUBITEMS_COD_TUR_DIR_ESC = "codigo_turma\ncodigo_diretoria\ncodigo_escola";

    /** @var string Script path for profile page. */
    private const SCRIPT_PROFILE = '/user/profile.php';

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
            'param1' => self::SUBITEMS_NAME_ROLE,
        ], $extradata));

        return new \profile_field_repeatable(0, 0, $fielddata);
    }

    /**
     * Validate strict JSON object requirement.
     */
    public function test_edit_validate_field_invalid_json(): void {
        $this->resetAfterTest();

        $field = $this->create_field();
        $inputname = $field->inputname;

        $errors = $field->edit_validate_field((object) [
            'id' => 0,
            $inputname => '[]',
        ]);

        $this->assertArrayHasKey($inputname, $errors);
        $this->assertSame(get_string('errorinvalidjson', 'profilefield_repeatable'), $errors[$inputname]);
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
     * Ensure preprocessing persists normalised strict JSON.
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
            'param1' => self::SUBITEMS_DIR_ESC_TUR_DISC,
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
            'param1' => self::SUBITEMS_DIR_ESC_TUR_DISC,
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
     * Ensure normalisation removes empty sets after completing missing sub-items.
     */
    public function test_normalise_payload_discards_empty_sets_after_completion(): void {
        $this->resetAfterTest();

        $field = $this->create_field([
            'param1' => self::SUBITEMS_DIR_ESC_TUR_DISC,
        ]);

        $method = new \ReflectionMethod(\profile_field_repeatable::class, 'normalise_payload');
        $method->setAccessible(true);

        $payload = $method->invoke(
            $field,
            '{"@@ID#10":{"diretoria":"","escola":"","turma":"","disciplina":""},' .
            '"@@ID#20":{"diretoria":"16154","escola":"45","turma":"3"}}'
        );

        $this->assertSame([
            '@@ID#1' => [
                'diretoria' => '16154',
                'escola' => '45',
                'turma' => '3',
                'disciplina' => '',
            ],
        ], $payload);
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
            'param1' => self::SUBITEMS_NAME_ROLE,
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
            'param1' => self::SUBITEMS_NAME_ROLE,
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
            'param1' => self::SUBITEMS_COD_TUR_DIR_ESC,
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
            'param1' => self::SUBITEMS_COD_TUR_DIR_ESC,
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

        $field = new \profile_field_repeatable(0, $user->id, $fielddata);

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
     * Ensure profile accordion hides sync footer when timestamp is unavailable.
     */
    public function test_display_data_profile_context_hides_sync_footer_without_timestamp(): void {
        global $SCRIPT;

        $this->resetAfterTest();

        $field = $this->create_field([
            'param1' => self::SUBITEMS_COD_TUR_DIR_ESC,
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
        $this->assertStringNotContainsString('profilefield-repeatable-display-sync', $output);
    }

    /**
     * Ensure profile accordion title falls back to "Set N" when first sub-item is empty.
     */
    public function test_display_data_profile_context_falls_back_to_set_title_when_first_subitem_empty(): void {
        global $SCRIPT;

        $this->resetAfterTest();

        $field = $this->create_field([
            'param1' => self::SUBITEMS_COD_TUR_DIR_ESC,
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
            'param1' => "codigo_diretoria\ncodigo_escola",
            'param3' => "codigo_diretoria|diretoria",
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
        global $DB, $SCRIPT;

        $this->resetAfterTest();
        $this->setAdminUser();

        if (!class_exists('\\local_profilefield_repeatable\\local\\manager')) {
            $this->markTestSkipped('local_profilefield_repeatable plugin is not available.');
        }

        if (
            !$DB->get_manager()->table_exists(new \xmldb_table('local_profilefield_repeatable_domain')) ||
            !$DB->get_manager()->table_exists(new \xmldb_table('local_profilefield_repeatable_item'))
        ) {
            $this->markTestSkipped('local_profilefield_repeatable tables are not available.');
        }

        $manager = new \local_profilefield_repeatable\local\Manager();
        $manager->upsert_domain('diretoria', 'Diretoria');
        $manager->upsert_items('diretoria', [[
            'code' => '16',
            'label' => 'Sao Paulo',
        ]]);

        $field = $this->create_field([
            'param1' => "codigo_diretoria\ncodigo_escola",
            'param3' => "codigo_diretoria|diretoria",
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
        global $DB, $SCRIPT;

        $this->resetAfterTest();
        $this->setAdminUser();

        if (!class_exists('\\local_profilefield_repeatable\\local\\manager')) {
            $this->markTestSkipped('local_profilefield_repeatable plugin is not available.');
        }

        if (
            !$DB->get_manager()->table_exists(new \xmldb_table('local_profilefield_repeatable_domain')) ||
            !$DB->get_manager()->table_exists(new \xmldb_table('local_profilefield_repeatable_item'))
        ) {
            $this->markTestSkipped('local_profilefield_repeatable tables are not available.');
        }

        $manager = new \local_profilefield_repeatable\local\manager();
        $manager->upsert_domain('diretoria', 'Diretoria');
        $manager->upsert_items('diretoria', [[
            'code' => '16',
            'label' => 'Sao Paulo',
        ]]);

        $field = $this->create_field([
            'param1' => "codigo_diretoria\ncodigo_escola",
            'param3' => "codigo_diretoria|diretoria",
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
            'param1' => "codigo_diretoria\ncodigo_escola",
            'param3' => "codigo_diretoria|diretoria",
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
            'param1' => "codigo_turma\ncodigo_diretoria\ncodigo_escola",
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
     * Ensure accordion resolves reference labels when field is configured via define_save_preprocess.
     */
    public function test_display_data_profile_resolves_reference_with_preprocessed_param3(): void {
        global $DB, $SCRIPT;

        $this->resetAfterTest();
        $this->setAdminUser();

        if (!class_exists('\\local_profilefield_repeatable\\local\\manager')) {
            $this->markTestSkipped('local_profilefield_repeatable plugin is not available.');
        }

        if (
            !$DB->get_manager()->table_exists(new \xmldb_table('local_profilefield_repeatable_domain')) ||
            !$DB->get_manager()->table_exists(new \xmldb_table('local_profilefield_repeatable_item'))
        ) {
            $this->markTestSkipped('local_profilefield_repeatable tables are not available.');
        }

        $manager = new \local_profilefield_repeatable\local\manager();
        $manager->upsert_domain('diretorias', 'Diretorias');
        $manager->upsert_items('diretorias', [[
            'code' => '16',
            'label' => 'Sao Paulo',
        ]]);
        $manager->upsert_domain('escolas', 'Escolas');
        $manager->upsert_items('escolas', [[
            'code' => '45',
            'label' => 'EE Alpha',
        ]]);

        // Simulate define_save_preprocess to canonicalise param3.
        $define = new \profile_define_repeatable();
        $preprocessed = $define->define_save_preprocess((object) [
            'param1' => "Codigo_Diretoria\nCodigo_Escola",
            'param2' => '',
            'param3' => "codigo_diretoria|Diretorias\ncodigo_escola|ESCOLAS",
        ]);

        $fielddata = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'repeatable',
            'name' => 'Preprocessed test',
            'shortname' => 'repeatable_preproc_test',
            'required' => 0,
            'forceunique' => 0,
            'defaultdata' => '{}',
            'param1' => $preprocessed->param1,
            'param3' => $preprocessed->param3,
        ]);

        $field = new \profile_field_repeatable(0, 0, $fielddata);
        $field->data = '{"@@ID#1":{"Codigo_Diretoria":"16","Codigo_Escola":"45"}}';

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
     * Build a JSON payload with the requested number of sets.
     *
     * @param int $count
     * @return string
     */
    private function build_payload_with_sets(int $count): string {
        $sets = [];
        for ($i = 1; $i <= $count; $i++) {
            $sets['@@ID#' . $i] = ['name' => 'N' . $i, 'role' => 'R' . $i];
        }

        return json_encode($sets);
    }

    /**
     * Validation must reject payloads with more sets than the configured maximum.
     */
    public function test_edit_validate_field_rejects_too_many_sets(): void {
        $this->resetAfterTest();

        $field = $this->create_field();
        $inputname = $field->inputname;

        $errors = $field->edit_validate_field((object) [
            'id' => 0,
            $inputname => $this->build_payload_with_sets(\profilefield_repeatable\helper::MAX_SETS + 1),
        ]);

        $this->assertArrayHasKey($inputname, $errors);
        $this->assertSame(
            get_string('errortoomanysets', 'profilefield_repeatable', \profilefield_repeatable\helper::MAX_SETS),
            $errors[$inputname]
        );
    }

    /**
     * Validation must reject sub-item values longer than the configured maximum.
     */
    public function test_edit_validate_field_rejects_oversized_value(): void {
        $this->resetAfterTest();

        $field = $this->create_field();
        $inputname = $field->inputname;

        $oversized = str_repeat('a', \profilefield_repeatable\helper::MAX_VALUE_LENGTH + 1);
        $errors = $field->edit_validate_field((object) [
            'id' => 0,
            $inputname => json_encode(['@@ID#1' => ['name' => $oversized, 'role' => 'X']]),
        ]);

        $this->assertArrayHasKey($inputname, $errors);
        $this->assertSame(
            get_string('errorvaluetoolong', 'profilefield_repeatable', \profilefield_repeatable\helper::MAX_VALUE_LENGTH),
            $errors[$inputname]
        );
    }

    /**
     * Normalisation must defensively cap set count and value length on non-form paths.
     */
    public function test_normalise_payload_enforces_caps(): void {
        $subitems = ['name', 'role'];

        $payload = json_decode($this->build_payload_with_sets(\profilefield_repeatable\helper::MAX_SETS + 5), true);
        $normalised = \profilefield_repeatable\helper::normalise_payload($payload, $subitems);
        $this->assertCount(\profilefield_repeatable\helper::MAX_SETS, $normalised);

        $oversized = str_repeat('b', \profilefield_repeatable\helper::MAX_VALUE_LENGTH + 50);
        $normalised = \profilefield_repeatable\helper::normalise_payload(
            ['@@ID#1' => ['name' => $oversized, 'role' => '']],
            $subitems
        );
        $first = reset($normalised);
        $this->assertSame(\profilefield_repeatable\helper::MAX_VALUE_LENGTH, \core_text::strlen($first['name']));
    }

    /**
     * Repeated storage reads on one field instance must be memoised.
     */
    public function test_is_empty_memoises_storage_reads(): void {
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
        $this->assertFalse($field->is_empty());

        $before = $DB->perf_get_reads();
        $this->assertFalse($field->is_empty());
        $this->assertSame(0, $DB->perf_get_reads() - $before, 'Second is_empty() must not query the database again.');
    }

    /**
     * The display renderer must consume preloaded storage records without querying.
     */
    public function test_display_renderer_uses_preloaded_records(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $fielddata = $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'repeatable',
            'name' => 'Preload test',
            'shortname' => 'repeatable_preload_test',
            'required' => 0,
            'forceunique' => 0,
            'defaultdata' => '{}',
            'param1' => self::SUBITEMS_NAME_ROLE,
        ]);

        $records = [
            (object) [
                'id' => 1,
                'set_id' => 1,
                'data' => json_encode(['name' => 'Preloaded', 'role' => 'Ghost']),
                'timemodified' => 0,
            ],
        ];

        $renderer = new \profilefield_repeatable\output\display_renderer(
            $fielddata,
            999999,
            '',
            null,
            null,
            $records
        );

        $output = $renderer->render();
        $this->assertStringContainsString('Preloaded', $output);
        $this->assertStringContainsString('Ghost', $output);
    }
}
