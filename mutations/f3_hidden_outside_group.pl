# edit_field_add(): the hidden input is added bare again, not inside the group that carries the error.
s~\$mform->addGroup\(\s*\[\$mform->createElement\('hidden', \$this->inputname, '', \['id' => \$this->get_hidden_id\(\)\]\)\],\s*\$this->inputname,\s*'',\s*'',\s*false\s*\);~\$mform->addElement('hidden', \$this->inputname, '', ['id' => \$this->get_hidden_id()]);~s;
