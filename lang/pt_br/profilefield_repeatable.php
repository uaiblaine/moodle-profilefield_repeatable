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

$string['actioncolumn'] = 'AÇÃO';
$string['addnewset'] = 'Adicionar novo conjunto';
$string['columns'] = 'Colunas';
$string['columns_help'] =
    'As colunas de cada conjunto, como um esquema JSON: {"version": 2, "columns": [{"key": "language", "label": "Idioma", "mode": "dictionary", "domain": "iso639", "title": true}, ...]}. Com JavaScript, o editor de colunas o escreve. Modos: free (texto livre), dictionary (apenas códigos de um domínio do local_dictionaries), dictionary_open (qualquer código). Exatamente uma coluna é o título; até 3 aparecem na linha auxiliar e até 3 são indexadas. Uma chave com valores não pode mudar; renomeie o nome da coluna.';
$string['columnsubitem'] = '{$a}';
$string['columnsubitemcode'] = '{$a} (código)';
$string['columnsubitemname'] = '{$a} (nome)';
$string['datasourcerepeatablesets'] = 'Conjuntos de campo de perfil repetível';
$string['entityrepeatableset'] = 'Conjunto repetível';
$string['entityrepeatablesetnamed'] = '{$a}';
$string['errorcolumnrequired'] = 'Conjunto {$a->set}: {$a->column} é obrigatório.';
$string['errorcolumnslimit'] = 'Um campo pode ter no máximo {$a} colunas.';
$string['errorcolumnsrequired'] = 'Adicione pelo menos uma coluna.';
$string['errordomaininvalid'] = 'Coluna "{$a}": escolha um dicionário (letras minúsculas, dígitos e sublinhado).';
$string['errordomainmissing'] = 'Dicionário não encontrado no local_dictionaries: {$a}.';
$string['errorindexedlimit'] = 'No máximo {$a} colunas podem ser indexadas.';
$string['errorinvalidjson'] = 'Payload repetível inválido; esperado um objeto JSON.';
$string['errorkeyconflict'] = 'Duas colunas geram a coluna de relatório "{$a}": mude uma das chaves.';
$string['errorkeyduplicate'] = 'Chave usada por duas colunas: {$a}.';
$string['errorkeyinvalid'] =
    'Chave inválida "{$a}": comece com uma letra minúscula, seguida de até 39 letras minúsculas, dígitos ou sublinhados.';
$string['errorlabelduplicate'] = 'Nome de coluna usado duas vezes: {$a}.';
$string['errorlabelrequired'] = 'A coluna {$a} não tem nome.';
$string['errormodeinvalid'] = 'Coluna "{$a}": escolha texto livre ou um modo de dicionário.';
$string['errorparentinvalid'] = 'A coluna "{$a}" só pode depender de uma coluna de dicionário anterior.';
$string['errorschemainvalid'] = 'As colunas não formam um esquema versão 2 válido.';
$string['errorsublinelimit'] = 'No máximo {$a} colunas podem aparecer na linha auxiliar.';
$string['errorsublinetitle'] = 'A coluna "{$a}" é o título e não pode estar também na linha auxiliar.';
$string['errortitlecount'] = 'Marque exatamente uma coluna como título do conjunto.';
$string['errortoomanysets'] = 'Conjuntos demais: no máximo {$a} conjuntos são permitidos.';
$string['errorvaluetoolong'] = 'Valor muito longo: cada valor é limitado a {$a} caracteres.';
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
$string['removeset'] = 'Remover conjunto';
$string['repeatableset'] = 'Conjunto {$a}';
$string['setcolumn'] = 'CONJUNTO';
$string['setnumber'] = 'Número do conjunto';
$string['synchronisedat'] = 'Sincronizado em: {$a}';
$string['taskreconcileindexes'] = 'Reconciliar índices JSON do campo de perfil repetível';
$string['taskreprojectsets'] = 'Reconstruir os conjuntos do campo de perfil repetível para relatórios';
