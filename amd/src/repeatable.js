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
 * Repeatable profilefield UI.
 *
 * @module     profilefield_repeatable/repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    sets: '[data-region="sets"]',
    set: '[data-region="set"]',
    setTitle: '[data-region="set-title"]',
    removeSet: '[data-action="removeset"]',
    addSet: '[data-action="addset"]',
    input: 'input[data-key]'
};

/**
 * Replace {no} placeholder in set title template.
 *
 * @param {String} template
 * @param {Number} index
 * @returns {String}
 */
const renderSetTitle = (template, index) => template.replace('{no}', String(index));

/**
 * Build one empty set row node to be used as cloning template.
 *
 * @param {Array} columns Objects with key, label and required.
 * @param {Object} strings
 * @returns {HTMLElement}
 */
const createSetTemplate = (columns, strings) => {
    const row = document.createElement('tr');
    row.dataset.region = 'set';
    row.className = 'profilefield-repeatable-set';

    const titlecell = document.createElement('th');
    titlecell.scope = 'row';
    titlecell.className = 'align-middle text-nowrap';

    const title = document.createElement('span');
    title.dataset.region = 'set-title';
    title.className = 'profilefield-repeatable-set-title';
    titlecell.appendChild(title);
    row.appendChild(titlecell);

    columns.forEach((column) => {
        const cell = document.createElement('td');
        cell.className = 'align-middle';

        const label = document.createElement('label');
        label.className = 'accesshide';
        label.dataset.region = 'subitem-label';
        label.textContent = column.label;

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control';
        input.dataset.key = column.key;
        input.dataset.label = column.label;
        if (column.required) {
            // Not the required attribute: it would block the submit of a set left empty on purpose.
            input.setAttribute('aria-required', 'true');
        }

        cell.appendChild(label);
        cell.appendChild(input);
        row.appendChild(cell);
    });

    const actioncell = document.createElement('td');
    actioncell.className = 'align-middle text-center';

    const removebutton = document.createElement('button');
    removebutton.type = 'button';
    removebutton.className = 'btn btn-link text-danger p-0';
    removebutton.dataset.action = 'removeset';
    removebutton.setAttribute('aria-label', strings.removeset);
    removebutton.setAttribute('title', strings.removeset);

    const icon = document.createElement('i');
    icon.className = 'icon fa fa-trash fa-fw';
    icon.setAttribute('aria-hidden', 'true');
    removebutton.appendChild(icon);

    actioncell.appendChild(removebutton);
    row.appendChild(actioncell);

    return row;
};

/**
 * Update titles, ids and accessibility metadata after add/remove actions.
 *
 * @param {HTMLElement} wrapper
 * @param {String} hiddenid
 * @param {String} titletemplate
 */
const updateSetMetadata = (wrapper, hiddenid, titletemplate) => {
    const sets = Array.from(wrapper.querySelectorAll(SELECTORS.set));

    sets.forEach((set, setindex) => {
        const number = setindex + 1;
        const title = set.querySelector(SELECTORS.setTitle);
        const renderedtitle = renderSetTitle(titletemplate, number);

        if (title) {
            title.textContent = renderedtitle;
        }

        const inputs = Array.from(set.querySelectorAll(SELECTORS.input));
        inputs.forEach((input, fieldindex) => {
            const id = `${hiddenid}_${number}_${fieldindex + 1}`;
            input.id = id;

            const parentcell = input.closest('td');
            const label = parentcell ? parentcell.querySelector('[data-region="subitem-label"]') : null;
            if (label) {
                label.setAttribute('for', id);
            }

            input.setAttribute('aria-label', `${String(input.dataset.label)} (${renderedtitle})`);
        });
    });
};

/**
 * Build one set from cloning template and optional values.
 *
 * @param {HTMLElement} template
 * @param {Object} values
 * @returns {HTMLElement}
 */
const cloneSetFromTemplate = (template, values = {}) => {
    const node = template.cloneNode(true);
    const inputs = Array.from(node.querySelectorAll(SELECTORS.input));

    inputs.forEach((input) => {
        const key = input.dataset.key;
        input.value = Object.prototype.hasOwnProperty.call(values, key) ? String(values[key]) : '';
    });

    return node;
};

/**
 * Check whether at least one value in the set has non-blank text.
 *
 * @param {Object} setvalues
 * @returns {Boolean}
 */
const hasSetValue = (setvalues) => Object.values(setvalues).some((value) => String(value).trim() !== '');

/**
 * Parse current hidden JSON to list of set objects.
 *
 * @param {String} rawpayload Payload keyed by column key, as the server normalised it.
 * @param {Array} keys Column keys.
 * @returns {Array}
 */
const parsePayloadToSetList = (rawpayload, keys) => {
    if (!rawpayload || typeof rawpayload !== 'string') {
        return [];
    }

    try {
        const payload = JSON.parse(rawpayload);
        if (!payload || Array.isArray(payload) || typeof payload !== 'object') {
            return [];
        }

        const sets = [];
        Object.keys(payload).forEach((setkey) => {
            const setdata = payload[setkey];
            if (!setdata || Array.isArray(setdata) || typeof setdata !== 'object') {
                return;
            }

            const normalisedset = {};
            keys.forEach((key) => {
                const value = Object.prototype.hasOwnProperty.call(setdata, key) ? setdata[key] : '';
                normalisedset[key] = value === null || value === undefined ? '' : String(value);
            });

            if (hasSetValue(normalisedset)) {
                sets.push(normalisedset);
            }
        });

        return sets;
    } catch (_e) {
        return [];
    }
};

/**
 * Build strict JSON object with @@ID#N keys from current DOM.
 *
 * @param {HTMLElement} wrapper
 * @param {Array} keys Column keys.
 * @returns {Object}
 */
const buildPayloadFromDom = (wrapper, keys) => {
    const payload = {};
    let index = 1;

    const sets = Array.from(wrapper.querySelectorAll(SELECTORS.set));
    sets.forEach((set) => {
        const setvalues = {};
        const valuesbykey = {};

        Array.from(set.querySelectorAll(SELECTORS.input)).forEach((input) => {
            valuesbykey[input.dataset.key] = String(input.value);
        });

        keys.forEach((key) => {
            setvalues[key] = Object.prototype.hasOwnProperty.call(valuesbykey, key) ? valuesbykey[key] : '';
        });

        if (!hasSetValue(setvalues)) {
            return;
        }

        payload[`@@ID#${index}`] = setvalues;
        index++;
    });

    return payload;
};

/**
 * Ensure at least one empty set exists when list is fully removed.
 *
 * @param {HTMLElement} wrapper
 * @param {HTMLElement} template
 * @param {String} hiddenid
 * @param {String} titletemplate
 */
const ensureOneEmptySet = (wrapper, template, hiddenid, titletemplate) => {
    const container = wrapper.querySelector(SELECTORS.sets);
    if (!container) {
        return;
    }

    if (!container.querySelector(SELECTORS.set)) {
        container.appendChild(cloneSetFromTemplate(template));
        updateSetMetadata(wrapper, hiddenid, titletemplate);
    }
};

/**
 * Apply read-only mode in locked field scenarios.
 *
 * @param {HTMLElement} wrapper
 */
const applyReadonlyState = (wrapper) => {
    const addbutton = wrapper.querySelector(SELECTORS.addSet);
    if (addbutton) {
        addbutton.disabled = true;
        addbutton.classList.add('d-none');
    }

    Array.from(wrapper.querySelectorAll(SELECTORS.removeSet)).forEach((button) => {
        button.disabled = true;
        button.classList.add('d-none');
    });

    Array.from(wrapper.querySelectorAll(SELECTORS.input)).forEach((input) => {
        input.readOnly = true;
        input.disabled = true;
    });
};

/**
 * Read the columns the server put on the wrapper.
 *
 * @param {HTMLElement} wrapper
 * @returns {Array} Objects with key, label and required; empty when the attribute is unreadable.
 */
const readColumns = (wrapper) => {
    try {
        const columns = JSON.parse(wrapper.dataset.columns || '[]');
        return Array.isArray(columns) ? columns.filter((column) => column && typeof column.key === 'string') : [];
    } catch (_e) {
        return [];
    }
};

/**
 * Initialise repeatable field UI.
 *
 * Reads the payload from the hidden input once and writes it back from the widget when the form is
 * submitted, dropping sets with no value. Safe to call once per field on a form holding several.
 * The columns come from the wrapper's data-columns attribute (profile_field_repeatable::edit_field_add()).
 *
 * @param {Object} config From profile_field_repeatable::edit_after_data(): wrapperid, hiddenid, readonly, strings.
 */
export const init = (config) => {
    if (!config || !config.wrapperid || !config.hiddenid) {
        return;
    }

    const wrapper = document.getElementById(config.wrapperid);
    const hidden = document.getElementById(config.hiddenid);
    if (!wrapper || !hidden) {
        return;
    }

    if (wrapper.dataset.repeatableInitialised === '1') {
        return;
    }

    const setscontainer = wrapper.querySelector(SELECTORS.sets);
    if (!setscontainer) {
        return;
    }

    const readonly = !!config.readonly;
    const strings = {
        addnewset: config.strings && config.strings.addnewset ? config.strings.addnewset : 'Add new set',
        removeset: config.strings && config.strings.removeset ? config.strings.removeset : 'Remove set',
        repeatableset: config.strings && config.strings.repeatableset ? config.strings.repeatableset : 'Set {no}'
    };

    const columns = readColumns(wrapper);
    const keys = columns.map((column) => column.key);
    const settemplate = createSetTemplate(columns, strings);

    const existingsets = parsePayloadToSetList(hidden.value, keys);
    if (existingsets.length) {
        existingsets.forEach((setvalues) => {
            setscontainer.appendChild(cloneSetFromTemplate(settemplate, setvalues));
        });
    } else if (!readonly) {
        setscontainer.appendChild(cloneSetFromTemplate(settemplate));
    }

    updateSetMetadata(wrapper, config.hiddenid, strings.repeatableset);

    if (readonly) {
        applyReadonlyState(wrapper);
        wrapper.dataset.repeatableInitialised = '1';
        return;
    }

    wrapper.addEventListener('click', (event) => {
        const addbutton = event.target.closest(SELECTORS.addSet);
        if (addbutton) {
            event.preventDefault();
            setscontainer.appendChild(cloneSetFromTemplate(settemplate));
            updateSetMetadata(wrapper, config.hiddenid, strings.repeatableset);
            return;
        }

        const removebutton = event.target.closest(SELECTORS.removeSet);
        if (removebutton) {
            event.preventDefault();
            const set = removebutton.closest(SELECTORS.set);
            if (set) {
                set.remove();
                ensureOneEmptySet(wrapper, settemplate, config.hiddenid, strings.repeatableset);
                updateSetMetadata(wrapper, config.hiddenid, strings.repeatableset);
            }
        }
    });

    const form = hidden.closest('form');
    if (form) {
        form.addEventListener('submit', () => {
            const payload = buildPayloadFromDom(wrapper, keys);
            hidden.value = Object.keys(payload).length ? JSON.stringify(payload) : '{}';
        });
    }

    wrapper.dataset.repeatableInitialised = '1';
};
