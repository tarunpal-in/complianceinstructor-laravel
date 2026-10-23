<?php

declare(strict_types=1);

use App\Import\Hashing\Phpass;
use App\Import\Hashing\WordPressHash;
use App\Import\Importers\UserImporter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 2 — the user importer. Real customers come across (customer role or
 * billing on file) with their WordPress password intact; subscriber-only bot
 * accounts are left behind.
 */

beforeEach(function (): void {
    config(['database.connections.legacy' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'wp_', 'foreign_key_constraints' => false,
    ]]);
    DB::purge('legacy');

    $schema = Schema::connection('legacy');
    $schema->create('users', function ($t): void {
        $t->integer('ID')->primary();
        $t->string('user_email');
        $t->string('user_pass');
        $t->string('user_login')->nullable();
        $t->string('display_name')->nullable();
        $t->dateTime('user_registered')->nullable();
    });
    $schema->create('usermeta', function ($t): void {
        $t->increments('umeta_id');
        $t->integer('user_id');
        $t->string('meta_key');
        $t->text('meta_value')->nullable();
    });
});

function legacyUser(int $id, string $email, string $pass, string $display, string $caps, array $meta = []): void
{
    DB::connection('legacy')->table('users')->insert([
        'ID' => $id, 'user_email' => $email, 'user_pass' => $pass,
        'display_name' => $display, 'user_registered' => '2022-03-04 10:00:00',
    ]);
    DB::connection('legacy')->table('usermeta')->insert([
        'user_id' => $id, 'meta_key' => 'wp_capabilities', 'meta_value' => $caps,
    ]);
    foreach ($meta as $k => $v) {
        DB::connection('legacy')->table('usermeta')->insert(['user_id' => $id, 'meta_key' => $k, 'meta_value' => $v]);
    }
}

$CUSTOMER = 'a:1:{s:8:"customer";b:1;}';
$SUBSCRIBER = 'a:1:{s:10:"subscriber";b:1;}';

// -----------------------------------------------------------------------------

it('imports real customers and skips bot subscribers', function () use ($CUSTOMER, $SUBSCRIBER): void {
    legacyUser(1, 'alice@example.com', Phpass::hash('alicepw'), 'Alice A', $CUSTOMER, [
        'billing_first_name' => 'Alice', 'billing_last_name' => 'Anders',
    ]);
    legacyUser(2, 'test123@spam.imailfree.cc', Phpass::hash('whatever'), 'test123', $SUBSCRIBER);
    legacyUser(3, 'carol@example.com', WordPressHash::hashWp('carolpw'), 'Carol C', $CUSTOMER, [
        'first_name' => 'Carol', 'last_name' => 'Chen',
    ]);

    (new UserImporter)->run();

    expect(User::query()->count())->toBe(2)
        ->and(User::query()->where('email', 'test123@spam.imailfree.cc')->exists())->toBeFalse();

    $alice = User::query()->where('email', 'alice@example.com')->sole();
    expect($alice->name)->toBe('Alice Anders')
        ->and($alice->email_verified_at)->not->toBeNull();

    $carol = User::query()->where('email', 'carol@example.com')->sole();
    expect($carol->name)->toBe('Carol Chen');
});

it('carries the WordPress password across verbatim, upgradable on login', function () use ($CUSTOMER): void {
    $phpass = Phpass::hash('alicepw');
    legacyUser(1, 'alice@example.com', $phpass, 'Alice', $CUSTOMER, ['billing_first_name' => 'Alice']);

    (new UserImporter)->run();

    $alice = User::query()->where('email', 'alice@example.com')->sole();

    // The hashed cast must NOT have re-hashed the WordPress hash.
    expect($alice->getAttributes()['password'])->toBe($phpass)
        // …and the person signs in with their existing password.
        ->and(Hash::check('alicepw', $alice->password))->toBeTrue()
        ->and(Hash::check('wrong', $alice->password))->toBeFalse()
        ->and(Hash::needsRehash($alice->password))->toBeTrue(); // upgrades on next login
});

it('is a customer — holds no staff role', function () use ($CUSTOMER): void {
    legacyUser(1, 'alice@example.com', Phpass::hash('pw'), 'Alice', $CUSTOMER, ['billing_first_name' => 'Alice']);

    (new UserImporter)->run();

    expect(User::query()->sole()->isCustomer())->toBeTrue();
});

it('is idempotent and maps a shared email to the existing account', function () use ($CUSTOMER): void {
    legacyUser(1, 'alice@example.com', Phpass::hash('pw'), 'Alice', $CUSTOMER, ['billing_first_name' => 'Alice']);
    legacyUser(2, 'alice@example.com', Phpass::hash('other'), 'Alice Two', $CUSTOMER, ['billing_first_name' => 'Alice']);

    (new UserImporter)->run();
    (new UserImporter)->run();

    // One account for the shared email, not duplicated across runs.
    expect(User::query()->count())->toBe(1);
});
