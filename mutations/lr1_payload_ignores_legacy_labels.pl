# normalise_payload() reads only keys, so a version 1 payload looks empty.
s~\} else if \(isset\(\$legacy\[\$key\]\) && array_key_exists\(\$legacy\[\$key\], \$setdata\)\) \{~} else if (false) {~s;
