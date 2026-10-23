<?php

declare(strict_types=1);

namespace App\Import\Hashing;

/**
 * WordPress's "portable" password hash — the `$P$` scheme (phpass).
 *
 * This is the algorithm WordPress used for most of the imported accounts, so to
 * let those users sign in with their existing password unchanged we have to be
 * able to verify it. The implementation is phpass's `crypt_private`
 * (salted, stretched MD5) with phpass's own base64 alphabet — reproduced here
 * exactly, since a one-bit difference means every legacy login fails.
 *
 * `hash()` exists only so the algorithm can be round-tripped in a test; in
 * production we only ever verify, then rehash the result to bcrypt.
 */
final class Phpass
{
    private const ITOA64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public static function check(string $password, string $stored): bool
    {
        $computed = self::cryptPrivate($password, $stored);

        return strlen($computed) === 34 && hash_equals($computed, $stored);
    }

    public static function hash(string $password, int $iterationCountLog2 = 8): string
    {
        $salt = self::encode64(random_bytes(6), 6);
        $setting = '$P$'.self::ITOA64[min($iterationCountLog2 + 5, 30)].$salt;

        return self::cryptPrivate($password, $setting);
    }

    private static function cryptPrivate(string $password, string $setting): string
    {
        $id = substr($setting, 0, 3);

        if ($id !== '$P$' && $id !== '$H$') {
            return '*0';
        }

        $countLog2 = strpos(self::ITOA64, $setting[3]);

        if ($countLog2 === false || $countLog2 < 7 || $countLog2 > 30) {
            return '*0';
        }

        $count = 1 << $countLog2;
        $salt = substr($setting, 4, 8);

        if (strlen($salt) !== 8) {
            return '*0';
        }

        $hash = md5($salt.$password, true);

        do {
            $hash = md5($hash.$password, true);
        } while (--$count);

        return substr($setting, 0, 12).self::encode64($hash, 16);
    }

    private static function encode64(string $input, int $count): string
    {
        $output = '';
        $i = 0;

        do {
            $value = ord($input[$i++]);
            $output .= self::ITOA64[$value & 0x3F];

            if ($i < $count) {
                $value |= ord($input[$i]) << 8;
            }

            $output .= self::ITOA64[($value >> 6) & 0x3F];

            if ($i++ >= $count) {
                break;
            }

            if ($i < $count) {
                $value |= ord($input[$i]) << 16;
            }

            $output .= self::ITOA64[($value >> 12) & 0x3F];

            if ($i++ >= $count) {
                break;
            }

            $output .= self::ITOA64[($value >> 18) & 0x3F];
        } while ($i < $count);

        return $output;
    }
}
