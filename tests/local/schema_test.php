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
 * Tests for the version 2 column schema.
 *
 * Metadata stays in docblocks: the plugin supports Moodle 4.5, whose moodle-cs cannot read
 * PHPUnit attributes.
 *
 * @package    profilefield_repeatable
 * @category   test
 * @covers     \profilefield_repeatable\local\schema
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class schema_test extends \advanced_testcase {
    /**
     * A valid document with some columns.
     *
     * @param array $columns
     * @return array
     */
    private function document(array $columns): array {
        return ['version' => 2, 'columns' => $columns];
    }

    /**
     * A valid two-column document: a free title and a dictionary.
     *
     * @return array
     */
    private function valid_columns(): array {
        return [
            ['key' => 'language', 'label' => 'Language', 'mode' => 'dictionary', 'domain' => 'iso639', 'title' => true],
            ['key' => 'level', 'label' => 'Level', 'mode' => 'free', 'subline' => true],
        ];
    }

    /**
     * Columns are normalised: trimmed strings, lower-case domain, settings of other modes dropped.
     */
    public function test_from_json_normalises_columns(): void {
        $schema = schema::from_json(json_encode($this->document([
            ['key' => ' language ', 'label' => ' Language ', 'mode' => 'dictionary', 'domain' => ' ISO639 ',
                'parent' => '', 'title' => 1, 'subline' => 0],
            ['key' => 'notes', 'label' => 'Notes', 'domain' => 'ignored', 'parent' => 'language', 'required' => true],
            'not a column',
        ])));

        $this->assertSame([
            ['key' => 'language', 'label' => 'Language', 'mode' => 'dictionary', 'domain' => 'iso639', 'title' => true],
            ['key' => 'notes', 'label' => 'Notes', 'mode' => 'free', 'required' => true],
        ], $schema->columns(), 'The entry that is no column has no valid key and is dropped');
        $this->assertDebuggingCalled();
        $this->assertSame(
            '{"version":2,"columns":[{"key":"language","label":"Language","mode":"dictionary","domain":"iso639","title":true},'
                . '{"key":"notes","label":"Notes","mode":"free","required":true}]}',
            $schema->to_json()
        );
    }

    /**
     * Text that is not a version 2 document is no schema.
     */
    public function test_from_json_rejects_other_text(): void {
        $this->assertNull(schema::from_json(''));
        $this->assertNull(schema::from_json("name\nrole"));
        $this->assertNull(schema::from_json('{"version":1,"columns":[]}'));
        $this->assertNull(schema::from_json('{"version":"2","columns":[]}'));
        $this->assertNull(schema::from_json('{broken'));
        $this->assertTrue(schema::from_json('{"version":2}')->is_empty());
    }

    /**
     * Version 1 parameters become the equivalent schema, remembering each label.
     */
    public function test_from_v1_converts_the_four_lists(): void {
        $schema = schema::from_v1(
            "Língua\nNível\nCertificate number\nnível\nÓrgão emissor\nCidade",
            "língua\nCidade\nnope\nNível\nCertificate number",
            "Língua|ISO639\nNível|cefr_levels\nCidade|Bad-Domain",
            "Língua\nnível\nNível\nCidade\nÓrgão emissor\nCertificate number"
        );

        $this->assertSame([
            ['key' => 'lingua', 'label' => 'Língua', 'mode' => 'dictionary', 'domain' => 'iso639', 'title' => true,
                'indexed' => true],
            ['key' => 'nivel', 'label' => 'Nível', 'mode' => 'dictionary', 'domain' => 'cefr_levels', 'subline' => true,
                'indexed' => true],
            ['key' => 'certificate_number', 'label' => 'Certificate number', 'mode' => 'free'],
            ['key' => 'orgao_emissor', 'label' => 'Órgão emissor', 'mode' => 'free', 'subline' => true],
            ['key' => 'cidade', 'label' => 'Cidade', 'mode' => 'free', 'subline' => true, 'indexed' => true],
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
        $schema = schema::from_v1("name\nNamé\nname!\n2nd\nLang\nlang name");

        $this->assertSame(['name', 'name_2', 'name_3', 'column_2nd', 'lang', 'lang_name_2'], $schema->keys());
        $this->assertArrayNotHasKey('name', $schema->legacy_labels());
        $this->assertSame('Namé', $schema->legacy_labels()['name_2']);
    }

    /**
     * Key generation folds, cleans, prefixes and caps, and steps around the identifiers in use.
     *
     * @dataProvider generate_key_provider
     * @param string $label
     * @param array $taken
     * @param string $expected
     */
    public function test_generate_key(string $label, array $taken, string $expected): void {
        $key = schema::generate_key($label, $taken);
        $this->assertSame($expected, $key);
        $this->assertMatchesRegularExpression(schema::KEY_PATTERN, $key);
    }

    /**
     * Labels and the keys they generate.
     *
     * @return array
     */
    public static function generate_key_provider(): array {
        $long = str_repeat('abcdefghij', 5);
        return [
            'accents and spaces' => ['Certificação de Língua', [], 'certificacao_de_lingua'],
            'symbols trimmed' => ['  --Start: date?!  ', [], 'start_date'],
            'leading digit' => ['2nd language', [], 'column_2nd_language'],
            'nothing usable' => ['¿¡ !', [], 'column'],
            'capped at 40' => [$long, [], substr($long, 0, 40)],
            'suffix within the cap' => [$long, [substr($long, 0, 40)], substr($long, 0, 38) . '_2'],
            'taken key' => ['Name', ['name', 'name_name', 'name_code'], 'name_2'],
            'taken report name' => ['Lang', ['lang_name'], 'lang_2'],
            'reserved set number' => ['Setnumber', [], 'setnumber_2'],
            'trailing underscore after the cap' => [str_repeat('a', 39) . ' b', [], str_repeat('a', 39)],
        ];
    }

    /**
     * A valid document passes every rule.
     */
    public function test_validate_accepts_a_valid_schema(): void {
        $this->resetAfterTest();
        $columns = $this->valid_columns();
        $columns[] = ['key' => 'city', 'label' => 'City', 'mode' => 'dictionary_open', 'domain' => 'cities',
            'parent' => 'language', 'indexed' => true, 'required' => true];

        $this->assertSame([], schema::validate($this->document($columns), static fn(string $domain): bool => true));
    }

    /**
     * Each broken rule yields its own message.
     *
     * @dataProvider validate_provider
     * @param array|null $columns Columns, or null for the whole document in $document.
     * @param string $identifier The expected error string.
     * @param mixed $a Its parameter.
     * @param mixed $document A raw document, when $columns is null.
     */
    public function test_validate_rejects(?array $columns, string $identifier, $a = null, $document = null): void {
        $this->resetAfterTest();
        $document = $columns === null ? $document : $this->document($columns);
        $domainexists = static fn(string $domain): bool => $domain !== 'missing';

        $errors = schema::validate($document, $domainexists);

        $this->assertContains(get_string($identifier, 'profilefield_repeatable', $a), $errors);
    }

    /**
     * Broken schemas and the message each must produce.
     *
     * @return array
     */
    public static function validate_provider(): array {
        $title = ['key' => 'name', 'label' => 'Name', 'mode' => 'free', 'title' => true];
        $free = fn(string $key, array $extra = []): array => $extra + ['key' => $key, 'label' => ucfirst($key), 'mode' => 'free'];
        $dictionary = fn(string $key, array $extra = []): array =>
            $extra + ['key' => $key, 'label' => ucfirst($key), 'mode' => 'dictionary', 'domain' => 'dom'];
        return [
            'not an array' => [null, 'errorschemainvalid', null, 'text'],
            'wrong version' => [null, 'errorschemainvalid', null, ['version' => 1, 'columns' => [$title]]],
            'columns not a list' => [null, 'errorschemainvalid', null, ['version' => 2, 'columns' => ['a' => $title]]],
            'column not an object' => [null, 'errorschemainvalid', null, ['version' => 2, 'columns' => [$title, 'x']]],
            'no column' => [[], 'errorcolumnsrequired'],
            'too many columns' => [
                array_map(fn(int $i): array => $free('c' . $i, ['title' => $i === 0]), range(0, 20)),
                'errorcolumnslimit',
                20,
            ],
            'no label' => [[$title, ['key' => 'x', 'label' => ' ', 'mode' => 'free']], 'errorlabelrequired', 2],
            'duplicate label' => [[$title, ['key' => 'other', 'label' => 'NAME', 'mode' => 'free']], 'errorlabelduplicate', 'NAME'],
            'key with upper case' => [[$title, $free('Bad')], 'errorkeyinvalid', 'Bad'],
            'key starting with a digit' => [[$title, $free('1abc')], 'errorkeyinvalid', '1abc'],
            'key too long' => [[$title, $free(str_repeat('a', 41))], 'errorkeyinvalid', str_repeat('a', 41)],
            'escaped key' => [[$title, $free('<b>')], 'errorkeyinvalid', '&lt;b&gt;'],
            'duplicate key' => [[$title, $free('name')], 'errorkeyduplicate', 'name'],
            'pattern not offered yet' => [[$title, $free('code', ['mode' => 'pattern'])], 'errormodeinvalid', 'Code'],
            'unknown mode' => [[$title, $free('code', ['mode' => 'select'])], 'errormodeinvalid', 'Code'],
            'dictionary without domain' => [[$title, $dictionary('lang', ['domain' => ''])], 'errordomaininvalid', 'Lang'],
            'malformed domain' => [[$title, $dictionary('lang', ['domain' => 'bad-domain'])], 'errordomaininvalid', 'Lang'],
            'missing domain' => [[$title, $dictionary('lang', ['domain' => 'missing'])], 'errordomainmissing', 'missing'],
            'parent later' => [
                [$title, $dictionary('city', ['parent' => 'state']), $dictionary('state')],
                'errorparentinvalid',
                'City',
            ],
            'parent free' => [[$title, $dictionary('city', ['parent' => 'name'])], 'errorparentinvalid', 'City'],
            'parent unknown' => [[$title, $dictionary('city', ['parent' => 'nope'])], 'errorparentinvalid', 'City'],
            'parent itself' => [[$title, $dictionary('city', ['parent' => 'city'])], 'errorparentinvalid', 'City'],
            'no title' => [[$free('a'), $free('b')], 'errortitlecount'],
            'two titles' => [[$title, $free('b', ['title' => true])], 'errortitlecount'],
            'subline title' => [[$title + ['subline' => true]], 'errorsublinetitle', 'Name'],
            'subline limit' => [
                [$title, $free('a', ['subline' => true]), $free('b', ['subline' => true]), $free('c', ['subline' => true]),
                    $free('d', ['subline' => true])],
                'errorsublinelimit',
                3,
            ],
            'indexed limit' => [
                [$title + ['indexed' => true], $free('a', ['indexed' => true]), $free('b', ['indexed' => true]),
                    $free('c', ['indexed' => true])],
                'errorindexedlimit',
                3,
            ],
            'report name taken' => [[$title, $dictionary('lang'), $free('lang_name')], 'errorkeyconflict', 'lang_name'],
            'report code taken' => [[$title, $free('lang_code'), $dictionary('lang')], 'errorkeyconflict', 'lang_code'],
            'set number taken' => [[$title, $free('setnumber')], 'errorkeyconflict', 'setnumber'],
        ];
    }

    /**
     * Without the companion check, any well-formed domain passes; the check is asked only for those.
     */
    public function test_validate_asks_for_well_formed_domains_only(): void {
        $this->resetAfterTest();
        $asked = [];
        $domainexists = function (string $domain) use (&$asked): bool {
            $asked[] = $domain;
            return true;
        };
        $columns = $this->valid_columns();
        $columns[] = ['key' => 'other', 'label' => 'Other', 'mode' => 'dictionary', 'domain' => 'Bad Domain'];

        schema::validate($this->document($columns), $domainexists);
        $this->assertSame(['iso639'], $asked);
        $this->assertSame([], schema::validate($this->document($this->valid_columns())));
    }

    /**
     * A parent column on another dictionary than the one holding the parents is refused, when that dictionary is known.
     */
    public function test_validate_refuses_a_parent_on_the_wrong_dictionary(): void {
        $this->resetAfterTest();
        $columns = [
            ['key' => 'name', 'label' => 'Name', 'mode' => 'free', 'title' => true],
            ['key' => 'lang', 'label' => 'Language', 'mode' => 'dictionary', 'domain' => 'langs'],
            ['key' => 'state', 'label' => 'State', 'mode' => 'dictionary', 'domain' => 'uf'],
            ['key' => 'city', 'label' => 'C&ity', 'mode' => 'dictionary_open', 'domain' => 'cities', 'parent' => 'lang'],
        ];
        $mismatch = get_string('errorparentmismatch', 'profilefield_repeatable', (object)[
            'column' => 'C&amp;ity',
            'domain' => 'cities',
            'parentdomain' => 'uf',
        ]);

        $this->assertSame([$mismatch], schema::validate($this->document($columns), null, [], ['cities' => 'uf']));
        // Control: the parent column on the dictionary holding the parents passes.
        $columns[3]['parent'] = 'state';
        $this->assertSame([], schema::validate($this->document($columns), null, [], ['cities' => 'uf']));
        // An unknown parent dictionary (none, or parents in several) is not checked.
        $columns[3]['parent'] = 'lang';
        $this->assertSame([], schema::validate($this->document($columns), null, [], ['langs' => 'langs']));
        $this->assertSame([], schema::validate($this->document($columns)));
    }

    /**
     * The accessors read roles, falling back where a stored schema breaks a rule.
     */
    public function test_accessors(): void {
        $schema = schema::from_columns([
            ['key' => 'a', 'label' => 'A', 'mode' => 'free', 'subline' => true, 'indexed' => true],
            ['key' => 'b', 'label' => '', 'mode' => 'dictionary', 'domain' => 'dom', 'title' => true, 'subline' => true,
                'required' => true],
            ['key' => 'c', 'label' => 'C', 'mode' => 'dictionary_open', 'domain' => 'bad domain', 'subline' => true,
                'indexed' => true],
            ['key' => 'd', 'label' => 'D', 'mode' => 'free', 'subline' => true, 'indexed' => true, 'required' => true],
            ['key' => 'e', 'label' => 'E', 'mode' => 'free', 'subline' => true, 'indexed' => true],
        ]);

        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $schema->keys());
        $this->assertSame('b', $schema->title_key());
        $this->assertSame(['a', 'c', 'd'], $schema->subline_keys());
        $this->assertSame(['a', 'c', 'd'], $schema->indexed_keys());
        $this->assertSame(['b', 'd'], $schema->required_keys());
        $this->assertSame(['b' => 'dom'], $schema->domain_map());
        $this->assertSame('b', $schema->label('b'));
        $this->assertSame('A', $schema->label('a'));
        $this->assertNull($schema->column('z'));
        $this->assertSame(['value' => 'a'], $schema->report_identifiers('a'));
        $this->assertSame(['name' => 'c_name', 'code' => 'c_code'], $schema->report_identifiers('c'));
        $this->assertSame([], $schema->report_identifiers('z'));

        $untitled = schema::from_columns([['key' => 'x', 'label' => 'X'], ['key' => 'y', 'label' => 'Y']]);
        $this->assertSame('x', $untitled->title_key());
        $this->assertNull(schema::from_columns([])->title_key());
    }

    /**
     * Legacy labels keep only real keys whose label differs from the key, on a copy.
     */
    public function test_with_legacy_labels(): void {
        $schema = schema::from_columns([['key' => 'name', 'label' => 'Name'], ['key' => 'role', 'label' => 'Role']]);

        $copy = $schema->with_legacy_labels(['name' => 'Full name', 'role' => 'role', 'gone' => 'Gone', 'x' => 1]);

        $this->assertSame(['name' => 'Full name'], $copy->legacy_labels());
        $this->assertSame([], $schema->legacy_labels());
    }

    /**
     * A field record yields its version 2 schema with the legacy labels of the plugin config, or its version 1 schema.
     */
    public function test_from_field(): void {
        $this->resetAfterTest();
        $json = schema::from_columns([['key' => 'name', 'label' => 'Full name', 'title' => true]])->to_json();

        $field = (object)['id' => 7, 'param1' => $json];
        $this->assertSame([], schema::from_field($field)->legacy_labels());

        set_config(schema::legacy_config_name(7), json_encode(['name' => 'Full name']), 'profilefield_repeatable');
        $this->assertSame(['name' => 'Full name'], schema::from_field($field)->legacy_labels());
        $this->assertSame([], schema::from_field((object)['id' => 8, 'param1' => $json])->legacy_labels());

        $v1 = schema::from_field((object)['id' => 9, 'param1' => "Full name\nRole", 'param3' => 'Role|roles']);
        $this->assertSame(['full_name', 'role'], $v1->keys());
        $this->assertSame(['role' => 'roles'], $v1->domain_map());
        $this->assertSame(['full_name' => 'Full name', 'role' => 'Role'], $v1->legacy_labels());
    }

    /**
     * A generated key never equals another column's version 1 label, so v1 sets read the right value.
     */
    public function test_from_v1_keys_never_equal_another_label(): void {
        $schema = schema::from_v1("A B\na_b\nX\nx_name");

        $this->assertSame(['a_b_2', 'a_b', 'x_2', 'x_name'], $schema->keys());
        $payload = \profilefield_repeatable\helper::normalise_payload(
            '{"@@ID#1":{"A B":"first","a_b":"second","X":"third","x_name":"fourth"}}',
            $schema
        );
        $this->assertSame(['a_b_2' => 'first', 'a_b' => 'second', 'x_2' => 'third', 'x_name' => 'fourth'], $payload['@@ID#1']);
    }

    /**
     * A stored schema drops the columns no reader may see: bad key, repeated key, taken report identifier.
     */
    public function test_from_json_drops_unsafe_stored_columns(): void {
        $json = json_encode(['version' => 2, 'columns' => [
            ['key' => 'name', 'label' => 'Name', 'title' => true],
            ['key' => "x' OR 1=1", 'label' => 'Injected'],
            ['key' => 'name', 'label' => 'Again'],
            ['key' => 'lang', 'label' => 'Lang', 'mode' => 'dictionary', 'domain' => 'iso639'],
            ['key' => 'lang_name', 'label' => 'Lang name'],
            ['key' => 'setnumber', 'label' => 'Set'],
            ['key' => 'role', 'label' => 'Role'],
        ]]);

        $this->assertSame(['name', 'lang', 'role'], schema::from_json($json)->keys());
        $this->assertDebuggingCalledCount(4);

        // Each dropped column is reported once per request.
        schema::from_json($json);
        $this->assertDebuggingNotCalled();

        // Validation still sees every submitted column, so it can name them.
        $this->assertCount(7, schema::from_columns(json_decode($json, true)['columns'])->columns());
    }
}
