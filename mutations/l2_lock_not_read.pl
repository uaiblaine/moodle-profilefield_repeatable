# define_validate_specific(): no stored field is read, so nothing is locked.
s~\$stored = \$this->stored_field\(\(int\)\(\$data->id \?\? 0\)\);~\$stored = null;~s;
