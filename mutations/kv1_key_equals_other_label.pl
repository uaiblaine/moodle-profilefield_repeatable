# from_v1(): a key may equal another column's label.
s~\$key = schema::generate_key\(\$label, array_merge\(\$taken, \$otherlabels\)\);~\$key = schema::generate_key(\$label, \$taken);~s;
