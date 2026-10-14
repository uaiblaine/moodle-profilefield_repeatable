# edit_validate_field(): the uniqueness check gets the typed shape, not the stored one.
s~(// JSON as it would be stored, or '' so that an empty optional value is not checked\.\n)\s+\$payload = \$validator->for_storage\([^\n]+\n~$1~s;
