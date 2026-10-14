# is_empty() asks the projection table instead of the loaded payload.
s~return empty\(\\local_repeatable\\local\\payload::normalise\(\$this->data, \$this->get_schema\(\)\)\);~return empty(\$GLOBALS['DB']->get_records('profilefield_repeatable_data', ['dataid' => \$this->dataid]));~s;
