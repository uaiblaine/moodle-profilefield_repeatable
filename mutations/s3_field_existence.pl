# The source is offered even when no repeatable field exists.
s~return \$DB->record_exists\('user_info_field', \['datatype' => 'repeatable'\]\);~return true;~s;
