# validate(): a document of another version, or with a non-list of columns, is accepted.
s~ \|\| \(\$document\['version'\] \?\? null\) !== self::VERSION \|\| !array_is_list\(\$rawcolumns\)~~s;
