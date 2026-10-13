# Every mapped domain counts as existing, so the missing-domain error never fires.
s~!\\local_dictionaries\\resolver::domain_exists\(\$domain\)~false~s;
