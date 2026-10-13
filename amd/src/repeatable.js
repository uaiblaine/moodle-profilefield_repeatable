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
 * Dictionary columns the server marks "autocomplete" are a select enhanced by core/form-autocomplete
 * over the companion's search (profilefield_repeatable/dictionary_transport); every other column is
 * a text input. Either way the value of a control is a code, and the hidden input stays the one
 * submitted value.
 *
 * @module     profilefield_repeatable/repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Autocomplete from 'core/form-autocomplete';
import {escapeHtml, newCodeHtml} from 'profilefield_repeatable/markup';

/** Transport module of the enhanced selects, loaded by core only when one is enhanced (the companion is installed). */
const TRANSPORT = 'profilefield_repeatable/dictionary_transport';

const SELECTORS = {
    sets: '[data-region="sets"]',
    // A removed set holding an enhanced select is hidden, not deleted: see removeSet().
    set: '[data-region="set"]:not([data-removed])',
    setTitle: '[data-region="set-title"]',
    removeSet: '[data-action="removeset"]',
    addSet: '[data-action="addset"]',
    input: '[data-key]',
    autocompleteInput: 'input[data-fieldtype="autocomplete"]',
    selection: '.form-autocomplete-selection [role="option"]'
};

/** Counter of the stable ids the enhanced selects need: core keeps addressing them by id. */
let selectcounter = 0;

/**
 * Replace {no} placeholder in set title template.
 *
 * @param {String} template
 * @param {Number} index
 * @returns {String}
 */
const renderSetTitle = (template, index) => template.replace('{no}', String(index));

/**
 * Build the control of one column: a select to enhance, or a text input.
 *
 * @param {Object} column From the wrapper's data-columns.
 * @param {Boolean} readonly Locked field: never enhanced.
 * @returns {HTMLElement}
 */
const createControl = (column, readonly) => {
    let control;
    if (column.autocomplete && !readonly) {
        control = document.createElement('select');
        control.dataset.domain = column.domain;
        control.dataset.open = column.open ? '1' : '0';
        if (column.parent) {
            control.dataset.parent = column.parent;
            control.dataset.parentdomain = column.parentdomain || '';
            control.dataset.parentlabel = column.parentlabel || '';
            control.dataset.parentcode = '';
        }
    } else {
        control = document.createElement('input');
        control.type = 'text';
        control.className = 'form-control';
    }
    control.dataset.key = column.key;
    control.dataset.label = column.label;
    if (column.required) {
        // Not the required attribute: it would block the submit of a set left empty on purpose.
        control.setAttribute('aria-required', 'true');
    }
    return control;
};

/**
 * Build one empty set row node to be used as cloning template.
 *
 * @param {Array} columns Objects with key, label and required, plus the dictionary properties.
 * @param {Object} strings
 * @param {Boolean} readonly
 * @returns {HTMLElement}
 */
const createSetTemplate = (columns, strings, readonly) => {
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

        cell.appendChild(label);
        cell.appendChild(createControl(column, readonly));
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
            const parentcell = input.closest('td');
            const label = parentcell ? parentcell.querySelector('[data-region="subitem-label"]') : null;
            const name = `${String(input.dataset.label)} (${renderedtitle})`;

            if (input.tagName === 'SELECT') {
                // Core moves the label to its text input and addresses the select by id: the id never changes.
                if (!input.id) {
                    selectcounter++;
                    input.id = `${hiddenid}_ac${selectcounter}`;
                    if (label) {
                        label.setAttribute('for', input.id);
                    }
                }
                if (label) {
                    label.textContent = name;
                }
                return;
            }

            const id = `${hiddenid}_${number}_${fieldindex + 1}`;
            input.id = id;
            if (label) {
                label.setAttribute('for', id);
            }
            input.setAttribute('aria-label', name);
        });
    });
};

/**
 * Set the markup of an option.
 *
 * Parsed outside the select: the HTML parser drops elements inside an option's innerHTML, and
 * core shows the option's markup in the chip.
 *
 * @param {HTMLOptionElement} option
 * @param {String} html
 */
const setOptionHtml = (option, html) => {
    const holder = document.createElement('div');
    holder.innerHTML = html;
    option.replaceChildren(...holder.childNodes);
};

/**
 * The markup a select shows for a code: "label (code)" as the search does, or the bare code.
 *
 * @param {HTMLSelectElement} select
 * @param {String} code
 * @param {Object} labels Domain => code => plain label, preloaded by the server.
 * @param {Object} strings
 * @returns {String}
 */
const optionHtml = (select, code, labels, strings) => {
    const domainlabels = labels[select.dataset.domain] || {};
    if (Object.prototype.hasOwnProperty.call(domainlabels, code.trim())) {
        return escapeHtml(`${domainlabels[code.trim()]} (${code})`);
    }
    return select.dataset.open === '1' ? newCodeHtml(code, strings.newcode) : escapeHtml(code);
};

/**
 * Build one set from cloning template and optional values.
 *
 * @param {HTMLElement} template
 * @param {Object} values
 * @param {Object} labels Domain => code => plain label.
 * @param {Object} strings
 * @returns {HTMLElement}
 */
const cloneSetFromTemplate = (template, values = {}, labels = {}, strings = {}) => {
    const node = template.cloneNode(true);
    const inputs = Array.from(node.querySelectorAll(SELECTORS.input));
    const valueOf = (key) => (Object.prototype.hasOwnProperty.call(values, key) ? String(values[key]) : '');

    inputs.forEach((input) => {
        const value = valueOf(input.dataset.key);
        if (input.tagName !== 'SELECT') {
            input.value = value;
            return;
        }
        if (input.dataset.parent) {
            input.dataset.parentcode = valueOf(input.dataset.parent).trim();
        }
        if (value.trim() !== '') {
            const option = document.createElement('option');
            option.value = value;
            setOptionHtml(option, optionHtml(input, value, labels, strings));
            option.selected = true;
            input.appendChild(option);
        }
    });

    return node;
};

/**
 * Enhance the selects of one set with core/form-autocomplete; core enhances an element once.
 *
 * @param {HTMLElement} set
 * @param {Object} strings
 */
const enhanceSet = (set, strings) => {
    Array.from(set.querySelectorAll('select[data-key]')).forEach((select) => {
        Autocomplete.enhance(`#${CSS.escape(select.id)}`, select.dataset.open === '1', TRANSPORT,
            strings.searchplaceholder, false, true, '');
    });
};

/**
 * The code a control holds.
 *
 * For an enhanced select accepting any code, text typed but not yet turned into a value counts:
 * core turns it into one on submit too, but asynchronously, after this module has read the value.
 *
 * @param {HTMLElement} control
 * @returns {String}
 */
const controlValue = (control) => {
    if (control.tagName !== 'SELECT') {
        return String(control.value);
    }
    if (control.dataset.open === '1') {
        const cell = control.closest('td');
        const typed = cell ? cell.querySelector(SELECTORS.autocompleteInput) : null;
        if (typed && typed.value.trim() !== '') {
            return typed.value.trim();
        }
    }
    return String(control.value);
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
            valuesbykey[input.dataset.key] = controlValue(input);
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
 * @param {Object} strings
 */
const ensureOneEmptySet = (wrapper, template, hiddenid, strings) => {
    const container = wrapper.querySelector(SELECTORS.sets);
    if (!container) {
        return;
    }

    if (!container.querySelector(SELECTORS.set)) {
        const set = cloneSetFromTemplate(template);
        container.appendChild(set);
        updateSetMetadata(wrapper, hiddenid, strings.repeatableset);
        enhanceSet(set, strings);
    }
};

/**
 * Remove one set from the form.
 *
 * A set holding an enhanced select stays in the page, hidden and without labels: core's
 * autocomplete keeps handlers on the form that look its elements up by id on submit, and a
 * deleted element makes them throw.
 *
 * @param {HTMLElement} set
 */
const removeSet = (set) => {
    if (!set.querySelector('select[data-key]')) {
        set.remove();
        return;
    }
    set.dataset.removed = '1';
    set.hidden = true;
    Array.from(set.querySelectorAll('[data-region="subitem-label"], [data-region="set-title"]')).forEach((node) => {
        node.textContent = '';
    });
};

/**
 * Point the dependent columns of a set at a parent's new code, and empty them when it changed.
 *
 * @param {HTMLElement} control The parent column's control.
 */
const updateDependents = (control) => {
    const set = control.closest(SELECTORS.set);
    if (!set) {
        return;
    }
    const code = controlValue(control).trim();
    Array.from(set.querySelectorAll('select[data-parent]')).forEach((child) => {
        if (child.dataset.parent !== control.dataset.key || child.dataset.parentcode === code) {
            return;
        }
        child.dataset.parentcode = code;
        const cell = child.closest('td');
        if (!cell) {
            return;
        }
        // Core deselects a value when its chip is clicked, which also re-renders the chips.
        Array.from(cell.querySelectorAll(SELECTORS.selection)).forEach((chip) => chip.click());
        const typed = cell.querySelector(SELECTORS.autocompleteInput);
        if (typed) {
            typed.value = '';
        }
    });
};

/**
 * Mark a code typed into a column accepting any code as new, once core has made it a value.
 *
 * Core creates such a value (Enter, comma, leaving the field) as a bare option; the "Use code"
 * suggestion already carries the marker.
 *
 * @param {HTMLSelectElement} select
 * @param {Object} strings
 */
const markCreatedCode = (select, strings) => {
    const option = select.selectedOptions[0];
    if (!option || !option.dataset.iscustom || option.dataset.marked) {
        return;
    }
    option.dataset.marked = '1';
    setOptionHtml(option, newCodeHtml(option.value, strings.newcode));
    const cell = select.closest('td');
    const chip = cell ? cell.querySelector(`.form-autocomplete-selection [data-value="${CSS.escape(option.value)}"]`) : null;
    if (chip && !chip.querySelector('.profilefield-repeatable-newcode')) {
        const marker = document.createElement('span');
        marker.className = 'profilefield-repeatable-newcode';
        marker.textContent = strings.newcode;
        chip.append(' ', marker);
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
 * Read the labels the server preloaded for the codes the form holds.
 *
 * @param {HTMLElement} hidden
 * @returns {Object} Domain => code => plain label; empty when the attribute is unreadable.
 */
const readLabels = (hidden) => {
    try {
        const labels = JSON.parse(hidden.dataset.labels || '{}');
        return labels && typeof labels === 'object' && !Array.isArray(labels) ? labels : {};
    } catch (_e) {
        return {};
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
 *     The labels of the codes the form holds come from the hidden input's data-labels attribute.
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
    const given = config.strings || {};
    const strings = {
        addnewset: given.addnewset || 'Add new set',
        removeset: given.removeset || 'Remove set',
        repeatableset: given.repeatableset || 'Set {no}',
        newcode: given.newcode || '',
        searchplaceholder: given.searchplaceholder || '',
    };

    const columns = readColumns(wrapper);
    const keys = columns.map((column) => column.key);
    const labels = readLabels(hidden);
    const settemplate = createSetTemplate(columns, strings, readonly);

    const existingsets = parsePayloadToSetList(hidden.value, keys);
    if (existingsets.length) {
        existingsets.forEach((setvalues) => {
            setscontainer.appendChild(cloneSetFromTemplate(settemplate, setvalues, labels, strings));
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

    Array.from(setscontainer.querySelectorAll(SELECTORS.set)).forEach((set) => enhanceSet(set, strings));

    wrapper.addEventListener('click', (event) => {
        const addbutton = event.target.closest(SELECTORS.addSet);
        if (addbutton) {
            event.preventDefault();
            const set = cloneSetFromTemplate(settemplate);
            setscontainer.appendChild(set);
            updateSetMetadata(wrapper, config.hiddenid, strings.repeatableset);
            enhanceSet(set, strings);
            return;
        }

        const removebutton = event.target.closest(SELECTORS.removeSet);
        if (removebutton) {
            event.preventDefault();
            const set = removebutton.closest(SELECTORS.set);
            if (set) {
                removeSet(set);
                ensureOneEmptySet(wrapper, settemplate, config.hiddenid, strings);
                updateSetMetadata(wrapper, config.hiddenid, strings.repeatableset);
            }
        }
    });

    // Core's autocomplete dispatches change on the select; a text input fires it on leaving.
    wrapper.addEventListener('change', (event) => {
        const control = event.target.closest(SELECTORS.input);
        if (!control || !wrapper.contains(control)) {
            return;
        }
        if (control.tagName === 'SELECT' && control.dataset.open === '1') {
            markCreatedCode(control, strings);
        }
        updateDependents(control);
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
