# The resolved-name column returns the label raw.
s~return s\(self::resolve_label\(\$domain, \$value\)\);~return self::resolve_label(\$domain, \$value);~s;
