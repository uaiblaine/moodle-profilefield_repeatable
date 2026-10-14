# edit_save_data(): the save path no longer runs the form's checks, so a web service stores what the form would refuse.
s~\n\s+\$submitted = \[\];\n\s+if \(\$this->submitted_error\(.*?\n\s+\}\n(?=\n\s+\$data = new stdClass)~~s;
