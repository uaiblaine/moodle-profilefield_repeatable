# column_code_error(): a deactivated code counts as offered.
s~\(\$item === null \|\| !\$item\['active'\]\) && !\$isstored~(\$item === null) && !\$isstored~s;
