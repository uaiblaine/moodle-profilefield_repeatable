# column_code_error(): in an unreadable domain, an any-code column is refused like a codes-only one.
s~if \(\$strict && !\$isstored\) \{~if (!\$isstored) {~s;
