# delete_data_for_user(): ids are compared without the integer cast.
s~\(int\)\$context->instanceid === \(int\)\$user->id~\$context->instanceid === \$user->id~s;
