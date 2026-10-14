# profilefield_repeatable_drop_index_layer(): the PostgreSQL-only guard is gone, so MariaDB runs the pg_indexes DDL path.
s~\n\s+if \(\$db->get_dbfamily\(\) !== 'postgres'\) \{\n\s+return;\n\s+\}\n~\n~s;
