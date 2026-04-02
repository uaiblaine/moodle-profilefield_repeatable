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
 * @copyright  2026 Anderson Blaine (anderson@blaine.com.br)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['addnewset'] = 'Adicionar novo conjunto';
$string['errorinvalidjson'] = 'Payload repetível inválido; esperado um objeto JSON.';
$string['errorindexedsubitemlimit'] = 'Você pode indexar no máximo {$a} subitens para este campo.';
$string['errorindexedsubitemunknown'] = 'Subiten(s) indexado(s) não encontrado(s) em Subitens: {$a}.';
$string['errordomainmapformat'] = 'Formato de mapeamento de referência inválido: {$a}. Use "subitem|domínio" (um por linha).';
$string['errordomainmapunknownsubitem'] = 'Subiten(s) de mapeamento de referência não encontrado(s) em Subitens: {$a}.';
$string['errordomainmapduplicatesubitem'] = 'Mapeamento de referência duplicado para subiten(s): {$a}.';
$string['errordomainmapinvaliddomain'] = 'Nomes de domínio inválidos: {$a}. Padrão permitido: letras minúsculas, números, sublinhado.';
$string['errordomainmapmissingdomain'] = 'Domínio(s) de referência não encontrado(s) em local_profilefield_repeatable: {$a}.';
$string['errorsubitemsduplicate'] = 'Os subitens devem ser únicos (sem distinção entre maiúsculas e minúsculas).';
$string['errorsubitemsrequired'] = 'Informe pelo menos um subitem, um por linha.';
$string['displaytableinfo'] = 'Informação';
$string['displaytablevalue'] = 'Valor';
$string['indexedsubitems'] = 'Subitens indexados (um por linha)';
$string['indexedsubitems_help'] =
    'Opcionalmente liste subitens que devem receber índices PostgreSQL dedicados. Cada linha deve corresponder exatamente a um subitem.';
$string['referencesubitemdomains'] = 'Mapeamento de referência (subitem|domínio, um por linha)';
$string['referencesubitemdomains_help'] =
    'Mapeamento opcional para resolver valores codificados em rótulos na exibição do perfil. Formate cada linha como "subitem|domínio", onde domínio é um nome curto de local_profilefield_repeatable.';
$string['pluginname'] = 'Conjunto repetível';
$string['setcolumn'] = 'CONJUNTO';
$string['actioncolumn'] = 'AÇÃO';
$string['privacy:metadata:profilefield_repeatable:userid'] =
    'O ID do usuário cujos dados são armazenados pelo campo de perfil de conjunto repetível';
$string['privacy:metadata:profilefield_repeatable:fieldid'] = 'O ID do campo de perfil';
$string['privacy:metadata:profilefield_repeatable:data'] = 'Dados do usuário do campo de perfil de conjunto repetível';
$string['privacy:metadata:profilefield_repeatable:dataformat'] =
    'O formato dos dados do campo de perfil de conjunto repetível';
$string['privacy:metadata:profilefield_repeatable:tableexplanation'] = 'Dados adicionais de perfil';
$string['privacy:metadata:profilefield_repeatable_data:dataid'] =
    'Chave estrangeira para a linha em user_info_data que armazena esta instância do campo repetível';
$string['privacy:metadata:profilefield_repeatable_data:fieldid'] = 'O ID do campo de perfil';
$string['privacy:metadata:profilefield_repeatable_data:userid'] = 'O ID do usuário proprietário dos dados deste campo repetível';
$string['privacy:metadata:profilefield_repeatable_data:set_id'] = 'O número do conjunto repetível';
$string['privacy:metadata:profilefield_repeatable_data:data'] = 'Payload JSON para um conjunto repetível';
$string['privacy:metadata:profilefield_repeatable_data:timecreated'] = 'A data em que a linha do conjunto foi criada';
$string['privacy:metadata:profilefield_repeatable_data:timemodified'] = 'A data da última modificação da linha do conjunto';
$string['privacy:metadata:profilefield_repeatable_data:tableexplanation'] =
    'Conjuntos de campos de perfil repetíveis armazenados como payloads JSON';
$string['removeset'] = 'Remover conjunto';
$string['repeatableset'] = 'Conjunto {$a}';
$string['subitems'] = 'Subitens (um por linha)';
$string['subitems_help'] =
    'Defina cada rótulo de subitem em uma nova linha. Esses rótulos se tornarão campos de texto dentro de cada conjunto repetível.';
$string['synchronisedat'] = 'Sincronizado em: {$a}';
$string['taskreconcileindexes'] = 'Reconciliar índices JSON do campo de perfil repetível';
