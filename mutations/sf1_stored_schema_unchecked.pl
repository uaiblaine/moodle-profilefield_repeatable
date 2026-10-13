# from_json(): a stored schema reaches the readers unchecked.
s~return \(new self\(is_array\(\$columns\) \? array_values\(\$columns\) : \[\]\)\)->without_unsafe_columns\(\);~return new self(is_array(\$columns) ? array_values(\$columns) : []);~s;
