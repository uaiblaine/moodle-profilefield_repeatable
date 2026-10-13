# filter_field_indexes() keeps any index the name pattern recognises, whichever field owns it.
s~self::owned_index_fieldid\(\$indexname\) === \$fieldid~self::owned_index_fieldid(\$indexname) !== null~s;
