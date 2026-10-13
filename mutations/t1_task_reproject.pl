# The scheduled task rebuilds nothing.
s~\$count = projection::reproject_field\(\$field\);~\$count = 0;~s;
