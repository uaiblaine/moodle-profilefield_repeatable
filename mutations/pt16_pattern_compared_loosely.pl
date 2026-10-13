# validate_pattern(): the stored and submitted settings are compared loosely ("1000" == "1e3").
s~\|\| self::sorted_by_key\(\$storedpatterns\[\$key\]\) !== self::sorted_by_key\(\$object\)~|| self::sorted_by_key(\$storedpatterns[\$key]) != self::sorted_by_key(\$object)~s;
