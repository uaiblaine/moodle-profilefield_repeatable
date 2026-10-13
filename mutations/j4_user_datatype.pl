# reproject_user(): the field-datatype filter inverts, so a user's repeatable fields are never examined.
s~AND f\.datatype = :datatype~AND f.datatype <> :datatype~s;
