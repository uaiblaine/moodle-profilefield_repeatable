# The locked keys are read from the submitted schema instead of the stored field.
s~\\profilefield_repeatable\\local\\schema::from_field\(\$stored\)~\\profilefield_repeatable\\local\\schema::from_json((string)\$element->getValue()) ?? \\profilefield_repeatable\\local\\schema::from_field(\$stored)~s;
