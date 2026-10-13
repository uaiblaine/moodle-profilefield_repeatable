# The name filter options use the raw label (core prints option labels unescaped).
s~s\(\(is_string\(\$label\) && trim\(\$label\) !== ''\) \? \$label : \$code\)~((is_string(\$label) && trim(\$label) !== '') ? \$label : \$code)~s;
