# validate_dictionary_codes(): the code the user already has stored is refused like any other.
s~\n\s+&& !isset\(\$stored\[\$key\]\[\\profilefield_repeatable\\helper::fold_code\(\$code\)\]\)~~s;
