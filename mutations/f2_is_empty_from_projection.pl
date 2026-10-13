# is_empty() asks the projection table instead of the loaded payload.
s~return empty\(\\profilefield_repeatable\\helper::normalise_payload\(\$this->data, \$this->get_subitems\(\)\)\);~return empty(\$GLOBALS['DB']->get_records('profilefield_repeatable_data', ['dataid' => \$this->dataid]));~s;
