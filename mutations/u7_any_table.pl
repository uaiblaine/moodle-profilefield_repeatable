# profilefield_repeatable_drop_index_layer(): indexes of every table are candidates, not only the projection's.
s~AND tablename = :tablename",~AND (tablename = :tablename OR TRUE)",~s;
