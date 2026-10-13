# column_code_error(): the parent codes are compared exactly, not folded.
s~\\profilefield_repeatable\\helper::fold_code\(\$item\['parentcode'\]\)\n(\s+)=== \\profilefield_repeatable\\helper::fold_code\(\$parentcode\)~\$item['parentcode']\n$1=== \$parentcode~s;
