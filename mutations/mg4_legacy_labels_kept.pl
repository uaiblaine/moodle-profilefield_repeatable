# finish_field() leaves the legacy labels behind.
s~(\$rewritten = self::rekey_payloads\(\$fieldid, \$schema\);\n\s+projection::reproject_field\(\$field\);\n)(\s+)unset_config\(schema::legacy_config_name\(\$fieldid\), 'profilefield_repeatable'\);~$1~s;
