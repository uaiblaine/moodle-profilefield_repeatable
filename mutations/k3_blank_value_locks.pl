# keys_in_use(): a value of spaces locks the key.
s~if \(trim\(\$value\) !== ''\) \{\n(\s+\$inuse\[\$key\] = true;)~if (true) {\n$1~s;
