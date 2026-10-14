# finish_field() leaves the legacy labels behind.
s~(\$rewritten = self::rekey_payloads\(\$fieldid, \$schema\);\n\s+projector::reproject_field\(new source\(\), \$fieldid, \$schema\);\n)(\s+)unset_config\(field_schema::legacy_config_name\(\$fieldid\), 'profilefield_repeatable'\);~$1~s;
