# save_checks_apply(): the checks apply on every request, so login and sync refuse a provider's value and lock the user out.
s~return \$request && isloggedin\(\) && !isguestuser\(\);~return true;~;
