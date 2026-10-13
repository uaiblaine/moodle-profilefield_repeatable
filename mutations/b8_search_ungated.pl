# can_read_domain(): every domain counts as readable, so restricted dictionaries are searched, labelled and checked.
s~return \$this->companion_available\(\) && \\local_dictionaries\\api::can_read\(\$domain\);~return \$this->companion_available();~s;
