# A set may leave a required column empty.
s~if \(trim\(\(string\)\(\$set\[\$key\] \?\? ''\)\) === ''\) \{~if (false) {~s;
