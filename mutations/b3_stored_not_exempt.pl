# column_code_error(): in a readable domain, the code the owner already has stored is refused like any other.
s~\(\$item === null \|\| !\$item\['active'\]\) && !\$isstored\)~(\$item === null || !\$item['active']))~s;
