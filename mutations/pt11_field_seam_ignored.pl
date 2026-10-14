# validate_pattern_values(): the validator checks pattern values even when the field says the plugin is absent.
s~if \(empty\(\$payload\) \|\| !\$this->patternavailable\) \{\n(\s+return null;\n\s+\}\n\s+\$schema = \$this->schema;\n\n\s+\$setnumber = 0;)~if (empty(\$payload)) {\n$1~s;
