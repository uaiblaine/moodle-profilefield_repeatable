# reproject_field(): rows of a vanished user_info_data record are no longer skipped, so the row cursor
# stays on them and every later record sees no rows (it then counts as stale).
s~while \(\$rowrs->valid\(\) && \(int\)\$rowrs->current\(\)->dataid < \$dataid\) \{~while (false) {~s;
