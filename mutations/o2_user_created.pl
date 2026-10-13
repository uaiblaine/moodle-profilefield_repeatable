# The user_created observer no longer reprojects.
s~(function user_created\(\\core\\event\\user_created \$event\): void \{\n\s+)local\\projection::reproject_user\(\(int\)\$event->objectid\);~$1;~s;
