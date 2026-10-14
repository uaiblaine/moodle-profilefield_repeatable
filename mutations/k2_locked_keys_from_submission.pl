# The locked keys are read from the submitted schema instead of the stored field.
s~\\profilefield_repeatable\\local\\field_schema::from_field\(\$stored\)~\\local_repeatable\\local\\schema::from_json((string)\$element->getValue()) ?? \\profilefield_repeatable\\local\\field_schema::from_field(\$stored)~s;
