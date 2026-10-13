# validate_dictionary_codes(): a dependent column accepts an item of any parent code.
s~(\$parentcode !== ''\n\s+)&& \\profilefield_repeatable\\helper::fold_code~$1&& false && \\profilefield_repeatable\\helper::fold_code~s;
