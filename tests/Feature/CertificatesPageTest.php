<?php

declare(strict_types=1);

use App\Models\Certificate;
use App\Models\User;

/*
 * The learner's certificates page and its download — §8 #4 / §6 / §9. Scoped to
 * the signed-in member, and the PDF is reached only through a signed, expiring,
 * owner-checked link.
 */

// The page -------------------------------------------------------------------

it('lists the member their own certificate with its details', function (): void {
    $user = User::factory()->create();
    Certificate::factory()->create([
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
        ->assertSee('Being prepared'); // no PDF rendered yet (P8)
});

it('offers a download once the certificate PDF exists', function (): void {
    $user = User::factory()->create();
    $certificate = Certificate::factory()->withPdf()->create(['user_id' => $user->getKey()]);

    $this->actingAs($user)
        ->get(route('filament.account.pages.certificates'))
        ->assertSuccessful()
        ->assertSee(route('learning.certificate', $certificate), escape: false)
        ->assertDontSee('Being prepared');
});

it('shows a revoked certificate as revoked with no download', function (): void {
    $user = User::factory()->create();
    $certificate = Certificate::factory()->revoked()->withPdf()->create(['user_id' => $user->getKey()]);

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

// The download ----------------------------------------------------------------

it('hands the owner a signed URL for their certificate PDF', function (): void {
    $user = User::factory()->create();
    $certificate = Certificate::factory()->withPdf()->create(['user_id' => $user->getKey()]);

    $this->actingAs($user)
        ->get(route('learning.certificate', $certificate))
        ->assertStatus(302); // a redirect to a minted URL, never the raw path
});

it('forbids downloading another member\'s certificate', function (): void {
    $owner = User::factory()->create();
    $certificate = Certificate::factory()->withPdf()->create(['user_id' => $owner->getKey()]);

    $this->actingAs(User::factory()->create())
        ->get(route('learning.certificate', $certificate))
        ->assertForbidden();
});

it('404s a certificate with no PDF yet', function (): void {
    $user = User::factory()->create();
    $certificate = Certificate::factory()->create(['user_id' => $user->getKey()]); // no pdf

    $this->actingAs($user)
        ->get(route('learning.certificate', $certificate))
        ->assertNotFound();
});

it('404s a revoked certificate', function (): void {
    $user = User::factory()->create();
    $certificate = Certificate::factory()->revoked()->withPdf()->create(['user_id' => $user->getKey()]);

    $this->actingAs($user)
        ->get(route('learning.certificate', $certificate))
        ->assertNotFound();
});

it('sends a guest to log in', function (): void {
    $certificate = Certificate::factory()->withPdf()->create();

    $this->get(route('learning.certificate', $certificate))
        ->assertRedirect(route('login'));
});
