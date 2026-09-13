<?php

declare(strict_types=1);

use App\Actions\Certificates\IssueCertificate;
use App\Actions\Certificates\RevokeCertificate;
use App\Enums\UserRole;
use App\Filament\Resources\Certificates\CertificateResource;
use App\Filament\Resources\Certificates\Pages\ListCertificates;
use App\Filament\Resources\Certificates\Pages\ViewCertificate;
use App\Models\Accreditation;
use App\Models\Certificate;
use App\Models\User;
use App\Models\Webinar;
use App\Support\CertificateSerial;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/** A webinar accredited by one body, worth some credits. */
function accredited(string $credits = '2.00', string $code = 'HRCI'): array
{
    $webinar = Webinar::factory()->create(['title' => 'FMLA Compliance 2026']);
    $accreditation = Accreditation::factory()->create(['code' => $code]);

    $webinar->accreditations()->attach($accreditation->id, ['credits' => $credits]);

    return [$webinar, $accreditation];
}

// Authorization ---------------------------------------------------------------

it('opens certificates to roles that may read them', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin/certificates')
        ->assertSuccessful();
})->with([UserRole::SuperAdmin, UserRole::Support]);

/** Credits are a compliance matter, not a content or a finance one. */
it('keeps roles without a certificate permission out', function (UserRole $role): void {
    $this->actingAs(userWithRole($role))
        ->get('/admin/certificates')
        ->assertForbidden();
})->with([UserRole::ContentEditor, UserRole::Finance]);

/**
 * A certificate is what it said on the day it was issued. Correcting one means
 * revoking and reissuing, which leaves both on the record.
 */
it('offers no way to create, edit or delete a certificate', function (): void {
    $certificate = Certificate::factory()->create();

    expect(CertificateResource::canCreate())->toBeFalse()
        ->and(CertificateResource::canEdit($certificate))->toBeFalse()
        ->and(CertificateResource::canDelete($certificate))->toBeFalse();

    $this->actingAs(userWithRole(UserRole::SuperAdmin))
        ->get('/admin/certificates/create')
        ->assertNotFound();
});

// Serials ---------------------------------------------------------------------

/**
 * §8 puts the serial behind a public verification URL, so it has to be
 * unguessable — and somebody has to be able to read it off a printed page.
 */
it('makes a serial that can be typed and cannot be walked', function (): void {
    $serials = collect(range(1, 50))->map(fn (): string => CertificateSerial::generate());

    expect($serials->unique())->toHaveCount(50)
        ->and($serials->every(fn (string $s): bool => CertificateSerial::looksValid($s)))->toBeTrue()
        // No 0/O or 1/I/L, the pairs that get mistyped.
        ->and($serials->every(fn (string $s): bool => ! preg_match('/[01ILO]/', substr($s, 3))))->toBeTrue();
});

it('recognises a serial somebody typed in lower case', function (): void {
    $certificate = Certificate::factory()->create();

    expect(Certificate::query()->matchingSerial(strtolower($certificate->serial))->first()?->id)
        ->toBe($certificate->id)
        ->and(Certificate::query()->matchingSerial(' '.$certificate->serial.' ')->first()?->id)
        ->toBe($certificate->id);
});

it('rejects something that is not one of ours', function (string $serial): void {
    expect(CertificateSerial::looksValid($serial))->toBeFalse();
})->with(['', 'CI-0001', 'ABCD-EFGH-IJKL', 'CI-IIII-OOOO-1111']);

// Issuing ---------------------------------------------------------------------

it('issues a certificate carrying what the document says', function (): void {
    [$webinar, $accreditation] = accredited('2.00');
    $learner = User::factory()->create(['name' => 'Priya Raman']);

    $certificate = app(IssueCertificate::class)($learner, $webinar, $accreditation);

    expect($certificate->learner_name)->toBe('Priya Raman')
        ->and($certificate->webinar_title)->toBe('FMLA Compliance 2026')
        ->and($certificate->accreditation_code)->toBe('HRCI')
        ->and($certificate->credits)->toBe('2.00')
        ->and($certificate->describeCredits())->toBe('2.00 HRCI credits')
        ->and($certificate->isValid())->toBeTrue();
});

/**
 * The whole reason those columns are copied rather than read through relations:
 * an accrediting body auditing this in three years must see what it said today.
 */
it('does not change when the records behind it do', function (): void {
    [$webinar, $accreditation] = accredited('2.00');
    $learner = User::factory()->create(['name' => 'Priya Raman']);

    $certificate = app(IssueCertificate::class)($learner, $webinar, $accreditation);

    $webinar->update(['title' => 'FMLA Compliance 2027 (revised)']);
    $learner->update(['name' => 'Priya Raman-Shah']);
    $webinar->accreditations()->updateExistingPivot($accreditation->id, ['credits' => '3.00']);

    $certificate = $certificate->fresh();

    expect($certificate?->webinar_title)->toBe('FMLA Compliance 2026')
        ->and($certificate?->learner_name)->toBe('Priya Raman')
        ->and($certificate?->credits)->toBe('2.00');
});

/** §12 — credit rules are prescribed by the body and not negotiable. */
it('takes the credits from the webinar, never from a form', function (): void {
    [$webinar, $accreditation] = accredited('1.50');

    $certificate = app(IssueCertificate::class)(User::factory()->create(), $webinar, $accreditation);

    expect($certificate->credits)->toBe('1.50');
});

it('refuses a body the webinar is not accredited by', function (): void {
    [$webinar] = accredited();
    $other = Accreditation::factory()->create(['code' => 'SHRM']);

    expect(fn () => app(IssueCertificate::class)(User::factory()->create(), $webinar, $other))
        ->toThrow(RuntimeException::class, 'is not accredited by SHRM');
});

it('refuses a webinar the body awards no credits for', function (): void {
    [$webinar, $accreditation] = accredited('0.00');

    expect(fn () => app(IssueCertificate::class)(User::factory()->create(), $webinar, $accreditation))
        ->toThrow(RuntimeException::class, 'awards no credits');
});

/** One live certificate per learner, per webinar, per body. */
it('refuses a second certificate while the first still stands', function (): void {
    [$webinar, $accreditation] = accredited();
    $learner = User::factory()->create();

    app(IssueCertificate::class)($learner, $webinar, $accreditation);

    expect(fn () => app(IssueCertificate::class)($learner, $webinar, $accreditation))
        ->toThrow(RuntimeException::class, 'already hold a valid HRCI certificate');
});

/** But reissuing after a revocation must work — which is why it is not a UNIQUE. */
it('allows a fresh certificate once the old one is revoked', function (): void {
    [$webinar, $accreditation] = accredited();
    $learner = User::factory()->create();

    $first = app(IssueCertificate::class)($learner, $webinar, $accreditation);
    app(RevokeCertificate::class)($first, 'Name was misspelled');

    $second = app(IssueCertificate::class)($learner, $webinar, $accreditation);

    expect($second->serial)->not->toBe($first->serial)
        ->and(Certificate::query()->count())->toBe(2)
        ->and(Certificate::query()->valid()->count())->toBe(1);
});

// Revoking --------------------------------------------------------------------

it('keeps a revoked certificate on the record', function (): void {
    $staff = userWithRole(UserRole::SuperAdmin);
    $this->actingAs($staff);

    $certificate = Certificate::factory()->create();

    app(RevokeCertificate::class)($certificate, 'Issued against the wrong webinar');

    $certificate = $certificate->fresh();

    expect($certificate?->isRevoked())->toBeTrue()
        ->and($certificate?->revoked_reason)->toBe('Issued against the wrong webinar')
        ->and($certificate?->revoked_by)->toBe($staff->id)
        // Still findable by serial: a lookup resolving to nothing reads as a
        // forgery, which is a worse conversation than a withdrawal.
        ->and(Certificate::query()->matchingSerial($certificate->serial)->exists())->toBeTrue();
});

it('insists on a reason for revoking', function (): void {
    expect(fn () => app(RevokeCertificate::class)(Certificate::factory()->create(), '  '))
        ->toThrow(RuntimeException::class, 'Give a reason');
});

it('refuses to revoke twice', function (): void {
    $certificate = Certificate::factory()->revoked()->create();

    expect(fn () => app(RevokeCertificate::class)($certificate, 'Again'))
        ->toThrow(RuntimeException::class, 'already been revoked');
});

// Through the admin -----------------------------------------------------------

it('issues one from the register', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    [$webinar, $accreditation] = accredited('3.00');
    $learner = User::factory()->create(['name' => 'Daniel Okonkwo']);

    Livewire\Livewire::test(ListCertificates::class)
        ->callAction('issue', [
            'user_id' => $learner->getKey(),
            'webinar_id' => $webinar->getKey(),
            'accreditation_id' => $accreditation->getKey(),
        ]);

    expect(Certificate::query()->where('user_id', $learner->id)->first()?->credits)->toBe('3.00');
});

it('separates valid certificates from revoked ones', function (): void {
    $valid = Certificate::factory()->create();
    $revoked = Certificate::factory()->revoked()->create();

    Livewire\Livewire::actingAs(userWithRole(UserRole::Support))
        ->test(ListCertificates::class)
        ->assertCanSeeTableRecords([$valid])
        ->assertCanNotSeeTableRecords([$revoked])
        ->set('activeTab', 'revoked')
        ->assertCanSeeTableRecords([$revoked])
        ->assertCanNotSeeTableRecords([$valid]);
});

it('revokes and reissues in one step', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    [$webinar, $accreditation] = accredited('2.00');
    $learner = User::factory()->create();
    $original = app(IssueCertificate::class)($learner, $webinar, $accreditation);

    // The figure is corrected on the webinar, then the certificate replaced —
    // the reissue reads the live records, so it carries the new number.
    $webinar->accreditations()->updateExistingPivot($accreditation->id, ['credits' => '2.50']);

    Livewire\Livewire::test(ViewCertificate::class, ['record' => $original->getKey()])
        ->callAction('reissue', ['reason' => 'Credit hours restated by HRCI']);

    $replacement = Certificate::query()->valid()->first();

    expect($original->fresh()?->isRevoked())->toBeTrue()
        ->and($replacement?->credits)->toBe('2.50')
        ->and($replacement?->serial)->not->toBe($original->serial);
});

/** Support reads the register; awarding and withdrawing credit is not theirs. */
it('keeps issuing and revoking away from Support', function (): void {
    $this->actingAs(userWithRole(UserRole::Support));

    $certificate = Certificate::factory()->create();

    Livewire\Livewire::test(ListCertificates::class)->assertActionHidden('issue');

    Livewire\Livewire::test(ViewCertificate::class, ['record' => $certificate->getKey()])
        ->assertActionHidden('revoke')
        ->assertActionHidden('reissue');
});

it('offers no revoke on a certificate already revoked', function (): void {
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $certificate = Certificate::factory()->revoked()->create();

    Livewire\Livewire::test(ViewCertificate::class, ['record' => $certificate->getKey()])
        ->assertActionHidden('revoke')
        ->assertActionHidden('reissue');
});
