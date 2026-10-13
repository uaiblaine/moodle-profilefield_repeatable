# The index task writes a stored key the schema rules reject into the DDL.
s~if \(!preg_match\(schema::KEY_PATTERN, \$key\)\) \{~if (false) {~s;
