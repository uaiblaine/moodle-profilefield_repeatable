# canonical_set(): a corrupt row (json_decode() gives null) is no longer turned into null.
s~if \(!is_array\(\$set\)\) \{~if (false) {~s;
