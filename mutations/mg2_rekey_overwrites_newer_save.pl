# write_if_unchanged() writes whatever is stored now (the condition always holds).
s~'id = :id AND ' \. \$DB->sql_equal\('data', ':olddata', true, true\)~'id = :id AND ' . \$DB->sql_length(':olddata') . ' >= 0'~s;
