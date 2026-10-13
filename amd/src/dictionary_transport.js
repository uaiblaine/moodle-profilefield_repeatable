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
 * Transport for core/form-autocomplete over the companion's dictionary search, for one column.
 *
 * Wraps local_dictionaries/autocomplete_transport, which reads data-domain, data-parentcode and
 * data-parentdomain from the select. On top of it, read from the select as well:
 * - data-parent: the column depends on another one; while data-parentcode is empty, the list
 *   asks for the parent's value first (data-parentlabel names it) instead of searching;
 * - data-open="1": a column accepting any code offers the typed text as a code when no item has
 *   that code; a column accepting listed codes only says so when nothing matches.
 *
 * Only repeatable.js enhances selects with this module, and only when the companion is installed.
 *
 * @module     profilefield_repeatable/dictionary_transport
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';
import {escapeHtml, newCodeHtml} from 'profilefield_repeatable/markup';
import {transport as searchTransport, processResults as searchResults} from 'local_dictionaries/autocomplete_transport';

/**
 * Fold a code for comparison, as the companion's code key does: accents removed, lower case.
 *
 * @param {String} code
 * @returns {String}
 */
const foldCode = (code) => String(code).trim().normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/**
 * Search the column's domain, or ask for the parent's value first.
 *
 * @param {String} selector The selector of the original select element.
 * @param {String} query The text typed by the user.
 * @param {Function} callback Receives the response, with the query and the strings added.
 * @param {Function} failure Receives the error.
 */
export const transport = async(selector, query, callback, failure) => {
    const element = document.querySelector(selector);
    let strings;
    try {
        const parentlabel = element ? (element.dataset.parentlabel || '') : '';
        const values = await getStrings([
            {key: 'choosefirst', component: 'profilefield_repeatable', param: parentlabel},
            {key: 'newcode', component: 'profilefield_repeatable'},
            {key: 'nomatchstrict', component: 'profilefield_repeatable'},
            {key: 'usecode', component: 'profilefield_repeatable', param: String(query).trim()},
        ]);
        strings = {choosefirst: values[0], newcode: values[1], nomatchstrict: values[2], usecode: values[3]};
    } catch (error) {
        failure(error);
        return;
    }

    if (element && element.dataset.parent && (element.dataset.parentcode || '').trim() === '') {
        callback({notice: strings.choosefirst});
        return;
    }

    await searchTransport(selector, query, (response) => {
        callback(Object.assign({}, response, {query: String(query), strings: strings}));
    }, failure);
};

/**
 * Turn the response into autocomplete options, or a notice.
 *
 * @param {String} selector The selector of the original select element.
 * @param {Object} results What transport() passed to the callback.
 * @returns {Array|String} Options, or a notice (markup) shown instead of the list.
 */
export const processResults = (selector, results) => {
    if (results && typeof results.notice === 'string') {
        return escapeHtml(results.notice);
    }
    const options = searchResults(selector, results);
    if (!Array.isArray(options)) {
        return options;
    }

    const element = document.querySelector(selector);
    const query = String(results.query || '').trim();
    const strings = results.strings || {};
    if (!element || query === '') {
        return options;
    }

    if (element.dataset.open === '1') {
        const known = results.items.some((item) => foldCode(item.code) === foldCode(query));
        if (!known) {
            // One label for both places: the suggestion reads "Use code", the picked value the code and its marker.
            options.push({
                value: query,
                label: `<span class="profilefield-repeatable-usecode">${escapeHtml(strings.usecode || query)}</span>`
                    + `<span class="profilefield-repeatable-picked">${newCodeHtml(query, strings.newcode || '')}</span>`,
            });
        }
        return options;
    }

    return options.length ? options : escapeHtml(strings.nomatchstrict || '');
};
