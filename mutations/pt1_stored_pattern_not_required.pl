# validate_pattern(): without customfield_pattern any pattern column is accepted.
s~if \(!array_key_exists\(\$key, \$storedpatterns\) \|\| \$storedpatterns\[\$key\] != \$object\) \{~if (false) {~s;
