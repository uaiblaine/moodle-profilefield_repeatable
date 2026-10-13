# generate_key(): the first candidate is taken even when in use.
s~\$free = \$free && !isset\(\$taken\[\$identifier\]\);~\$free = true;~s;
