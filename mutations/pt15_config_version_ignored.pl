# available(): the bridge expects another configuration version, so the installed plugin reads as absent.
s~constant\(self::API \. '::CONFIG_VERSION'\) === self::CONFIG_VERSION~constant(self::API . '::CONFIG_VERSION') === self::CONFIG_VERSION + 1~s;
