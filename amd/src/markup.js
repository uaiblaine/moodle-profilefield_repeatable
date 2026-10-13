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
 * Markup shared by the edit widget and the dictionary transport.
 *
 * Kept apart from profilefield_repeatable/dictionary_transport, which loads the companion's
 * transport: the widget must load without the companion.
 *
 * @module     profilefield_repeatable/markup
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Escape text for HTML: core/form-autocomplete inserts option labels and notices as markup.
 *
 * @param {String} text
 * @returns {String}
 */
export const escapeHtml = (text) => {
    const element = document.createElement('span');
    element.textContent = String(text);
    return element.innerHTML;
};

/**
 * Markup of a code that is not in the dictionary, as shown once picked.
 *
 * @param {String} code
 * @param {String} newcode The "new code" marker text.
 * @returns {String}
 */
export const newCodeHtml = (code, newcode) =>
    `${escapeHtml(code)} <span class="profilefield-repeatable-newcode">${escapeHtml(newcode)}</span>`;
