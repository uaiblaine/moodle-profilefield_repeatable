# The pattern value column returns the formatted text raw.
s~return s\(pattern_columns::format\(\$patternconfig, \(string\)\$value\)\);~return pattern_columns::format(\$patternconfig, (string)\$value);~s;
