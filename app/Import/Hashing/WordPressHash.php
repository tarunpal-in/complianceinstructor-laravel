<?php

declare(strict_types=1);

namespace App\Import\Hashing;

/**
 * Verifies a password against any hash WordPress might have stored.
 *
 * Two schemes appear in the dump: the classic `$P$` portable hash (phpass,
 * ~30k accounts) and WordPress 6.8's `$wp$` scheme (~4k), which HMAC-SHA384s the
 * password before bcrypt so it is not cut off at bcrypt's 72-byte limit. Both
 * are verified here so an imported user signs in with their existing password;
 * the LegacyAwareHasher then rehashes the result to native bcrypt.
 *
 * NOTE: the `$wp$` scheme is reproduced from WordPress core's `wp_hash_password`
 * (HMAC-SHA384 with the key 'wp-sha384', base64, then bcrypt). It should be
 * confirmed against a real WP 6.8 sample before go-live, since the plaintext for
 * the dump's hashes is not known here.
 */
final class WordPressHash
{
    private const WP_PREFIX = '$wp$';

    private const PHPASS_PREFIXES = ['$P$', '$H$'];

    public static function isPhpass(string $hash): bool
    {
        return in_array(substr($hash, 0, 3), self::PHPASS_PREFIXES, true);
    }

    public static function isWp(string $hash): bool
    {
        return str_starts_with($hash, self::WP_PREFIX);
    }

    /** Whether this hash is a WordPress scheme we verify but then upgrade. */
    public static function isLegacy(string $hash): bool
    {
        return self::isPhpass($hash) || self::isWp($hash);
    }

    public static function check(string $password, string $hash): bool
    {
        if (self::isPhpass($hash)) {
            return Phpass::check($password, $hash);
        }

        if (self::isWp($hash)) {
            return password_verify(self::wpPreHash($password), substr($hash, strlen(self::WP_PREFIX)));
        }

        return false;
    }

    /** The `$wp$` scheme, for round-trip testing; production only verifies. */
    public static function hashWp(string $password): string
    {
        return self::WP_PREFIX.password_hash(self::wpPreHash($password), PASSWORD_BCRYPT);
    }

    private static function wpPreHash(string $password): string
    {
        return base64_encode(hash_hmac('sha384', trim($password), 'wp-sha384', true));
    }
}
