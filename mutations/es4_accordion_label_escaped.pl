# The accordion row label is escaped by format_string() and again by the template.
s~('context' => \\core\\context\\system::instance\(\)),\n\s+'escape' => false,~$1,~s;
