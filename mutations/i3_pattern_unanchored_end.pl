# The owned-name pattern loses its end anchor: a longer hash tail, or a suffix, still counts as owned.
s~\[0-9a-f\]\{16\}\$/'~[0-9a-f]{16}/'~s;
