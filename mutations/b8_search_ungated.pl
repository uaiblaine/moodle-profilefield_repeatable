# dictionary_widget_properties(): a column is searched even when the user may not read its domain.
s~\n\s+&& \\local_dictionaries\\api::can_read\(\$domain\),~,~s;
