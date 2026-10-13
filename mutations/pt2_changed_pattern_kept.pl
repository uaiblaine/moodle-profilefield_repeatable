# validate_pattern(): without customfield_pattern a stored pattern column may change its settings.
s~if \(!array_key_exists\(\$key, \$storedpatterns\) \|\| \$storedpatterns\[\$key\] != \$object\) \{~if (!array_key_exists(\$key, \$storedpatterns)) {~s;
