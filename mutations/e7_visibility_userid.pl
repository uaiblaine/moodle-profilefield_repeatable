# is_field_visible(): the profile field object is built for the CURRENT user instead of the support user,
# so a PRIVATE field reads as the viewer's own. Anchored on the call and its two surrounding arguments;
# the userid argument itself ([^,]+) is whatever the file holds, so this survives a change of that line.
s~(profile_get_user_field\('repeatable', \(int\)\$this->field->id, )[^,]+(, \$this->field\))~$1(int)\$GLOBALS['USER']->id$2~s;
