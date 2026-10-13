# The definition form tells the editor that no key holds values.
s~'data-lockedkeys' => json_encode\(\$usage\['keys'\]\),~'data-lockedkeys' => json_encode([]),~s;
