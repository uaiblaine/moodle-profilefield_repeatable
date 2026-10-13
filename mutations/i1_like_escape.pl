# The index-name LIKE pattern is not escaped: '_' becomes a wildcard and 'pfrd_f1_' matches 'pfrd_f11_...'.
s~\$DB->sql_like_escape\('pfrd_f' \. \$fieldid \. '_', '\\\\'\)~'pfrd_f' . \$fieldid . '_'~s;
