# reproject_record(): the in-transaction staleness re-check is gone, so a current record is rewritten.
s~if \(self::is_current\(\$payload, \$records\)\) \{\n(\s+\$transaction->allow_commit\(\);\n\s+return false;)~if (false) {\n$1~s;
