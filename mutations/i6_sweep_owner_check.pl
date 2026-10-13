# The orphan sweep also takes indexes whose name the task never builds (owner null) for orphans.
s~if \(\$owner !== null\) \{~if (true) {~s;
