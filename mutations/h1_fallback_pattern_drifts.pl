# The fallback pattern also accepts '-', so it no longer equals the companion's.
s~public const DOMAIN_PATTERN = '/\^\[a-z0-9_\]\+\$/';~public const DOMAIN_PATTERN = '/^[a-z0-9_-]+\$/';~s;
