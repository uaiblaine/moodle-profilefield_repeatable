# column_code_error(): an any-code column is checked like a codes-only one.
s~\$strict = \$column\['mode'\] === \\profilefield_repeatable\\local\\schema::MODE_DICTIONARY;~\$strict = true;~s;
