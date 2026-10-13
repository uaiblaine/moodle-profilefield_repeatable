# define_validate_specific(): pattern columns are checked as if customfield_pattern were always there.
s~if \(\$this->pattern_available\(\)\) \{\n(\s+\$patternerrors = )~if (true) {\n$1~s;
