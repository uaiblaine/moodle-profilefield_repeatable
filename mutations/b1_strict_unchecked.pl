# validate_dictionary_codes(): a codes-only column no longer checks its codes.
s~(\$column\['mode'\] === \\profilefield_repeatable\\local\\schema::MODE_DICTIONARY\n)~false && $1~s;
