# The upgrade skips every field already on version 2, half-converted ones included.
s~if \(get_config\('profilefield_repeatable', schema::legacy_config_name\(\(int\)\$field->id\)\) === false\) \{~if (true) {~s;
