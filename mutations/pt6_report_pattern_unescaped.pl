# The pattern value column returns the formatted text raw.
s~return s\(pattern::format\(\$patternconfig, \(string\)\$value\)\);~return pattern::format(\$patternconfig, (string)\$value);~s;
