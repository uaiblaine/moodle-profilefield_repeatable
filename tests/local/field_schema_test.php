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

use local_repeatable\local\payload;
use local_repeatable\local\schema;

/**
 * Tests for the profile adapter's schema reader: version 1 parameters and the stored field record.
 *
 * The version 2 schema itself is tested in local_repeatable (local\schema_test). Metadata stays in
 * docblocks: the plugin supports Moodle 4.5, whose moodle-cs cannot read PHPUnit attributes.
 *
 * @package    profilefield_repeatable
 * @category   test
 * @covers     \profilefield_repeatable\local\field_schema
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class field_schema_test extends \advanced_testcase {
    /**
     * Version 1 parameters become the equivalent schema, remembering each label; the indexed list is ignored.
     */
    public function test_from_v1_converts_the_four_lists(): void {
        $schema = field_schema::from_v1(
            "Língua\nNível\nCertificate number\nnível\nÓrgão emissor\nCidade",
            "língua\nCidade\nnope\nNível\nCertificate number",
            "Língua|ISO639\nNível|cefr_levels\nCidade|Bad-Domain",
            "Língua\nnível\nNível\nCidade\nÓrgão emissor\nCertificate number"
        );

        $this->assertSame([
            ['key' => 'lingua', 'label' => 'Língua', 'mode' => 'dictionary', 'domain' => 'iso639', 'title' => true],
            ['key' => 'nivel', 'label' => 'Nível', 'mode' => 'dictionary', 'domain' => 'cefr_levels', 'subline' => true],
            ['key' => 'certificate_number', 'label' => 'Certificate number', 'mode' => 'free'],
            ['key' => 'orgao_emissor', 'label' => 'Órgão emissor', 'mode' => 'free', 'subline' => true],
            ['key' => 'cidade', 'label' => 'Cidade', 'mode' => 'free', 'subline' => true],
        ], $schema->columns());
        $this->assertSame([
            'lingua' => 'Língua',
            'nivel' => 'Nível',
            'certificate_number' => 'Certificate number',
            'orgao_emissor' => 'Órgão emissor',
            'cidade' => 'Cidade',
        ], $schema->legacy_labels());
        $this->assertSame([], schema::validate($schema->to_document()));
    }

    /**
     * Labels that fold to the same key get unique keys; a label equal to its key leaves no legacy label.
     */
    public function test_from_v1_makes_keys_unique(): void {
        $schema = field_schema::from_v1("name\nNamé\nname!\n2nd\nLang\nlang name");

        $this->assertSame(['name', 'name_2', 'name_3', 'column_2nd', 'lang', 'lang_name_2'], $schema->keys());
        $this->assertArrayNotHasKey('name', $schema->legacy_labels());
        $this->assertSame('Namé', $schema->legacy_labels()['name_2']);
    }

    /**
     * A field record yields its version 2 schema with the legacy labels of the plugin config, or its version 1 schema.
     */
    public function test_from_field(): void {
        $this->resetAfterTest();
        $json = schema::from_columns([['key' => 'name', 'label' => 'Full name', 'title' => true]])->to_json();

        $field = (object)['id' => 7, 'param1' => $json];
        $this->assertSame([], field_schema::from_field($field)->legacy_labels());

        set_config(field_schema::legacy_config_name(7), json_encode(['name' => 'Full name']), 'profilefield_repeatable');
        $this->assertSame(['name' => 'Full name'], field_schema::from_field($field)->legacy_labels());
        $this->assertSame([], field_schema::from_field((object)['id' => 8, 'param1' => $json])->legacy_labels());

        $v1 = field_schema::from_field((object)['id' => 9, 'param1' => "Full name\nRole", 'param3' => 'Role|roles']);
        $this->assertSame(['full_name', 'role'], $v1->keys());
        $this->assertSame(['role' => 'roles'], $v1->domain_map());
        $this->assertSame(['full_name' => 'Full name', 'role' => 'Role'], $v1->legacy_labels());
    }

    /**
     * A generated key never equals another column's version 1 label, so v1 sets read the right value.
     */
    public function test_from_v1_keys_never_equal_another_label(): void {
        $schema = field_schema::from_v1("A B\na_b\nX\nx_name");

        $this->assertSame(['a_b_2', 'a_b', 'x_2', 'x_name'], $schema->keys());
        $payload = payload::normalise(
            '{"@@ID#1":{"A B":"first","a_b":"second","X":"third","x_name":"fourth"}}',
            $schema
        );
        $this->assertSame(['a_b_2' => 'first', 'a_b' => 'second', 'x_2' => 'third', 'x_name' => 'fourth'], $payload['@@ID#1']);
    }

    /**
     * The reference-mapping parser keeps valid pairs and drops invalid lines.
     */
    public function test_parse_subitem_domain_map(): void {
        $subitems = ['Diretoria', 'Escola', 'Turma'];
        $raw = "Diretoria|diretorias\nEscola|escolas\nUnknown|whatever\nTurma|BAD DOMAIN";

        $map = field_schema::parse_subitem_domain_map($raw, $subitems);

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

        $map = field_schema::parse_subitem_domain_map($raw, ['Diretoria']);

        $this->assertSame(['Diretoria' => 'diretorias'], $map);
    }
}
