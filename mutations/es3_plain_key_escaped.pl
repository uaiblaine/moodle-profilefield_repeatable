# The plain list's sub-item key is escaped by format_string() and again by the template.
s~(format_string\(\$subitem, true, \['context' => \$context), 'escape' => false\]~$1]~s;
