# export_user_data(): ids are compared without the integer cast (a string instanceid never equals an int id).
s~\(int\)\$context->instanceid !== \(int\)\$user->id~\$context->instanceid !== \$user->id~s;
