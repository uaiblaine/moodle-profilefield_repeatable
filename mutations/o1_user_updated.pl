# The user_updated observer no longer reprojects.
s~(function user_updated\(\\core\\event\\user_updated \$event\): void \{\n\s+)local\\projection::reproject_user\(\(int\)\$event->objectid\);~$1;~s;
