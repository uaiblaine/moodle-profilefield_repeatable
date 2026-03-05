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
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    sets: '[data-region="sets"]',
    set: '[data-region="set"]',
    setTitle: '[data-region="set-title"]',
    removeSet: '[data-action="removeset"]',
    addSet: '[data-action="addset"]',
    input: 'input[data-subitem]'
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
 * Build one empty set node to be used as cloning template.
 *
 * @param {Array} subitems
 * @param {Object} strings
 * @returns {HTMLElement}
 */
const createSetTemplate = (subitems, strings) => {
    const set = document.createElement('div');
    set.className = 'card p-3 mb-2 profilefield-repeatable-set';
    set.dataset.region = 'set';

    const head = document.createElement('div');
    head.className = 'd-flex justify-content-between align-items-center mb-2';

    const title = document.createElement('strong');
    title.dataset.region = 'set-title';
    head.appendChild(title);

    const removebutton = document.createElement('button');
    removebutton.type = 'button';
    removebutton.className = 'btn btn-link text-danger p-0';
    removebutton.dataset.action = 'removeset';
    removebutton.textContent = strings.removeset;
    head.appendChild(removebutton);

    set.appendChild(head);

    const body = document.createElement('div');
    body.className = 'profilefield-repeatable-fields';

    subitems.forEach((subitem) => {
        const row = document.createElement('div');
        row.className = 'mb-2 profilefield-repeatable-field';

        const label = document.createElement('label');
        label.className = 'form-label';
        label.textContent = subitem;
        label.dataset.region = 'subitem-label';

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control';
        input.dataset.subitem = subitem;

        row.appendChild(label);
        row.appendChild(input);
        body.appendChild(row);
    });

    set.appendChild(body);

    return set;
};

/**
 * Update titles, ids and label-for attributes after add/remove actions.
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
        if (title) {
            title.textContent = renderSetTitle(titletemplate, number);
        }

        const inputs = Array.from(set.querySelectorAll(SELECTORS.input));
        inputs.forEach((input, fieldindex) => {
            const id = `${hiddenid}_${number}_${fieldindex + 1}`;
            input.id = id;
            const parent = input.closest('.profilefield-repeatable-field');
            const label = parent ? parent.querySelector('label') : null;
            if (label) {
                label.setAttribute('for', id);
            }
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
        const key = input.dataset.subitem;
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
 * @param {String} rawpayload
 * @param {Array} subitems
 * @returns {Array}
 */
const parsePayloadToSetList = (rawpayload, subitems) => {
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
            subitems.forEach((subitem) => {
                const value = Object.prototype.hasOwnProperty.call(setdata, subitem) ? setdata[subitem] : '';
                normalisedset[subitem] = value === null || value === undefined ? '' : String(value);
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
 * @param {Array} subitems
 * @returns {Object}
 */
const buildPayloadFromDom = (wrapper, subitems) => {
    const payload = {};
    let index = 1;

    const sets = Array.from(wrapper.querySelectorAll(SELECTORS.set));
    sets.forEach((set) => {
        const setvalues = {};
        const valuesbykey = {};
        Array.from(set.querySelectorAll(SELECTORS.input)).forEach((input) => {
            valuesbykey[input.dataset.subitem] = String(input.value);
        });

        subitems.forEach((subitem) => {
            setvalues[subitem] = Object.prototype.hasOwnProperty.call(valuesbykey, subitem) ? valuesbykey[subitem] : '';
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
 * Initialise repeatable field UI.
 *
 * @param {Object} config
 */
export const init = (config) => {
    if (!config || !config.wrapperid || !config.hiddenid || !Array.isArray(config.subitems)) {
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

    const strings = {
        addnewset: config.strings && config.strings.addnewset ? config.strings.addnewset : 'Add new set',
        removeset: config.strings && config.strings.removeset ? config.strings.removeset : 'Remove set',
        repeatableset: config.strings && config.strings.repeatableset ? config.strings.repeatableset : 'Set {no}'
    };

    const subitems = config.subitems.map((item) => String(item).trim()).filter((item) => item !== '');
    const settemplate = createSetTemplate(subitems, strings);

    const existingsets = parsePayloadToSetList(hidden.value, subitems);
    if (existingsets.length) {
        existingsets.forEach((setvalues) => {
            setscontainer.appendChild(cloneSetFromTemplate(settemplate, setvalues));
        });
    } else {
        setscontainer.appendChild(cloneSetFromTemplate(settemplate));
    }

    updateSetMetadata(wrapper, config.hiddenid, strings.repeatableset);

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
            const payload = buildPayloadFromDom(wrapper, subitems);
            hidden.value = Object.keys(payload).length ? JSON.stringify(payload) : '{}';
        });
    }

    wrapper.dataset.repeatableInitialised = '1';
};
