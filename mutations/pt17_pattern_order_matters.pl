# validate_pattern(): the settings are compared without sorting their keys, so a reordered object reads as changed.
s~\|\| self::sorted_by_key\(\$storedpatterns\[\$key\]\) !== self::sorted_by_key\(\$object\)~|| \$storedpatterns[\$key] !== \$object~s;
