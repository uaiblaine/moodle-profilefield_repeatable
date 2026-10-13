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

namespace profilefield_repeatable\local;

/**
 * The one door to customfield_pattern, which pattern columns take their rules from.
 *
 * customfield_pattern is a soft dependency: it installs on Moodle 5.1 and later only, and a site
 * may leave it out. Every call goes through {@see self::available()}, which probes the public API
 * (\customfield_pattern\api) and its configuration version; without it a pattern column is plain
 * text: values are validated by nothing, stored as typed and shown as stored, and its stored
 * configuration is kept as it is ({@see schema::validate()}).
 *
 * A column's configuration is the column's "pattern" object, read through {@see self::config()}:
 * preset, mask, regex, regexerror and storeformat, each a string.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pattern_columns {
    /** @var string The companion's public API class. */
    public const API = 'customfield_pattern\api';

    /**
     * @var int The configuration version this field writes and reads; a companion on another one
     *          is treated as absent, because it may read a stored setting differently.
     */
    public const CONFIG_VERSION = 1;

    /** @var string[] Settings of a column's configuration, in the order the editor writes them. */
    public const CONFIG_KEYS = ['preset', 'mask', 'regex', 'regexerror', 'storeformat'];

    /** @var string[] Methods of the API this field calls. */
    private const METHODS = ['config_errors', 'validate', 'normalise', 'format', 'presets', 'masks', 'input_attributes',
        'js_init'];

    /**
     * Whether customfield_pattern offers the API pattern columns need.
     *
     * @return bool
     */
    public static function available(): bool {
        if (!class_exists(self::API)) {
            return false;
        }
        foreach (self::METHODS as $method) {
            if (!method_exists(self::API, $method)) {
                return false;
            }
        }
        return defined(self::API . '::CONFIG_VERSION') && constant(self::API . '::CONFIG_VERSION') === self::CONFIG_VERSION;
    }

    /**
     * The configuration of a pattern column, in the shape the API reads.
     *
     * @param array $column Normalised schema column.
     * @return string[] Setting => value; settings absent from the column, or not scalars, are left out.
     */
    public static function config(array $column): array {
        $raw = is_array($column['pattern'] ?? null) ? $column['pattern'] : [];
        $config = [];
        foreach (self::CONFIG_KEYS as $key) {
            if (isset($raw[$key]) && is_scalar($raw[$key])) {
                $config[$key] = trim((string)$raw[$key]);
            }
        }
        return $config;
    }

    /**
     * The problems of a column's configuration.
     *
     * @param array $config {@see self::config()}.
     * @return string[] Setting => message, safe to output as HTML; empty without the companion.
     */
    public static function config_errors(array $config): array {
        return self::available() ? \customfield_pattern\api::config_errors($config) : [];
    }

    /**
     * Validate one typed value.
     *
     * @param array $config {@see self::config()}.
     * @param string $value
     * @return string|null Null when valid or without the companion, else the companion's message,
     *                     safe to output as HTML like the result of get_string().
     */
    public static function validate(array $config, string $value): ?string {
        return self::available() ? \customfield_pattern\api::validate($config, $value, \core\context\system::instance()) : null;
    }

    /**
     * One value as it is stored: fitted into the mask, masked or raw per the configuration.
     *
     * @param array $config {@see self::config()}.
     * @param string $value
     * @return string The value unchanged without the companion.
     */
    public static function normalise(array $config, string $value): string {
        return self::available() ? \customfield_pattern\api::normalise($config, $value) : $value;
    }

    /**
     * One stored value as it is shown: a raw-stored value gets its mask back.
     *
     * @param array $config {@see self::config()}.
     * @param string $value
     * @return string Plain text, not escaped; the value unchanged without the companion.
     */
    public static function format(array $config, string $value): string {
        return self::available() ? \customfield_pattern\api::format($config, $value) : $value;
    }

    /**
     * Attributes of the text input a value is typed into.
     *
     * @param array $config {@see self::config()}.
     * @return array Attribute => unescaped value; empty without the companion.
     */
    public static function input_attributes(array $config): array {
        return self::available() ? \customfield_pattern\api::input_attributes($config) : [];
    }

    /**
     * Load the companion's typing formatter on the page; nothing without the companion.
     *
     * @return void
     */
    public static function js_init(): void {
        if (self::available()) {
            \customfield_pattern\api::js_init();
        }
    }

    /**
     * The presets the column editor offers, each with its mask.
     *
     * @return array List of ['value', 'label', 'mask'] (the mask '' for none and custom); empty
     *               without the companion.
     */
    public static function presets(): array {
        if (!self::available()) {
            return [];
        }
        $presets = [];
        foreach (\customfield_pattern\api::presets() as $value => $label) {
            $presets[] = [
                'value' => (string)$value,
                'label' => (string)$label,
                'mask' => implode('|', \customfield_pattern\api::masks(['preset' => (string)$value])),
            ];
        }
        return $presets;
    }
}
