<?php

declare(strict_types=1);

namespace App\Import\Hashing;

use Illuminate\Hashing\BcryptHasher;
use SensitiveParameter;

/**
 * Bcrypt, plus the ability to verify imported WordPress hashes.
 *
 * A strict superset of the framework's BcryptHasher: `make()` still produces a
 * bcrypt hash, and a native bcrypt password verifies exactly as before. The
 * only additions are that `check()` also accepts the `$P$` and `$wp$` hashes
 * carried over from WordPress, and `needsRehash()` reports true for them — so
 * the framework's rehash-on-login upgrades a legacy hash to bcrypt the first
 * time that user signs in. No user is ever asked to reset.
 */
class LegacyAwareHasher extends BcryptHasher
{
    /**
     * Report an imported WordPress hash as already hashed.
     *
     * This is what stops the `hashed` cast from re-hashing it on import: the
     * cast only hashes a value `Hash::isHashed()` says is raw, and isHashed
     * reads `info()['algo']`. A WordPress hash is not a PHP `password_hash`
     * format, so the default info would call it raw and the cast would bcrypt
     * the hash itself — losing the original and locking every migrated user out.
     *
     * @param  string|null  $hashedValue
     * @return array<string, mixed>
     */
    public function info($hashedValue): array
    {
        if (is_string($hashedValue) && WordPressHash::isLegacy($hashedValue)) {
            return ['algo' => 'wordpress', 'algoName' => 'wordpress', 'options' => []];
        }

        return parent::info($hashedValue);
    }

    /**
     * A WordPress hash is a valid stored value, even though it is not bcrypt.
     *
     * The `hashed` cast calls this after isHashed to reject a hash made with the
     * wrong algorithm; left to the bcrypt check it would throw on every imported
     * WordPress hash. The hash is legitimate — it just upgrades on next login.
     *
     * @param  mixed  $value
     */
    public function verifyConfiguration($value): bool
    {
        if (is_string($value) && WordPressHash::isLegacy($value)) {
            return true;
        }

        return parent::verifyConfiguration($value);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function check(#[SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        if ($hashedValue === '') {
            return false;
        }

        if (WordPressHash::isLegacy($hashedValue)) {
            return WordPressHash::check((string) $value, $hashedValue);
        }

        return parent::check($value, $hashedValue, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function needsRehash($hashedValue, array $options = []): bool
    {
        if (WordPressHash::isLegacy($hashedValue)) {
            return true;
        }

        return parent::needsRehash($hashedValue, $options);
    }
}
