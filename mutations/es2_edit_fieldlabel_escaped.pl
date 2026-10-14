# The edit form's field label is escaped by format_string() and again by the template.
s~(format_string\(\$fieldname, true, \['context' => \$context), 'escape' => false\]~$1]~s;
