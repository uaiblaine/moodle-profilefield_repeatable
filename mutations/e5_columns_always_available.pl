# Columns ignore the field's visibility.
s~\$this->add_column\(\$column->set_is_available\(\$available\)\);~\$this->add_column(\$column);~s;
