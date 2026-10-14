# The scheduled task rebuilds nothing.
s~\$count = projector::reproject_field\(new source\(\), \(int\)\$field->id, field_schema::from_field\(\$field\)\);~\$count = 0;~s;
