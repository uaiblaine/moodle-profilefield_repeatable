# Filters ignore the field's visibility.
s~\$this->add_filter\(\$filter->set_is_available\(\$available\)\);~\$this->add_filter(\$filter);~s;
