# The owned-name pattern loses its start anchor: a name that merely contains the pattern counts as owned.
s~'/\^pfrd_f\(\\d\+\)_~'/pfrd_f(\\d+)_~s;
