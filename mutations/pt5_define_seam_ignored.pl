# column_editor::errors(): pattern columns are checked as if customfield_pattern were always there.
s~if \(\$patternavailable\) \{\n(\s+\$patternerrors = )~if (true) {\n$1~s;
