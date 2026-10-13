# The user_updated and user_created observers are internal again: core runs them at once, before
# an open transaction commits and before auth_ldap writes the payload.
s~\n\s+'internal' => false,~~gs;
