# without_unsafe_columns(): a key breaking the pattern is kept.
s~\$safe = \(bool\)preg_match\(self::KEY_PATTERN, \$column\['key'\]\) && !isset~\$safe = !isset~s;
