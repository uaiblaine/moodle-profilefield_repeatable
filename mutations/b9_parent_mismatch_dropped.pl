# schema::validate_columns(): a parent column on the wrong dictionary passes.
s~(isset\(\$column\['parent'\], \$parentdomains\[\$domain\]\)\n\s+)&& ~$1&& false && ~s;
