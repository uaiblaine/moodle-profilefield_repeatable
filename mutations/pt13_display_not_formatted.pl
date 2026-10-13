# resolve_reference_display_value(): pattern values are shown as stored.
s~return \\profilefield_repeatable\\local\\pattern_columns::format\(\$patternconfig, \$rawvalue\);~return \$rawvalue;~s;
