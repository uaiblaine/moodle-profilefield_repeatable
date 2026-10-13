# define_validate_specific() accepts an indexed sub-item holding ":", "?" or "$".
s~if \(!empty\(\$unindexable\)\)~if (false \&\& !empty(\$unindexable))~s;
