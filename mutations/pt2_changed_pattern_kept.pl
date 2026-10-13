# validate_pattern(): without customfield_pattern a stored pattern column may change its settings.
s~\n\s+\|\| self::sorted_by_key\(\$storedpatterns\[\$key\]\) !== self::sorted_by_key\(\$object\)~~s;
