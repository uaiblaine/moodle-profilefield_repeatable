# The accordion's aria-label field name is escaped by format_string() and again by the template.
s~(\$this->fieldname,\n\s+true,\n\s+\['context' => \\core\\context\\system::instance\(\)), 'escape' => false\]~$1]~s;
