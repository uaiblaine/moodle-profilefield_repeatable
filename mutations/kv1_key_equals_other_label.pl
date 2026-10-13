# from_v1(): a key may equal another column's label.
s~\$key = self::generate_key\(\$label, array_merge\(\$taken, \$otherlabels\)\);~\$key = self::generate_key(\$label, \$taken);~s;
