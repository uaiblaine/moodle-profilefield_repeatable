# edit_validate_field() never reports the required-column error.
s~if \(\$requirederror !== null\) \{~if (false) {~s;
