<?php

declare(strict_types=1);

use App\Actions\Certificates\GenerateCertificatePdf;
use App\Models\Certificate;
use App\Models\User;

/*
 * The learner's certificates page and its download — §8 #4 / §6 / §9. Scoped to
 * the signed-in member; the PDF is rendered on demand from the frozen record,
 * owner-checked, like the invoice. The Browsershot render itself is verified by
 * eye (§10), so the tests exercise the document data and the auth branches that
 * run before any render.
 */

// The page -------------------------------------------------------------------

it('lists the member their own certificate with a download', function (): void {
    $user = User::factory()->create();
    $certificate = Certificate::factory()->create([
        'user_id' => $user->getKey(),
        'webinar_title' => 'Advanced Payroll Compliance',
        'serial' => 'CERT-TEST-0001',
        'accreditation_code' => 'HRCI',
        'credits' => '2.00',
    ]);

    $this->actingAs($user)
        ->get(route('filament.account.pages.certificates'))
        ->assertSuccessful()
        ->assertSee('Advanced Payroll Compliance')
        ->assertSee('2.00 HRCI credits')
        ->assertSee('CERT-TEST-0001')
        ->assertSee(route('learning.certificate', $certificate), escape: false);
});

it('shows a revoked certificate as revoked with no download', function (): void {
    $user = User::factory()->create();
    $certificate = Certificate::factory()->revoked()->create(['user_id' => $user->getKey()]);

    $this->actingAs($user)
        ->get(route('filament.account.pages.certificates'))
        ->assertSuccessful()
        ->assertSee('Revoked')
        ->assertDontSee(route('learning.certificate', $certificate), escape: false);
});

it('does not show another member\'s certificates', function (): void {
    $user = User::factory()->create();
    Certificate::factory()->create([
        'user_id' => User::factory()->create()->getKey(),
        'webinar_title' => 'Someone Else\'s Session',
    ]);

    $this->actingAs($user)
        ->get(route('filament.account.pages.certificates'))
        ->assertSuccessful()
        ->assertDontSee('Someone Else\'s Session');
});

it('tells a member with no certificates there are none yet', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('filament.account.pages.certificates'))
        ->assertSuccessful()
        ->assertSee('no certificates yet');
});

// The document (rendered from the frozen record, not Browsershot) --------------

it('builds the certificate document from the frozen record', function (): void {
    $certificate = Certificate::factory()->create([
        'learner_name' => 'Dana Holloway',
        'webinar_title' => 'FMLA Essentials 2026',
        'serial' => 'CERT-DOC-0007',
        'accreditation_code' => 'SHRM',
        'credits' => '1.50',
    ]);

    $html = app(GenerateCertificatePdf::class)->view($certificate)->render();

    expect($html)
        ->toContain('Dana Holloway')
        ->toContain('FMLA Essentials 2026')
        ->toContain('CERT-DOC-0007')
        ->toContain('1.50 SHRM credits');

    expect(app(GenerateCertificatePdf::class)->filename($certificate))->toBe('certificate-CERT-DOC-0007.pdf');
});

// The download route (auth branches run before any render) ---------------------

it('forbids downloading another member\'s certificate', function (): void {
    $owner = User::factory()->create();
    $certificate = Certificate::factory()->create(['user_id' => $owner->getKey()]);

    $this->actingAs(User::factory()->create())
        ->get(route('learning.certificate', $certificate))
        ->assertForbidden();
});

it('404s a revoked certificate', function (): void {
    $user = User::factory()->create();
    $certificate = Certificate::factory()->revoked()->create(['user_id' => $user->getKey()]);

    $this->actingAs($user)
        ->get(route('learning.certificate', $certificate))
        ->assertNotFound();
});

it('sends a guest to log in', function (): void {
    $certificate = Certificate::factory()->create();

    $this->get(route('learning.certificate', $certificate))
        ->assertRedirect(route('login'));
});
