# The edit form's sub-item heading is escaped by format_string() and again by the template.
s~(format_string\(\$subitem, true, \['context' => \$context), 'escape' => false\]~$1]~s;
