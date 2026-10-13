# validate_dictionary_codes(): an any-code column is checked like a codes-only one.
s~\$column\['mode'\] === \\profilefield_repeatable\\local\\schema::MODE_DICTIONARY\n~\\profilefield_repeatable\\local\\schema::is_dictionary_mode(\$column['mode'])\n~s;
