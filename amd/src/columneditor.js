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
 * Column editor of the repeatable field definition form.
 *
 * Enhances the schema textarea (param1) of profile_define_repeatable: the textarea is hidden and
 * rewritten from the editor on every change, so the form posts the JSON the server validates
 * ({@see \profilefield_repeatable\local\schema::validate()}). The error region of the textarea's
 * form row stays visible and describes the editor. Without JavaScript the textarea is edited as
 * text.
 *
 * @module     profilefield_repeatable/columneditor
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';
import Notification from 'core/notification';

/** Limits, in step with \profilefield_repeatable\local\schema. */
const LIMITS = {columns: 20, subline: 3, keylength: 40};

/** Modes; "pattern" is offered only when the server says customfield_pattern is installed. */
const MODES = [
    {value: 'free', label: 'modefree', help: 'modefreehelp'},
    {value: 'dictionary', label: 'modedictionary', help: 'modedictionaryhelp'},
    {value: 'dictionary_open', label: 'modedictionaryopen', help: 'modedictionaryopenhelp'},
    {value: 'pattern', label: 'modepattern', help: 'modepatternhelp'},
];

/** The configuration of a new pattern column, in the order the server writes it. */
const PATTERN_DEFAULTS = {preset: 'none', mask: '', regex: '', regexerror: '', storeformat: 'masked'};

/** Strings fetched once, raw; their placeholders are filled by fill(). */
const STRINGKEYS = [
    'addcolumn', 'columncount', 'columndomain', 'columndomainhint', 'columndomainnocompanion', 'columnkey',
    'columnkeyhint', 'columnkeylocked', 'columnlabel', 'columnlabelhint', 'columnmode', 'columnnew', 'columnparent',
    'columnparenthint', 'columnrole', 'columnsindata', 'domainoption', 'domainrestricted', 'domainvisible',
    'modedictionary', 'modedictionaryhelp', 'modedictionaryopen', 'modedictionaryopenhelp', 'modefree', 'modefreehelp',
    'modepattern', 'modepatternhelp', 'modepatternunavailable', 'modepatternunavailablehelp', 'movedown', 'moveup',
    'newcolumn', 'parentmismatch', 'parentnone', 'patternmask', 'patternpreset', 'patternregex', 'patternregexerror',
    'patternregexerrorhint', 'patternregexhint', 'patternregexplaceholder', 'patternstoreformat', 'patternstoremasked',
    'patternstoreraw', 'patterntokens', 'removecolumn', 'removecolumnlocked', 'rolerequired', 'rolerequiredlabel',
    'rolesubline', 'rolesublinelabel', 'roletitle', 'roletitlelabel', 'summarysubline', 'summarytitle',
];

/**
 * Replace the placeholders of a raw string, as M.util.get_string() does.
 *
 * @param {String} template
 * @param {*} a A scalar for {$a}, or an object for {$a->name}.
 * @returns {String}
 */
const fill = (template, a) => {
    if (a !== null && typeof a === 'object') {
        return template.replace(/\{\$a->(\w+)\}/g, (match, name) => String(a[name] ?? ''));
    }
    // A replacer function, so a "$" in the value is never read as a replacement pattern.
    return a === undefined ? template : template.replace(/\{\$a\}/g, () => String(a));
};

/**
 * Generate a key from a label; mirrors \profilefield_repeatable\local\schema::generate_key().
 *
 * @param {String} label
 * @param {Set} taken Identifiers reserved by the other columns.
 * @returns {String}
 */
export const generateKey = (label, taken) => {
    let slug = String(label).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
        .replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    if (slug === '') {
        slug = 'column';
    } else if (!/^[a-z]/.test(slug)) {
        slug = 'column_' + slug;
    }

    let suffix = '';
    let counter = 1;
    for (;;) {
        const candidate = slug.slice(0, LIMITS.keylength - suffix.length).replace(/_+$/, '') + suffix;
        const reserved = [candidate, candidate + '_name', candidate + '_code'];
        if (candidate !== 'setnumber' && reserved.every((identifier) => !taken.has(identifier))) {
            return candidate;
        }
        counter++;
        suffix = '_' + counter;
    }
};

/**
 * Identifiers reserved by every column but one.
 *
 * @param {Array} columns
 * @param {Number} except Index left out.
 * @returns {Set}
 */
const takenBy = (columns, except) => {
    const taken = new Set();
    columns.forEach((column, index) => {
        if (index !== except) {
            [column.key, column.key + '_name', column.key + '_code'].forEach((identifier) => taken.add(identifier));
        }
    });
    return taken;
};

/**
 * Whether a mode reads codes from a dictionary.
 *
 * @param {String} mode
 * @returns {Boolean}
 */
const isDictionary = (mode) => mode === 'dictionary' || mode === 'dictionary_open';

/**
 * Whether a value is a plain object (a JSON object, not an array).
 *
 * @param {*} value
 * @returns {Boolean}
 */
const isObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);

/**
 * The schema document of the editor state, in the property order the server writes.
 *
 * @param {Array} columns
 * @returns {Object}
 */
const toDocument = (columns) => ({
    version: 2,
    columns: columns.map((column) => {
        const out = {key: column.key, label: column.label, mode: column.mode};
        if (isDictionary(column.mode)) {
            out.domain = column.domain || '';
            if (column.parent) {
                out.parent = column.parent;
            }
        }
        if (column.mode === 'pattern' && column.pattern) {
            out.pattern = column.pattern;
        }
        ['title', 'subline', 'required'].forEach((flag) => {
            if (column[flag]) {
                out[flag] = true;
            }
        });
        return out;
    }),
});

/**
 * Read the columns of the textarea.
 *
 * @param {String} json
 * @returns {Array|null} Null when the text is neither empty nor a version 2 schema.
 */
const parseColumns = (json) => {
    if (String(json).trim() === '') {
        return [];
    }
    try {
        const document = JSON.parse(json);
        if (!document || document.version !== 2 || !Array.isArray(document.columns)) {
            return null;
        }
        return document.columns.filter((column) => column && typeof column === 'object').map((column) => ({
            key: String(column.key ?? ''),
            label: String(column.label ?? ''),
            mode: String(column.mode || 'free'),
            domain: String(column.domain ?? ''),
            parent: String(column.parent ?? ''),
            pattern: column.pattern ?? null,
            storedpattern: column.mode === 'pattern',
            title: !!column.title,
            subline: !!column.subline,
            required: !!column.required,
            stored: true,
        }));
    } catch (_e) {
        return null;
    }
};

/**
 * Read a JSON data attribute.
 *
 * @param {HTMLElement} element
 * @param {String} name
 * @param {*} fallback
 * @returns {*}
 */
const readData = (element, name, fallback) => {
    try {
        const value = JSON.parse(element.dataset[name] || '');
        return value ?? fallback;
    } catch (_e) {
        return fallback;
    }
};

/**
 * Create an element.
 *
 * @param {String} tag
 * @param {Object} attributes Properties set on the element; "dataset" and "aria" are objects.
 * @param {Array} children Nodes or strings.
 * @returns {HTMLElement}
 */
const make = (tag, attributes = {}, children = []) => {
    const element = document.createElement(tag);
    Object.entries(attributes).forEach(([name, value]) => {
        if (name === 'dataset') {
            Object.assign(element.dataset, value);
        } else if (name === 'attrs') {
            Object.entries(value).forEach(([attr, attrvalue]) => element.setAttribute(attr, attrvalue));
        } else {
            element[name] = value;
        }
    });
    children.forEach((child) => element.append(child));
    return element;
};

/**
 * One column editor bound to a schema textarea.
 */
class Editor {
    /**
     * Constructor.
     *
     * @param {HTMLTextAreaElement} textarea
     * @param {Array} columns Parsed columns.
     * @param {Object} strings Fetched strings by key.
     */
    constructor(textarea, columns, strings) {
        this.textarea = textarea;
        this.columns = columns;
        this.strings = strings;
        this.locked = new Set(readData(textarea, 'lockedkeys', []));
        this.users = parseInt(textarea.dataset.users || '0', 10) || 0;
        this.domains = readData(textarea, 'domains', []);
        this.companion = textarea.dataset.companion === '1';
        // Without customfield_pattern a stored pattern column is kept as it is: no panel, no change.
        this.pattern = textarea.dataset.pattern === '1';
        this.presets = readData(textarea, 'presets', []);
        this.open = columns.length ? -1 : 0;
        this.uid = textarea.id || textarea.name;

        const feedback = textarea.closest('.fitem')?.querySelector('.invalid-feedback, .form-control-feedback');
        this.root = make('div', {className: 'profilefield-repeatable-columneditor', attrs: {role: 'group'}});
        if (feedback && feedback.id) {
            this.root.setAttribute('aria-describedby', feedback.id);
        }
        textarea.before(this.root);
        textarea.hidden = true;

        this.root.addEventListener('click', (event) => this.click(event));
        this.root.addEventListener('change', (event) => this.change(event));
        this.root.addEventListener('input', (event) => this.input(event));
        textarea.form?.addEventListener('submit', () => this.write());

        this.render();
    }

    /**
     * A fetched string, with its placeholders filled.
     *
     * @param {String} key
     * @param {*} a The string's parameter.
     * @returns {String}
     */
    str(key, a = undefined) {
        return fill(this.strings[key] || key, a);
    }

    /**
     * Write the schema to the textarea.
     */
    write() {
        this.textarea.value = JSON.stringify(toDocument(this.columns), null, 2);
    }

    /**
     * Whether a column's key is locked: it holds stored values.
     *
     * @param {Object} column
     * @returns {Boolean}
     */
    isLocked(column) {
        return column.stored && this.locked.has(column.key);
    }

    /**
     * Rebuild the editor from the state and write the schema.
     *
     * @param {String|null} focus Selector, inside the editor, of the control to focus afterwards.
     */
    render(focus = null) {
        this.write();
        const nodes = [];
        if (this.users > 0) {
            nodes.push(make('div', {className: 'alert alert-info profilefield-repeatable-ce-banner', attrs: {role: 'status'}},
                [this.str('columnsindata', this.users)]));
        }
        nodes.push(this.renderSummary());
        nodes.push(make('ol', {className: 'profilefield-repeatable-ce-list'},
            this.columns.map((column, index) => this.renderColumn(column, index))));

        const add = make('button', {
            type: 'button', className: 'btn btn-secondary', disabled: this.columns.length >= LIMITS.columns,
            dataset: {action: 'add'},
        }, [this.str('addcolumn')]);
        nodes.push(make('div', {className: 'profilefield-repeatable-ce-footer'}, [
            add,
            make('span', {className: 'profilefield-repeatable-ce-muted'},
                [this.str('columncount', {count: this.columns.length, max: LIMITS.columns})]),
        ]));

        this.root.replaceChildren(...nodes);
        if (focus) {
            this.root.querySelector(focus)?.focus();
        }
    }

    /**
     * The chips summarising title and subline use.
     *
     * @returns {HTMLElement}
     */
    renderSummary() {
        const title = this.columns.find((column) => column.title);
        const subline = this.columns.filter((column) => column.subline).length;
        return make('div', {className: 'profilefield-repeatable-ce-summary'}, [
            make('span', {className: 'profilefield-repeatable-ce-chip', dataset: {region: 'titlechip'}},
                [this.str('summarytitle', title ? title.label : '-')]),
            make('span', {className: 'profilefield-repeatable-ce-chip' + (subline >= LIMITS.subline ? ' is-full' : '')},
                [this.str('summarysubline', {count: subline, max: LIMITS.subline})]),
        ]);
    }

    /**
     * One column: its header row and, when open, its settings.
     *
     * @param {Object} column
     * @param {Number} index
     * @returns {HTMLElement}
     */
    renderColumn(column, index) {
        const open = this.open === index;
        const bodyid = `${this.uid}-column-${index}`;
        const chips = [];
        if (column.title) {
            chips.push(this.str('roletitle'));
        }
        if (column.subline) {
            chips.push(this.str('rolesubline'));
        }
        if (column.required) {
            chips.push(this.str('rolerequired'));
        }
        if (!column.stored) {
            chips.push(this.str('columnnew'));
        }

        const head = make('div', {className: 'profilefield-repeatable-ce-head'}, [
            make('div', {className: 'profilefield-repeatable-ce-move'}, [
                make('button', {
                    type: 'button', className: 'btn btn-secondary btn-sm', disabled: index === 0,
                    dataset: {action: 'up', index: String(index)}, attrs: {'aria-label': this.str('moveup', column.label)},
                }, [make('i', {className: 'icon fa fa-chevron-up fa-fw m-0', attrs: {'aria-hidden': 'true'}})]),
                make('button', {
                    type: 'button', className: 'btn btn-secondary btn-sm', disabled: index === this.columns.length - 1,
                    dataset: {action: 'down', index: String(index)}, attrs: {'aria-label': this.str('movedown', column.label)},
                }, [make('i', {className: 'icon fa fa-chevron-down fa-fw m-0', attrs: {'aria-hidden': 'true'}})]),
            ]),
            make('span', {className: 'profilefield-repeatable-ce-number'}, [String(index + 1)]),
            make('button', {
                type: 'button', className: 'profilefield-repeatable-ce-toggle',
                dataset: {action: 'toggle', index: String(index)},
                attrs: {'aria-expanded': open ? 'true' : 'false', 'aria-controls': bodyid},
            }, [
                make('span', {className: 'profilefield-repeatable-ce-label', dataset: {region: 'label'}}, [column.label]),
                make('span', {className: 'profilefield-repeatable-ce-muted'}, [
                    make('span', {className: 'profilefield-repeatable-ce-key', dataset: {region: 'key'}}, [column.key]),
                    ' · ' + this.modeSummary(column),
                ]),
            ]),
            make('span', {className: 'profilefield-repeatable-ce-chips'},
                chips.map((chip) => make('span', {className: 'profilefield-repeatable-ce-chip'}, [chip]))),
        ]);

        const item = make('li', {className: 'profilefield-repeatable-ce-column' + (open ? ' is-open' : '')}, [head]);
        if (open) {
            item.append(this.renderBody(column, index, bodyid));
        }
        return item;
    }

    /**
     * Mode and domain of a column, for its header.
     *
     * @param {Object} column
     * @returns {String}
     */
    modeSummary(column) {
        const mode = MODES.find((candidate) => candidate.value === column.mode);
        const label = mode ? this.str(mode.label) : column.mode;
        if (column.mode === 'pattern' && !this.pattern) {
            return this.str('modepatternunavailable');
        }
        if (column.mode === 'pattern' && isObject(column.pattern)) {
            const preset = column.pattern.preset || 'none';
            const known = this.presets.find((candidate) => candidate.value === preset);
            let shape = known ? known.label : preset;
            if (preset === 'custom') {
                shape = column.pattern.mask;
            }
            return shape ? `${label} · ${shape}` : label;
        }
        return isDictionary(column.mode) && column.domain ? `${label} · ${column.domain}` : label;
    }

    /**
     * The settings of the open column.
     *
     * @param {Object} column
     * @param {Number} index
     * @param {String} bodyid
     * @returns {HTMLElement}
     */
    renderBody(column, index, bodyid) {
        const locked = this.isLocked(column);
        const id = (name) => `${bodyid}-${name}`;
        const field = (name, label, control, hint) => make('div', {className: 'profilefield-repeatable-ce-field'}, [
            make('label', {htmlFor: id(name)}, [label]),
            control,
            make('span', {className: 'profilefield-repeatable-ce-muted', id: id(name) + '-hint'}, [hint]),
        ]);

        const labelinput = make('input', {
            type: 'text', className: 'form-control', id: id('label'), value: column.label,
            dataset: {field: 'label', index: String(index)}, attrs: {'aria-describedby': id('label') + '-hint'},
        });
        const keyinput = make('input', {
            type: 'text', className: 'form-control profilefield-repeatable-ce-key', id: id('key'), value: column.key,
            readOnly: locked, maxLength: LIMITS.keylength,
            dataset: {field: 'key', index: String(index)}, attrs: {'aria-describedby': id('key') + '-hint'},
        });
        const keycontrol = make('span', {className: 'profilefield-repeatable-ce-keyrow'}, [keyinput]);
        if (locked) {
            keycontrol.append(make('i', {className: 'icon fa fa-lock fa-fw', attrs: {'aria-hidden': 'true'}}));
        }

        const children = [
            make('div', {className: 'profilefield-repeatable-ce-pair'}, [
                field('label', this.str('columnlabel'), labelinput, this.str('columnlabelhint')),
                field('key', this.str('columnkey'), keycontrol, this.str(locked ? 'columnkeylocked' : 'columnkeyhint')),
            ]),
            this.renderModes(column, index, id),
        ];
        if (isDictionary(column.mode)) {
            children.push(this.renderDictionary(column, index, id, field));
        }
        if (column.mode === 'pattern' && this.pattern) {
            children.push(this.renderPattern(column, index, id));
        }
        children.push(this.renderRoles(column, index, id));
        // A column holding stored values cannot be removed; the server rejects it as well.
        children.push(make('div', {className: 'profilefield-repeatable-ce-actions'}, [
            make('button', {
                type: 'button', className: 'btn btn-danger', disabled: locked,
                dataset: {action: 'remove', index: String(index)},
                attrs: locked ? {title: this.str('removecolumnlocked')} : {},
            }, [this.str('removecolumn')]),
        ]));

        return make('div', {className: 'profilefield-repeatable-ce-body', id: bodyid}, children);
    }

    /**
     * Radio buttons choosing how users fill the column.
     *
     * @param {Object} column
     * @param {Number} index
     * @param {Function} id
     * @returns {HTMLElement}
     */
    renderModes(column, index, id) {
        const modes = MODES.filter((mode) => mode.value !== 'pattern' || this.pattern).map((mode) => ({
            value: mode.value, name: this.str(mode.label), help: this.str(mode.help),
        }));
        if (!this.pattern && column.storedpattern) {
            // A stored pattern column without the plugin: it can go back to its stored configuration, never to a new one.
            modes.push({value: 'pattern', name: this.str('modepatternunavailable'), help: this.str('modepatternunavailablehelp')});
        }
        return make('fieldset', {className: 'profilefield-repeatable-ce-fieldset'}, [
            make('legend', {}, [this.str('columnmode')]),
            make('div', {className: 'profilefield-repeatable-ce-modes'}, modes.map((mode) => make('div', {
                className: 'form-check profilefield-repeatable-ce-mode' + (column.mode === mode.value ? ' is-checked' : ''),
            }, [
                make('input', {
                    type: 'radio', className: 'form-check-input', id: id('mode-' + mode.value), name: id('mode'),
                    value: mode.value, checked: column.mode === mode.value, dataset: {field: 'mode', index: String(index)},
                }),
                make('label', {className: 'form-check-label', htmlFor: id('mode-' + mode.value)}, [
                    make('span', {className: 'profilefield-repeatable-ce-modename'}, [mode.name]),
                    make('span', {className: 'profilefield-repeatable-ce-muted'}, [mode.help]),
                ]),
            ]))),
        ]);
    }

    /**
     * Preset, mask, storage and regular expression of a pattern column (customfield_pattern).
     *
     * The mask of a preset is shown read-only; only "Custom mask" takes a mask of its own. The
     * server checks the configuration with customfield_pattern on save.
     *
     * @param {Object} column
     * @param {Number} index
     * @param {Function} id
     * @returns {HTMLElement}
     */
    renderPattern(column, index, id) {
        const pattern = this.patternOf(column);
        const custom = pattern.preset === 'custom';
        const preset = this.presets.find((candidate) => candidate.value === pattern.preset);
        const field = (name, label, control, hint, wide = false) => make('div', {
            className: 'profilefield-repeatable-ce-field ' + (wide ? 'is-wide' : 'is-third'),
        }, [
            make('label', {htmlFor: id(name)}, [label]),
            control,
            ...(hint ? [make('span', {className: 'profilefield-repeatable-ce-muted', id: id(name) + '-hint'}, [hint])] : []),
        ]);
        const select = (name, options, value) => make('select', {
            className: 'form-control', id: id(name), dataset: {field: name, index: String(index)},
        }, options.map((option) => make('option', {value: option.value, selected: option.value === value}, [option.label])));

        const presets = this.presets.map((candidate) => ({value: candidate.value, label: candidate.label}));
        if (!preset) {
            // A preset this version of the plugin does not offer; the server reports it on save.
            presets.push({value: pattern.preset, label: pattern.preset});
        }
        // A preset's mask is shown for reference; only "Custom mask" is typed.
        let maskvalue = preset ? preset.mask : '';
        if (custom) {
            maskvalue = pattern.mask;
        }
        const mask = make('input', {
            type: 'text', className: 'form-control profilefield-repeatable-ce-code', id: id('mask'),
            value: maskvalue, readOnly: !custom,
            dataset: {field: 'mask', index: String(index)},
        });
        const regex = make('input', {
            type: 'text', className: 'form-control profilefield-repeatable-ce-code', id: id('regex'), value: pattern.regex,
            placeholder: this.str('patternregexplaceholder'), dataset: {field: 'regex', index: String(index)},
            attrs: {'aria-describedby': id('regex') + '-hint'},
        });
        const regexerror = make('input', {
            type: 'text', className: 'form-control', id: id('regexerror'), value: pattern.regexerror,
            dataset: {field: 'regexerror', index: String(index)}, attrs: {'aria-describedby': id('regexerror') + '-hint'},
        });

        return make('div', {className: 'profilefield-repeatable-ce-pair profilefield-repeatable-ce-box'}, [
            field('preset', this.str('patternpreset'), select('preset', presets, pattern.preset), ''),
            field('mask', this.str('patternmask'), mask, ''),
            field('storeformat', this.str('patternstoreformat'), select('storeformat', [
                {value: 'masked', label: this.str('patternstoremasked')},
                {value: 'raw', label: this.str('patternstoreraw')},
            ], pattern.storeformat), ''),
            field('regex', this.str('patternregex'), regex, this.str('patternregexhint'), true),
            field('regexerror', this.str('patternregexerror'), regexerror, this.str('patternregexerrorhint'), true),
            make('p', {className: 'profilefield-repeatable-ce-muted profilefield-repeatable-ce-tokens'},
                [this.str('patterntokens')]),
        ]);
    }

    /**
     * The pattern configuration of a column, created with the defaults when it has none.
     *
     * Settings the editor does not know are kept, so the server receives them back.
     *
     * @param {Object} column
     * @returns {Object}
     */
    patternOf(column) {
        if (!isObject(column.pattern)) {
            column.pattern = {...PATTERN_DEFAULTS};
        }
        Object.entries(PATTERN_DEFAULTS).forEach(([name, value]) => {
            if (typeof column.pattern[name] !== 'string') {
                column.pattern[name] = value;
            }
        });
        return column.pattern;
    }

    /**
     * Domain and parent of a dictionary column.
     *
     * @param {Object} column
     * @param {Number} index
     * @param {Function} id
     * @param {Function} field
     * @returns {HTMLElement}
     */
    renderDictionary(column, index, id, field) {
        let domaincontrol;
        let hint = this.str('columndomainnocompanion');
        if (this.companion) {
            const options = [make('option', {value: ''}, ['-'])];
            this.domains.forEach((domain) => {
                options.push(make('option', {value: domain.shortname, selected: domain.shortname === column.domain},
                    [this.str('domainoption', {name: domain.name, itemcount: domain.itemcount})]));
            });
            if (column.domain && !this.domains.some((domain) => domain.shortname === column.domain)) {
                options.push(make('option', {value: column.domain, selected: true}, [column.domain]));
            }
            domaincontrol = make('select', {
                className: 'form-control', id: id('domain'), dataset: {field: 'domain', index: String(index)},
                attrs: {'aria-describedby': id('domain') + '-hint'},
            }, options);
            const domain = this.domains.find((candidate) => candidate.shortname === column.domain);
            if (domain) {
                hint = this.str('columndomainhint') + ' ' + this.str(domain.visibility ? 'domainvisible' : 'domainrestricted');
            } else {
                hint = this.str('columndomainhint');
            }
        } else {
            domaincontrol = make('input', {
                type: 'text', className: 'form-control', id: id('domain'), value: column.domain,
                dataset: {field: 'domain', index: String(index)}, attrs: {'aria-describedby': id('domain') + '-hint'},
            });
        }

        // Only an earlier dictionary column can be a parent, so a set is filled parent first.
        const parents = [make('option', {value: ''}, [this.str('parentnone')])];
        this.columns.slice(0, index).filter((candidate) => isDictionary(candidate.mode)).forEach((candidate) => {
            parents.push(make('option', {value: candidate.key, selected: candidate.key === column.parent}, [candidate.label]));
        });
        const parentcontrol = make('select', {
            className: 'form-control', id: id('parent'), dataset: {field: 'parent', index: String(index)},
            attrs: {'aria-describedby': id('parent') + '-hint'},
        }, parents);

        const children = [
            field('domain', this.str('columndomain'), domaincontrol, hint),
            field('parent', this.str('columnparent'), parentcontrol, this.str('columnparenthint')),
        ];
        const parentdomain = this.parentMismatch(column);
        if (parentdomain !== null) {
            children.push(make('p', {
                className: 'profilefield-repeatable-ce-warning', id: id('parentmismatch'), attrs: {role: 'alert'},
            }, [this.str('parentmismatch', {domain: column.domain, parentdomain})]));
            parentcontrol.setAttribute('aria-describedby', `${id('parent')}-hint ${id('parentmismatch')}`);
        }

        return make('div', {className: 'profilefield-repeatable-ce-pair profilefield-repeatable-ce-box'}, children);
    }

    /**
     * The domain a column's parent column should use, when the chosen one uses another.
     *
     * Known only when the companion reports the domain holding the parents of the column's
     * domain; define_validate_specific() refuses the same schema on save.
     *
     * @param {Object} column
     * @returns {String|null} The expected parent domain, or null when there is nothing to warn about.
     */
    parentMismatch(column) {
        if (!this.companion || !column.parent) {
            return null;
        }
        const domain = this.domains.find((candidate) => candidate.shortname === column.domain);
        const parent = this.columns.find((candidate) => candidate.key === column.parent);
        if (!domain || !domain.parentdomain || !parent || !isDictionary(parent.mode)) {
            return null;
        }
        return parent.domain === domain.parentdomain ? null : domain.parentdomain;
    }

    /**
     * Title, subline and required settings, with the subline limit applied.
     *
     * @param {Object} column
     * @param {Number} index
     * @param {Function} id
     * @returns {HTMLElement}
     */
    renderRoles(column, index, id) {
        const subline = this.columns.filter((candidate) => candidate.subline).length;
        const check = (name, type, label, checked, disabled) => make('div', {className: 'form-check'}, [
            make('input', {
                type, className: 'form-check-input', id: id(name), name: type === 'radio' ? `${this.uid}-title` : id(name),
                checked, disabled, dataset: {field: name, index: String(index)},
            }),
            make('label', {className: 'form-check-label', htmlFor: id(name)}, [label]),
        ]);

        return make('fieldset', {className: 'profilefield-repeatable-ce-fieldset'}, [
            make('legend', {}, [this.str('columnrole')]),
            make('div', {className: 'profilefield-repeatable-ce-roles'}, [
                check('title', 'radio', this.str('roletitlelabel'), column.title, false),
                check('subline', 'checkbox', this.str('rolesublinelabel'), column.subline,
                    column.title || (!column.subline && subline >= LIMITS.subline)),
                check('required', 'checkbox', this.str('rolerequiredlabel'), column.required, false),
            ]),
        ]);
    }

    /**
     * Buttons: add, remove, move and open a column.
     *
     * @param {Event} event
     */
    click(event) {
        const button = event.target.closest('button[data-action]');
        if (!button || !this.root.contains(button)) {
            return;
        }
        event.preventDefault();
        const index = parseInt(button.dataset.index ?? '-1', 10);
        const action = button.dataset.action;

        if (action === 'add' && this.columns.length < LIMITS.columns) {
            const label = this.str('newcolumn', this.columns.length + 1);
            this.columns.push({
                key: generateKey(label, takenBy(this.columns, -1)), label, mode: 'free', domain: '', parent: '',
                pattern: null, title: !this.columns.some((column) => column.title), subline: false, required: false,
                stored: false, keyedited: false,
            });
            this.open = this.columns.length - 1;
            this.render(`#${CSS.escape(`${this.uid}-column-${this.open}-label`)}`);
        } else if (action === 'remove' && !this.isLocked(this.columns[index])) {
            const [removed] = this.columns.splice(index, 1);
            this.columns.forEach((column) => {
                column.parent = column.parent === removed.key ? '' : column.parent;
            });
            if (removed.title && this.columns.length) {
                this.columns[0].title = true;
                this.columns[0].subline = false;
            }
            this.open = -1;
            this.render('[data-action="add"]');
        } else if (action === 'up' || action === 'down') {
            const target = action === 'up' ? index - 1 : index + 1;
            [this.columns[index], this.columns[target]] = [this.columns[target], this.columns[index]];
            this.dropInvalidParents();
            this.open = this.open === index ? target : this.open;
            this.render(`[data-action="${action}"][data-index="${target}"]`);
        } else if (action === 'toggle') {
            this.open = this.open === index ? -1 : index;
            this.render(`[data-action="toggle"][data-index="${index}"]`);
        }
    }

    /**
     * Forget parents that no longer name an earlier dictionary column.
     */
    dropInvalidParents() {
        this.columns.forEach((column, index) => {
            const earlier = this.columns.slice(0, index).filter((candidate) => isDictionary(candidate.mode));
            if (column.parent && !earlier.some((candidate) => candidate.key === column.parent)) {
                column.parent = '';
            }
        });
    }

    /**
     * Typing in a text box: update in place, without rebuilding the inputs.
     *
     * @param {Event} event
     */
    input(event) {
        const control = event.target;
        const index = parseInt(control.dataset.index ?? '-1', 10);
        const column = this.columns[index];
        if (!column || control.tagName === 'SELECT') {
            return;
        }
        if (['mask', 'regex', 'regexerror'].includes(control.dataset.field)) {
            if (!control.readOnly) {
                this.patternOf(column)[control.dataset.field] = control.value;
                this.write();
            }
            return;
        }
        if (!['label', 'key', 'domain'].includes(control.dataset.field)) {
            return;
        }

        if (control.dataset.field === 'domain') {
            // The text box shown without the companion plugin.
            column.domain = control.value.trim().toLowerCase();
            this.write();
            return;
        }
        if (control.dataset.field === 'label') {
            column.label = control.value;
            if (!this.isLocked(column) && !column.keyedited && !column.stored) {
                column.key = generateKey(column.label, takenBy(this.columns, index));
                this.root.querySelector(`#${CSS.escape(`${this.uid}-column-${index}-key`)}`).value = column.key;
            }
        } else if (!this.isLocked(column)) {
            // Lenient while typing, so "_" can be typed; the server rejects what the key pattern does not allow.
            column.key = control.value.toLowerCase().replace(/[^a-z0-9_]/g, '_').slice(0, LIMITS.keylength);
            column.keyedited = true;
            control.value = column.key;
        }

        const item = control.closest('.profilefield-repeatable-ce-column');
        item.querySelector('[data-region="label"]').textContent = column.label;
        item.querySelector('[data-region="key"]').textContent = column.key;
        const title = this.columns.find((candidate) => candidate.title);
        this.root.querySelector('[data-region="titlechip"]').textContent = this.str('summarytitle', title ? title.label : '-');
        this.write();
    }

    /**
     * Mode, domain, parent and role controls.
     *
     * @param {Event} event
     */
    change(event) {
        const control = event.target;
        const index = parseInt(control.dataset.index ?? '-1', 10);
        const column = this.columns[index];
        const name = control.dataset.field;
        if (!column || !name || control.type === 'text') {
            return;
        }

        if (name === 'mode') {
            column.mode = control.value;
            if (column.mode === 'pattern' && this.pattern) {
                this.patternOf(column);
            }
            this.dropInvalidParents();
        } else if (name === 'preset') {
            const pattern = this.patternOf(column);
            if (pattern.preset === 'custom') {
                // Kept aside, so that going back to "Custom mask" restores what was typed.
                column.custommask = pattern.mask;
            }
            pattern.preset = control.value;
            pattern.mask = control.value === 'custom' ? (column.custommask ?? '') : '';
        } else if (name === 'storeformat') {
            this.patternOf(column).storeformat = control.value;
        } else if (name === 'domain' || name === 'parent') {
            column[name] = control.value.trim();
        } else if (name === 'title') {
            this.columns.forEach((candidate, position) => {
                candidate.title = position === index;
            });
            column.subline = false;
        } else {
            column[name] = control.checked;
        }

        this.render(`#${CSS.escape(control.id)}`);
    }
}

/**
 * Enhance every schema textarea of the page that is not enhanced yet.
 *
 * Called once per form render: the define form is a modal form, rendered again after a failed
 * validation.
 *
 * @returns {Promise}
 */
export const init = () => {
    const textareas = Array.from(document.querySelectorAll('textarea[data-region="profilefield-repeatable-schema"]'))
        .filter((textarea) => textarea.dataset.enhanced !== '1');
    if (!textareas.length) {
        return Promise.resolve();
    }

    return getStrings(STRINGKEYS.map((key) => ({key, component: 'profilefield_repeatable'}))).then((values) => {
        const strings = {};
        STRINGKEYS.forEach((key, index) => {
            strings[key] = values[index];
        });
        textareas.forEach((textarea) => {
            const columns = parseColumns(textarea.value);
            if (columns === null || textarea.dataset.enhanced === '1') {
                // Text the editor cannot read stays editable as text.
                return;
            }
            textarea.dataset.enhanced = '1';
            new Editor(textarea, columns, strings);
        });
        return strings;
    }).catch(Notification.exception);
};
