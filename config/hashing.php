<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    |
    | Defaults to the migration-aware driver, which is a strict superset of
    | bcrypt: it hashes new passwords with bcrypt exactly as before, and it can
    | additionally *verify* the WordPress hashes imported from the old site
    | ($P$ phpass and $wp$ WP 6.8), silently rehashing them to bcrypt on the
    | user's next login. A fresh install with no imported users behaves exactly
    | like plain bcrypt. Set HASH_DRIVER=bcrypt to opt out entirely.
    |
    | Supported: "legacy", "bcrypt", "argon", "argon2id"
    |
    */

    'driver' => env('HASH_DRIVER', 'legacy'),

    /*
    |--------------------------------------------------------------------------
    | Bcrypt Options
    |--------------------------------------------------------------------------
    */

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => env('HASH_VERIFY', true),
        'limit' => env('BCRYPT_LIMIT', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Argon Options
    |--------------------------------------------------------------------------
    */

    'argon' => [
        'memory' => env('ARGON_MEMORY', 65536),
        'threads' => env('ARGON_THREADS', 1),
        'time' => env('ARGON_TIME', 4),
        'verify' => env('HASH_VERIFY', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rehash On Login
    |--------------------------------------------------------------------------
    |
    | Keep this on: it is what upgrades an imported WordPress hash to bcrypt the
    | first time the user signs in, so no one is ever asked to reset.
    |
    */

    'rehash_on_login' => true,

];
