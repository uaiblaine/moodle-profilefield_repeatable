# The index task no longer skips a sub-item the DML placeholder scan would misread.
s~if \(!helper::is_indexable_subitem\(\$subitem\)\) \{~if (false) {~s;
