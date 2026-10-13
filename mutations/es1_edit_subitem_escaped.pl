# The edit form's column heading is escaped by format_string() and again by the template.
s~(format_string\(\$schema->label\(\$column\['key'\]\), true, \['context' => \$context), 'escape' => false\]~$1]~s;
