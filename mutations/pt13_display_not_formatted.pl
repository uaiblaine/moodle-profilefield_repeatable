# resolve_reference_display_value(): pattern values are shown as stored.
s~return \\local_repeatable\\local\\bridge\\pattern::format\(\$patternconfig, \$rawvalue\);~return \$rawvalue;~s;
