# The resolved-name column returns the label raw.
s~return s\(\$domain === '' \? \(string\)\$value : self::resolve_label\(\$domain, \$value\)\);~return \$domain === '' ? (string)\$value : self::resolve_label(\$domain, \$value);~s;
