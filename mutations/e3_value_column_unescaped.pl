# The plain sub-item value column returns the stored text raw. Anchored on the 'val' field followed by
# a callback with no `use ($domain)`, which the resolved-name column has.
s~(->add_field\(\$valuesql, 'val' \. \$index, \$valueparams\)\n\s+->set_is_sortable\(true\)\n\s+->add_callback\(static function \(\?string \$value\): string \{\n\s+return )s\(\$value\)(;)~$1\$value$2~s;
