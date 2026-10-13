# pattern_widget_properties(): pattern inputs get no attributes, hence no mask.
s~return \['inputattributes' => \\profilefield_repeatable\\local\\pattern_columns::input_attributes\(\$config\)\];~return [];~s;
