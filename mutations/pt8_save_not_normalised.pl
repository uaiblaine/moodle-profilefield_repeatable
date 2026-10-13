# edit_save_data_preprocess(): pattern values are stored as typed.
s~(public function edit_save_data_preprocess\(\$data, \$datarecord\) \{\n\s+\$payload = [^\n]+\n)\s+\$payload = \$this->map_pattern_values\([^\n]+\n~$1~s;
