# profilefield_repeatable_drop_index_layer(): the data column stays jsonb.
s~if \(\$columntype === 'jsonb'\) \{~if (false) {~s;
