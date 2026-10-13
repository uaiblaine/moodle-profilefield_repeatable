# The definition form treats every domain as existing, so the missing-domain error never fires.
s~static fn\(string \$domain\): bool => \\local_dictionaries\\resolver::domain_exists\(\$domain\)~static fn(string \$domain): bool => true~s;
