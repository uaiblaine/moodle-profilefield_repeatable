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

/**
 * Single-open accordion behaviour for repeatable profile field display.
 *
 * Uses native <details>/<summary> elements; this module only enforces the
 * "one open at a time" constraint by listening to the toggle event.
 *
 * @module     profilefield_repeatable/displayaccordion
 * @copyright  2026 Anderson Blaine (anderson@blaine.com.br)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    accordion: '.profilefield-repeatable-display-accordion',
    item: '.profilefield-repeatable-item'
};

/**
 * Bind toggle handlers to one accordion root.
 *
 * @param {HTMLElement} root
 */
const initAccordion = (root) => {
    if (root.dataset.repeatableAccordionInitialised === '1') {
        return;
    }
    root.dataset.repeatableAccordionInitialised = '1';

    Array.from(root.querySelectorAll(SELECTORS.item)).forEach((details) => {
        details.addEventListener('toggle', () => {
            if (!details.open) {
                return;
            }
            Array.from(root.querySelectorAll(SELECTORS.item)).forEach((sibling) => {
                if (sibling !== details && sibling.open) {
                    sibling.open = false;
                }
            });
        });
    });
};

/**
 * Initialise profile repeatable accordions.
 */
export const init = () => {
    Array.from(document.querySelectorAll(SELECTORS.accordion)).forEach((root) => {
        initAccordion(root);
    });
};
