# validate_pattern(): without customfield_pattern any pattern column is accepted.
s~if \(\n\s+!array_key_exists\(\$key, \$storedpatterns\)\n\s+\|\| self::sorted_by_key\(\$storedpatterns\[\$key\]\) !== self::sorted_by_key\(\$object\)\n\s+\) \{~if (false) {~s;
