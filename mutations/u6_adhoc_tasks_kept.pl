# profilefield_repeatable_drop_index_layer(): the queued tasks of the removed class are kept.
s~\s+\$db->delete_records\('task_adhoc', \['classname' => '[^']+reconcile_indexes'\]\);\n~\n~s;
