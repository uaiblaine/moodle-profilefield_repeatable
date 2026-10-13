# column_value(): a JSON null under the key hides the value under the legacy label.
s~if \(isset\(\$set\[\$key\]\)\) \{~if (array_key_exists(\$key, \$set)) {~s;
