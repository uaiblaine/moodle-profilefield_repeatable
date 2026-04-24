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
require_once($CFG->dirroot . '/user/profile/definelib.php');
require_once($CFG->dirroot . '/user/profile/field/repeatable/define.class.php');

/**
 * Tests for profile_define_repeatable.
 *
 * @package    profilefield_repeatable
 * @covers     \profile_define_repeatable
 * @copyright  2026 Anderson Blaine (anderson@blaine.com.br)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class define_class_test extends \advanced_testcase {
    /** @var string Sub-items: diretoria, escola. */
    private const SUBITEMS_DIR_ESC = "diretoria\nescola";

    /**
     * Validate empty sub-item configuration.
     */
    public function test_define_validate_specific_requires_subitems(): void {
        $define = new \profile_define_repeatable();

        $errors = $define->define_validate_specific((object) [
            'param1' => "\n   \n",
        ], []);

        $this->assertArrayHasKey('param1', $errors);
        $this->assertEquals(get_string('errorsubitemsrequired', 'profilefield_repeatable'), $errors['param1']);
    }

    /**
     * Validate duplicate sub-items in configuration.
     */
    public function test_define_validate_specific_rejects_duplicate_subitems(): void {
        $define = new \profile_define_repeatable();

        $errors = $define->define_validate_specific((object) [
            'param1' => "Name\nRole\nname",
        ], []);

        $this->assertArrayHasKey('param1', $errors);
        $this->assertEquals(get_string('errorsubitemsduplicate', 'profilefield_repeatable'), $errors['param1']);
    }

    /**
     * Validate indexed sub-items must exist in the configured sub-items list.
     */
    public function test_define_validate_specific_rejects_unknown_indexed_subitems(): void {
        $define = new \profile_define_repeatable();

        $errors = $define->define_validate_specific((object) [
            'param1' => "diretoria\nescola\nturma",
            'param2' => "escola\nnaoexiste",
        ], []);

        $this->assertArrayHasKey('param2', $errors);
        $this->assertEquals(
            get_string('errorindexedsubitemunknown', 'profilefield_repeatable', 'naoexiste'),
            $errors['param2']
        );
    }

    /**
     * Validate indexed sub-items max limit.
     */
    public function test_define_validate_specific_rejects_indexed_subitems_limit(): void {
        $define = new \profile_define_repeatable();

        $errors = $define->define_validate_specific((object) [
            'param1' => "a\nb\nc\nd",
            'param2' => "a\nb\nc\nd",
        ], []);

        $this->assertArrayHasKey('param2', $errors);
        $this->assertEquals(
            get_string('errorindexedsubitemlimit', 'profilefield_repeatable', 3),
            $errors['param2']
        );
    }

    /**
     * Ensure save preprocess canonicalises and deduplicates indexed sub-items.
     */
    public function test_define_save_preprocess_normalises_indexed_subitems(): void {
        $define = new \profile_define_repeatable();

        $processed = $define->define_save_preprocess((object) [
            'param1' => "Diretoria\nEscola\nTurma\nDisciplina",
            'param2' => "escola\nDIRETORIA\nescola\nturma\ndisciplina",
        ]);

        $this->assertEquals("Diretoria\nEscola\nTurma\nDisciplina", $processed->param1);
        $this->assertEquals("Escola\nDiretoria\nTurma", $processed->param2);
    }

    /**
     * Validate reference mapping format.
     */
    public function test_define_validate_specific_rejects_invalid_reference_mapping_format(): void {
        $define = new \profile_define_repeatable();

        $errors = $define->define_validate_specific((object) [
            'param1' => self::SUBITEMS_DIR_ESC,
            'param3' => "diretoria-diretoria",
        ], []);

        $this->assertArrayHasKey('param3', $errors);
        $this->assertStringContainsString('diretoria-diretoria', $errors['param3']);
    }

    /**
     * Validate reference mapping sub-item must exist in param1.
     */
    public function test_define_validate_specific_rejects_unknown_reference_mapping_subitem(): void {
        $define = new \profile_define_repeatable();

        $errors = $define->define_validate_specific((object) [
            'param1' => self::SUBITEMS_DIR_ESC,
            'param3' => "turma|diretoria",
        ], []);

        $this->assertArrayHasKey('param3', $errors);
        $this->assertStringContainsString('turma', $errors['param3']);
    }

    /**
     * Validate reference mapping does not allow duplicate sub-item entries.
     */
    public function test_define_validate_specific_rejects_duplicate_reference_mapping_subitem(): void {
        $define = new \profile_define_repeatable();

        $errors = $define->define_validate_specific((object) [
            'param1' => self::SUBITEMS_DIR_ESC,
            'param3' => "diretoria|dom_a\ndiretoria|dom_b",
        ], []);

        $this->assertArrayHasKey('param3', $errors);
        $this->assertStringContainsString('diretoria', $errors['param3']);
    }

    /**
     * Validate reference mapping domain syntax.
     */
    public function test_define_validate_specific_rejects_invalid_reference_mapping_domain(): void {
        $define = new \profile_define_repeatable();

        $errors = $define->define_validate_specific((object) [
            'param1' => self::SUBITEMS_DIR_ESC,
            'param3' => "diretoria|Dominio-Invalido",
        ], []);

        $this->assertArrayHasKey('param3', $errors);
        $this->assertStringContainsString('Dominio-Invalido', $errors['param3']);
    }

    /**
     * Ensure save preprocess canonicalises reference mappings.
     */
    public function test_define_save_preprocess_normalises_reference_mappings(): void {
        $define = new \profile_define_repeatable();

        $processed = $define->define_save_preprocess((object) [
            'param1' => "Diretoria\nEscola\nTurma",
            'param3' => "escola|ESCOLAS\ndiretoria|Diretorias\ndiretoria|duplicado",
        ]);

        $this->assertEquals("Diretoria\nEscola\nTurma", $processed->param1);
        $this->assertEquals("Diretoria|diretorias\nEscola|escolas", $processed->param3);
    }

    /**
     * Validate subline sub-items must exist in param1.
     */
    public function test_define_validate_specific_rejects_unknown_subline_subitem(): void {
        $define = new \profile_define_repeatable();

        $errors = $define->define_validate_specific((object) [
            'param1' => "diretoria\nescola\nturma",
            'param4' => "escola\ninexistente",
        ], []);

        $this->assertArrayHasKey('param4', $errors);
        $this->assertStringContainsString('inexistente', $errors['param4']);
    }

    /**
     * Validate subline sub-items reject duplicates.
     */
    public function test_define_validate_specific_rejects_duplicate_subline_subitem(): void {
        $define = new \profile_define_repeatable();

        $errors = $define->define_validate_specific((object) [
            'param1' => "diretoria\nescola\nturma",
            'param4' => "escola\nESCOLA",
        ], []);

        $this->assertArrayHasKey('param4', $errors);
        $this->assertStringContainsString('escola', $errors['param4']);
    }

    /**
     * Validate subline cannot include the primary (title) sub-item.
     */
    public function test_define_validate_specific_rejects_primary_in_subline(): void {
        $define = new \profile_define_repeatable();

        $errors = $define->define_validate_specific((object) [
            'param1' => "diretoria\nescola\nturma",
            'param4' => "Diretoria",
        ], []);

        $this->assertArrayHasKey('param4', $errors);
        $this->assertStringContainsString('diretoria', $errors['param4']);
    }

    /**
     * Validate subline subitems max limit.
     */
    public function test_define_validate_specific_rejects_subline_subitems_limit(): void {
        $define = new \profile_define_repeatable();

        $errors = $define->define_validate_specific((object) [
            'param1' => "a\nb\nc\nd\ne",
            'param4' => "b\nc\nd\ne",
        ], []);

        $this->assertArrayHasKey('param4', $errors);
        $this->assertStringContainsString('3', $errors['param4']);
    }

    /**
     * Subline empty configuration is allowed.
     */
    public function test_define_validate_specific_allows_empty_subline(): void {
        $define = new \profile_define_repeatable();

        $errors = $define->define_validate_specific((object) [
            'param1' => self::SUBITEMS_DIR_ESC,
            'param4' => "\n  \n",
        ], []);

        $this->assertArrayNotHasKey('param4', $errors);
    }

    /**
     * Save preprocess canonicalises subline sub-items.
     */
    public function test_define_save_preprocess_normalises_subline_subitems(): void {
        $define = new \profile_define_repeatable();

        $processed = $define->define_save_preprocess((object) [
            'param1' => "Diretoria\nEscola\nTurma\nDisciplina",
            'param4' => "escola\nTURMA\nescola",
        ]);

        $this->assertEquals("Escola\nTurma", $processed->param4);
    }
}
