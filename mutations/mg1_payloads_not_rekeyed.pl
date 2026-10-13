# finish_field() re-keys no payload.
s~\$rewritten = self::rekey_payloads\(\$fieldid, \$schema\);~\$rewritten = 0;~s;
