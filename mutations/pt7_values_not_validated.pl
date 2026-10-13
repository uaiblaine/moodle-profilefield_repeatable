# edit_validate_field(): pattern values are not checked.
s~\$patternerror = \$this->validate_pattern_values\(\$payload\);~\$patternerror = null;~s;
