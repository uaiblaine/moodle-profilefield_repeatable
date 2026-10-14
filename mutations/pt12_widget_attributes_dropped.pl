# pattern_widget_properties(): pattern inputs get no attributes, hence no mask.
s~return \['inputattributes' => \\local_repeatable\\local\\bridge\\pattern::input_attributes\(\$config\)\];~return [];~s;
