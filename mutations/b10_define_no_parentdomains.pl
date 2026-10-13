# define_validate_specific(): the companion's parent domains are never passed to the schema check.
s~(\$parentdomains\[\$domain\['shortname'\]\] = )\$domain\['parentdomain'\];~$1null;~s;
