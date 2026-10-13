# column_value() reads only keys, so a version 1 payload looks empty.
s~if \(\$legacylabel !== null && isset\(\$set\[\$legacylabel\]\)\) \{~if (false) {~s;
