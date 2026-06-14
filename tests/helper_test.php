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

/**
 * Tests for the profilefield_repeatable helper.
 *
 * @package    profilefield_repeatable
 * @covers     \profilefield_repeatable\helper
 * @copyright  2026 Anderson Blaine (anderson@blaine.com.br)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class helper_test extends \advanced_testcase {
    /**
     * Insert one storage row and return its id.
     *
     * @param string $data JSON payload for one set.
     * @return int
     */
    private function insert_set_row(string $data): int {
        global $DB;

        return (int)$DB->insert_record('profilefield_repeatable_data', (object) [
            'dataid' => 1,
            'fieldid' => 1,
            'userid' => 1,
            'set_id' => 1,
            'data' => $data,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Read one sub-item value back through the generated SQL expression.
     *
     * @param int $rowid Storage row id.
     * @param string $subitem Sub-item key to extract.
     * @return mixed
     */
    private function read_subitem(int $rowid, string $subitem) {
        global $DB;

        [$sql, $params] = helper::sql_subitem_value($DB, 'data', $subitem, 'k');
        $params['id'] = $rowid;

        return $DB->get_field_sql(
            "SELECT {$sql} FROM {profilefield_repeatable_data} WHERE id = :id",
            $params
        );
    }

    /**
     * The generated expression extracts a present sub-item value on every DB family.
     */
    public function test_sql_subitem_value_extracts_present_value(): void {
        $this->resetAfterTest();

        $rowid = $this->insert_set_row('{"diretoria":"16154","escola":"45"}');

        $this->assertSame('16154', $this->read_subitem($rowid, 'diretoria'));
        $this->assertSame('45', $this->read_subitem($rowid, 'escola'));
    }

    /**
     * A missing sub-item key resolves to an empty value rather than erroring.
     */
    public function test_sql_subitem_value_missing_key_is_empty(): void {
        $this->resetAfterTest();

        $rowid = $this->insert_set_row('{"diretoria":"16154"}');

        $this->assertEmpty($this->read_subitem($rowid, 'escola'));
    }

    /**
     * Keys containing spaces and unicode are handled through bound parameters.
     */
    public function test_sql_subitem_value_key_with_space_and_unicode(): void {
        $this->resetAfterTest();

        $rowid = $this->insert_set_row('{"Spoken language":"Português"}');

        $this->assertSame('Português', $this->read_subitem($rowid, 'Spoken language'));
    }

    /**
     * The reference-mapping parser keeps valid pairs and drops invalid lines.
     */
    public function test_parse_subitem_domain_map(): void {
        $subitems = ['Diretoria', 'Escola', 'Turma'];
        $raw = "Diretoria|diretorias\nEscola|escolas\nUnknown|whatever\nTurma|BAD DOMAIN";

        $map = helper::parse_subitem_domain_map($raw, $subitems);

        $this->assertSame([
            'Diretoria' => 'diretorias',
            'Escola' => 'escolas',
        ], $map);
    }

    /**
     * Sub-item matching is case-insensitive (keeping the canonical label), and
     * pipe-less lines plus duplicate sub-items are dropped (first mapping wins).
     */
    public function test_parse_subitem_domain_map_canonicalises_and_dedupes(): void {
        $raw = "DIRETORIA|Diretorias\ninvalidline\ndiretoria|duplicado";

        $map = helper::parse_subitem_domain_map($raw, ['Diretoria']);

        $this->assertSame(['Diretoria' => 'diretorias'], $map);
    }
}
