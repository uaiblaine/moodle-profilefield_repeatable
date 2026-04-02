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
 * Profile display accordion behaviour for repeatable profile field.
 *
 * @module     profilefield_repeatable/displayaccordion
 * @copyright  2026 Anderson Blaine (anderson@blaine.com.br)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    accordion: '.profilefield-repeatable-display-accordion',
    button: '.profilefield-repeatable-accordion-button'
};

/**
 * Resolve a collapse panel by id within one accordion root.
 *
 * @param {HTMLElement} root
 * @param {String} collapseid
 * @returns {?HTMLElement}
 */
const getCollapse = (root, collapseid) => {
    if (!collapseid) {
        return null;
    }

    const collapse = document.getElementById(collapseid);
    if (!collapse || !root.contains(collapse)) {
        return null;
    }

    return collapse;
};

/**
 * Apply expanded/collapsed state to one accordion entry.
 *
 * @param {HTMLElement} button
 * @param {HTMLElement} collapse
 * @param {Boolean} expanded
 */
const setExpandedState = (button, collapse, expanded) => {
    button.classList.toggle('collapsed', !expanded);
    button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    collapse.classList.toggle('show', expanded);
    collapse.hidden = !expanded;
};

/**
 * Collapse all siblings except one button.
 *
 * @param {HTMLElement} root
 * @param {HTMLElement} activebutton
 */
const collapseSiblings = (root, activebutton) => {
    Array.from(root.querySelectorAll(SELECTORS.button)).forEach((button) => {
        if (button === activebutton) {
            return;
        }

        const collapse = getCollapse(root, button.getAttribute('aria-controls'));
        if (!collapse) {
            return;
        }

        setExpandedState(button, collapse, false);
    });
};

/**
 * Bind click handlers to one accordion root.
 *
 * @param {HTMLElement} root
 */
const initAccordion = (root) => {
    if (root.dataset.repeatableAccordionInitialised === '1') {
        return;
    }

    Array.from(root.querySelectorAll(SELECTORS.button)).forEach((button) => {
        const collapse = getCollapse(root, button.getAttribute('aria-controls'));
        if (collapse) {
            const expanded = button.getAttribute('aria-expanded') === 'true' || collapse.classList.contains('show');
            setExpandedState(button, collapse, expanded);
        }

        button.addEventListener('click', (event) => {
            event.preventDefault();

            const collapse = getCollapse(root, button.getAttribute('aria-controls'));
            if (!collapse) {
                return;
            }

            const currentlyexpanded = button.getAttribute('aria-expanded') === 'true' || collapse.classList.contains('show');
            collapseSiblings(root, button);
            setExpandedState(button, collapse, !currentlyexpanded);
        });
    });

    root.dataset.repeatableAccordionInitialised = '1';
};

/**
 * Initialise profile repeatable accordions.
 */
export const init = () => {
    Array.from(document.querySelectorAll(SELECTORS.accordion)).forEach((root) => {
        initAccordion(root);
    });
};
