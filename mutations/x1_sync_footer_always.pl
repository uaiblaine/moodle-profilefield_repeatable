# The accordion renders its sync footer even without a stored timestamp.
s~'hassync' => !empty\(\$timemodified\),~'hassync' => true,~s;
