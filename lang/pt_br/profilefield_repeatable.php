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

/**
 * Strings for component 'profilefield_repeatable', language 'pt_br'.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['columns'] = 'Colunas';
$string['columns_help'] =
    'As colunas de cada conjunto, como um esquema JSON: {"version": 2, "columns": [{"key": "language", "label": "Idioma", "mode": "dictionary", "domain": "iso639", "title": true}, ...]}. Com JavaScript, o editor de colunas o escreve. Modos: free (texto livre), dictionary (apenas códigos de um domínio do local_dictionaries), dictionary_open (qualquer código), pattern (máscara e/ou expressão regular do customfield_pattern, Moodle 5.1 ou posterior; um objeto "pattern" com preset, mask, regex, regexerror e storeformat). Exatamente uma coluna é o título; até 3 aparecem na linha auxiliar. Uma coluna cuja chave tem valores armazenados não pode ser removida nem ter a chave alterada; renomeie o nome da coluna.';
$string['datasourcerepeatablesets'] = 'Conjuntos de campo de perfil repetível';
$string['entityrepeatablesetnamed'] = '{$a}';
$string['errorsaverejected'] = 'Os conjuntos repetíveis do campo {$a} não foram salvos: eles não passam nas verificações do campo.';
$string['pluginname'] = 'Conjunto repetível';
$string['privacy:metadata:profilefield_repeatable:data'] = 'Dados do usuário do campo de perfil de conjunto repetível';
$string['privacy:metadata:profilefield_repeatable:dataformat'] =
    'O formato dos dados do campo de perfil de conjunto repetível';
$string['privacy:metadata:profilefield_repeatable:fieldid'] = 'O ID do campo de perfil';
$string['privacy:metadata:profilefield_repeatable:tableexplanation'] = 'Dados adicionais de perfil';
$string['privacy:metadata:profilefield_repeatable:userid'] =
    'O ID do usuário cujos dados são armazenados pelo campo de perfil de conjunto repetível';
$string['privacy:metadata:profilefield_repeatable_data:data'] = 'Payload JSON para um conjunto repetível';
$string['privacy:metadata:profilefield_repeatable_data:dataid'] =
    'Chave estrangeira para a linha em user_info_data que armazena esta instância do campo repetível';
$string['privacy:metadata:profilefield_repeatable_data:fieldid'] = 'O ID do campo de perfil';
$string['privacy:metadata:profilefield_repeatable_data:set_id'] = 'O número do conjunto repetível';
$string['privacy:metadata:profilefield_repeatable_data:tableexplanation'] =
    'Conjuntos de campos de perfil repetíveis armazenados como payloads JSON';
$string['privacy:metadata:profilefield_repeatable_data:timecreated'] = 'A data em que a linha do conjunto foi criada';
$string['privacy:metadata:profilefield_repeatable_data:timemodified'] = 'A data da última modificação da linha do conjunto';
$string['privacy:metadata:profilefield_repeatable_data:userid'] = 'O ID do usuário proprietário dos dados deste campo repetível';
$string['profilewidgettitle'] = 'Campos repetíveis';
$string['taskrekeypayloads'] = 'Rechavear os valores do campo de perfil repetível pela chave da coluna';
$string['taskreprojectsets'] = 'Reconstruir os conjuntos do campo de perfil repetível para relatórios';
