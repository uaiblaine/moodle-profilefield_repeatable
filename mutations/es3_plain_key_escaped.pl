# The plain list's column label is escaped by format_string() and again by the template.
s~(format_string\(\$schema->label\(\$key\), true, \['context' => \$context), 'escape' => false\]~$1]~s;
