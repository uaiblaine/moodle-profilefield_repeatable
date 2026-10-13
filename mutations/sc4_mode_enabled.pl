# validate(): the reserved pattern mode, or any other, is accepted.
s~if \(!in_array\(\$column\['mode'\], self::ENABLED_MODES, true\)\) \{~if (false) {~s;
