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
$string['addcolumn'] = 'Adicionar coluna';
$string['addnewset'] = 'Adicionar novo conjunto';
$string['choosefirst'] = 'Escolha antes um valor para {$a}.';
$string['columncount'] = '{$a->count} de {$a->max} colunas';
$string['columndomain'] = 'Dicionário';
$string['columndomainhint'] = 'Do local_dictionaries.';
$string['columndomainnocompanion'] =
    'Nome curto de um domínio do local_dictionaries. Sem esse plugin, os valores aparecem como armazenados.';
$string['columnkey'] = 'Chave';
$string['columnkeyhint'] =
    'Gerada a partir do nome; apenas letras minúsculas, dígitos e _. Valores e relatórios ficam guardados sob ela.';
$string['columnkeylocked'] = 'Bloqueada: há usuários com valores nesta chave.';
$string['columnlabel'] = 'Nome da coluna';
$string['columnlabelhint'] = 'Exibido aos usuários. Aceita marcações multilang.';
$string['columnmode'] = 'Como os usuários preenchem';
$string['columnnew'] = 'Nova';
$string['columnparent'] = 'Depende de';
$string['columnparenthint'] =
    'Uma coluna de dicionário anterior cujo código restringe esta, por exemplo as cidades do estado escolhido.';
$string['columnrole'] = 'Papel no conjunto';
$string['columns'] = 'Colunas';
$string['columns_help'] =
    'As colunas de cada conjunto, como um esquema JSON: {"version": 2, "columns": [{"key": "language", "label": "Idioma", "mode": "dictionary", "domain": "iso639", "title": true}, ...]}. Com JavaScript, o editor de colunas o escreve. Modos: free (texto livre), dictionary (apenas códigos de um domínio do local_dictionaries), dictionary_open (qualquer código). Exatamente uma coluna é o título; até 3 aparecem na linha auxiliar e até 3 são indexadas. Uma coluna cuja chave tem valores armazenados não pode ser removida nem ter a chave alterada; renomeie o nome da coluna.';
$string['columnsindata'] =
    '{$a} usuários têm dados neste campo. Colunas com valores armazenados não podem ser removidas e suas chaves estão bloqueadas: renomear essa coluna muda apenas o nome.';
$string['columnsubitem'] = '{$a}';
$string['columnsubitemcode'] = '{$a} (código)';
$string['columnsubitemname'] = '{$a} (nome)';
$string['datasourcerepeatablesets'] = 'Conjuntos de campo de perfil repetível';
$string['domainoption'] = '{$a->name} ({$a->itemcount} itens)';
$string['domainrestricted'] = 'Restrito: só quem pode ver dicionários consegue pesquisá-lo.';
$string['domainvisible'] = 'Pesquisável por qualquer usuário autenticado.';
$string['entityrepeatableset'] = 'Conjunto repetível';
$string['entityrepeatablesetnamed'] = '{$a}';
$string['errorcodeinactive'] = 'Conjunto {$a->set}: o código "{$a->code}" de {$a->column} não é mais oferecido.';
$string['errorcodeparent'] = 'Conjunto {$a->set}: {$a->column} "{$a->code}" não pertence a {$a->parent} "{$a->parentcode}".';
$string['errorcodeparentmissing'] = 'Conjunto {$a->set}: escolha um valor para {$a->parent} antes de {$a->column}.';
$string['errorcoderestricted'] = 'Conjunto {$a->set}: {$a->column} é uma lista restrita. Escolha um valor definido por um administrador ou peça acesso.';
$string['errorcodeunknown'] = 'Conjunto {$a->set}: "{$a->code}" não é um código de {$a->column}.';
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
$string['errorkeylocked'] = 'Colunas com valores armazenados não podem ser removidas nem ter a chave alterada: {$a}.';
$string['errorlabelduplicate'] = 'Nome de coluna usado duas vezes: {$a}.';
$string['errorlabelrequired'] = 'A coluna {$a} não tem nome.';
$string['errormodeinvalid'] = 'Coluna "{$a}": escolha texto livre ou um modo de dicionário.';
$string['errorparentinvalid'] = 'A coluna "{$a}" só pode depender de uma coluna de dicionário anterior.';
$string['errorparentmismatch'] = 'Coluna "{$a->column}": a coluna pai não usa "{$a->parentdomain}", o dicionário que contém os pais de "{$a->domain}".';
$string['errorschemainvalid'] = 'As colunas não formam um esquema versão 2 válido.';
$string['errorsublinelimit'] = 'No máximo {$a} colunas podem aparecer na linha auxiliar.';
$string['errorsublinetitle'] = 'A coluna "{$a}" é o título e não pode estar também na linha auxiliar.';
$string['errortitlecount'] = 'Marque exatamente uma coluna como título do conjunto.';
$string['errortoomanysets'] = 'Conjuntos demais: no máximo {$a} conjuntos são permitidos.';
$string['errorvaluetoolong'] = 'Valor muito longo: cada valor é limitado a {$a} caracteres.';
$string['modedictionary'] = 'Dicionário, só códigos';
$string['modedictionaryhelp'] = 'Códigos do dicionário; guarda o código, exibe o nome.';
$string['modedictionaryopen'] = 'Dicionário, qualquer código';
$string['modedictionaryopenhelp'] = 'Sugere do dicionário, mas aceita um código novo.';
$string['modefree'] = 'Texto livre';
$string['modefreehelp'] = 'Qualquer texto de até 1.024 caracteres.';
$string['movedown'] = 'Mover {$a} para baixo';
$string['moveup'] = 'Mover {$a} para cima';
$string['newcode'] = 'Código novo, ainda sem nome';
$string['newcolumn'] = 'Coluna {$a}';
$string['nomatchstrict'] = 'Nenhum resultado. Esta coluna aceita apenas códigos da lista.';
$string['parentmismatch'] = 'A coluna pai escolhida não usa "{$a->parentdomain}", o dicionário que contém os pais de "{$a->domain}".';
$string['parentnone'] = 'Nenhuma';
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
$string['removecolumn'] = 'Remover coluna';
$string['removecolumnlocked'] = 'Há usuários com valores nesta coluna, por isso ela não pode ser removida.';
$string['removeset'] = 'Remover conjunto';
$string['repeatableset'] = 'Conjunto {$a}';
$string['roleindexed'] = 'Indexada';
$string['roleindexedlabel'] = 'Indexar para relatórios (PostgreSQL)';
$string['rolerequired'] = 'Obrigatória';
$string['rolerequiredlabel'] = 'Obrigatória em todo conjunto';
$string['rolesubline'] = 'Linha auxiliar';
$string['rolesublinelabel'] = 'Exibir na linha auxiliar';
$string['roletitle'] = 'Título';
$string['roletitlelabel'] = 'Título do conjunto';
$string['searchplaceholder'] = 'Digite um nome ou um código';
$string['setcolumn'] = 'CONJUNTO';
$string['setnumber'] = 'Número do conjunto';
$string['summaryindexed'] = 'Indexadas {$a->count}/{$a->max}';
$string['summarysubline'] = 'Linha auxiliar {$a->count}/{$a->max}';
$string['summarytitle'] = 'Título: {$a}';
$string['synchronisedat'] = 'Sincronizado em: {$a}';
$string['taskreconcileindexes'] = 'Reconciliar índices JSON do campo de perfil repetível';
$string['taskrekeypayloads'] = 'Rechavear os valores do campo de perfil repetível pela chave da coluna';
$string['taskreprojectsets'] = 'Reconstruir os conjuntos do campo de perfil repetível para relatórios';
$string['usecode'] = 'Usar o código "{$a}"';
