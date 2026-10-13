# validate_pattern_values(): the field validates even when told the plugin is absent.
s~if \(empty\(\$payload\) \|\| !\$this->pattern_available\(\)\) \{\n(\s+return null;\n\s+\}\n\s+\$schema = \$this->get_schema\(\);\n\n\s+\$setnumber = 0;)~if (empty(\$payload)) {\n$1~s;
