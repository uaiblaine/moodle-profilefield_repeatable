# A value key is renamed by prefix, so subitem1 also claims subitem10's values.
s~strpos\(\(string\)\$name, \$old \. '_'\) === 0~strpos((string)\$name, \$old) === 0~s;
