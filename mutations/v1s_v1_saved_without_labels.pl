# define_save(): saving a version 1 field writes no legacy labels and queues no re-keying.
s~if \(\$convert\) \{\n(\s+\$legacy = )~if (false) {\n$1~s;
