# profilefield_repeatable_is_index_layer_index(): the GIN index is no longer recognised, so it stays.
s~'/\^pfrd_\[0-9a-f\]\{8\}_gin_ix\$/'~'/^(?!)pfrd_[0-9a-f]{8}_gin_ix\$/'~s;
