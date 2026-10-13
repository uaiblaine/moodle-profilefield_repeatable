# reproject_field(): no record is ever considered stale.
s~if \(!self::is_current\(\$payload, \$records\)\) \{\n(\s+\$stale\[\] = \[\$dataid, \$schema\];)~if (false) {\n$1~s;
