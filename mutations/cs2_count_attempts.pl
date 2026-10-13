# rekey_payloads(): a write that did not happen is counted.
s~if \(self::write_if_unchanged\(\$lastid, \(string\)\$row->data, \$json\)\) \{~if (self::write_if_unchanged(\$lastid, (string)\$row->data, \$json) \|\| true) {~s;
