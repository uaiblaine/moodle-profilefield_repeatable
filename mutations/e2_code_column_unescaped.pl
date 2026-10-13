# The code column returns the stored code raw. Anchored on the 'code' field, which only the code
# column adds; the first `return s($value)` after it is the code column's own callback.
s~(->add_field\(\$codesql, 'code' \. \$index, \$codeparams\).*?return )s\(\$value\)(;)~$1\$value$2~s;
