# define_validate_specific(): the stored field's pattern columns are not read.
s~\$storedpatterns = \\profilefield_repeatable\\local\\field_schema::from_field\(\$stored\)->pattern_objects\(\);~\$storedpatterns = [];~s;
