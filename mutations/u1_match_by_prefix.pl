# profilefield_repeatable_is_index_layer_index(): any "pfrd_" name counts as the layer's, the bare-prefix ownership bug.
s~return preg_match\('/\^pfrd_f\\d\+_\[0-9a-f\]\{16\}\$/', \$indexname\) === 1\n\s+\|\| preg_match\('/\^pfrd_\[0-9a-f\]\{8\}_gin_ix\$/', \$indexname\) === 1;~return strpos(\$indexname, 'pfrd_') === 0;~s;
