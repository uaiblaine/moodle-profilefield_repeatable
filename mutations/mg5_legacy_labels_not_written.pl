# The upgrade converts a field without remembering its labels.
s~if \(!empty\(\$schema->legacy_labels\(\)\)\) \{~if (false) {~s;
