# validate_dictionary_codes(): a deactivated code counts as offered.
s~&& \(\$item === null \|\| !\$item\['active'\]\)~&& (\$item === null)~s;
