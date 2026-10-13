# reproject_record(): a record deleted since the snapshot is no longer skipped.
s~if \(!\$datarecord\) \{~if (false) {~s;
