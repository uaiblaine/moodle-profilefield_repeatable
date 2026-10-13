# sql_column_value() reads only keys, so Report Builder misses label-keyed rows.
s~if \(\$legacy === null\) \{\n(\s+return \[\$sql, \$params\];)~if (true) {\n$1~s;
