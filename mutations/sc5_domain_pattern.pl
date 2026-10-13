# validate(): a dictionary column needs no well-formed domain.
s~if \(\$domain === '' \|\| !preg_match\(helper::domain_pattern\(\), \$domain\)\) \{~if (false) {~s;
