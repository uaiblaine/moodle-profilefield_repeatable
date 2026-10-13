# write_if_unchanged(): the comparison follows the column collation (case and accent insensitive on MariaDB).
s~'id = :id AND ' \. \$DB->sql_equal\('data', ':olddata', true, true\)~'id = :id AND ' . \$DB->sql_compare_text('data') . ' = ' . \$DB->sql_compare_text(':olddata')~s;
