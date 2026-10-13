# The upgrade converts a field without remembering its labels or marking it in progress.
s~    set_config\(\n        schema::legacy_config_name\(\(int\)\$field->id\),\n.*?\n    \);\n~~s;
