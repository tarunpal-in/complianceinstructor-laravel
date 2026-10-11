<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Resources\Redirects\Pages\CreateRedirect;
use App\Models\Page;
use App\Models\Post;
use App\Models\Redirect;
use App\Models\Webinar;
use App\Seo\RedirectImporter;
use Database\Seeders\RolePermissionSeeder;

// Resolution -------------------------------------------------------------------------------------

it('resolves a simple redirect', function (): void {
    Redirect::factory()->create(['from_path' => '/old', 'to_path' => '/new', 'status_code' => 301]);

    expect(Redirect::destinationFor('/old'))->toBe(['/new', 301]);
});

it('normalises the lookup — case, trailing slash and query do not matter', function (): void {
    Redirect::factory()->create(['from_path' => '/old-page', 'to_path' => '/new-page']);

    expect(Redirect::destinationFor('/Old-Page/'))->toBe(['/new-page', 301])
        ->and(Redirect::destinationFor('/old-page?ref=x'))->toBe(['/new-page', 301]);
});

it('returns nothing for a path with no redirect', function (): void {
    expect(Redirect::destinationFor('/nothing-here'))->toBeNull();
});

it('ignores an inactive redirect', function (): void {
    Redirect::factory()->inactive()->create(['from_path' => '/paused', 'to_path' => '/somewhere']);

    expect(Redirect::destinationFor('/paused'))->toBeNull();
});

/*
 * Even if data somehow contains a chain, the visitor gets one hop to the end —
 * the belt to the save guards' braces.
 */
it('follows a chain to its final destination in one hop', function (): void {
    Redirect::factory()->create(['from_path' => '/a', 'to_path' => '/b']);
    Redirect::factory()->create(['from_path' => '/b', 'to_path' => '/c']);

    expect(Redirect::destinationFor('/a'))->toBe(['/c', 301]);
});

it('refuses to resolve a loop rather than spinning', function (): void {
    Redirect::factory()->create(['from_path' => '/x', 'to_path' => '/y']);
    Redirect::factory()->create(['from_path' => '/y', 'to_path' => '/x']);

    expect(Redirect::destinationFor('/x'))->toBeNull();
});

it('stops at an external destination', function (): void {
    Redirect::factory()->create(['from_path' => '/gone', 'to_path' => 'https://example.test/elsewhere']);

    expect(Redirect::destinationFor('/gone'))->toBe(['https://example.test/elsewhere', 301]);
});

// Save guards ------------------------------------------------------------------------------------

it('rejects a redirect that points at itself', function (): void {
    expect(Redirect::problemWith('/same', '/same'))->toContain('cannot point at itself');
});

it('rejects a chain at creation', function (): void {
    Redirect::factory()->create(['from_path' => '/b', 'to_path' => '/c']);

    expect(Redirect::problemWith('/a', '/b'))->toContain('already redirects');
});

it('rejects a loop at creation', function (): void {
    Redirect::factory()->create(['from_path' => '/b', 'to_path' => '/a']);

    expect(Redirect::problemWith('/a', '/b'))->toContain('loop');
});

it('allows a plain redirect', function (): void {
    expect(Redirect::problemWith('/moved', '/destination'))->toBeNull();
});

it('knows when a source would shadow a live page', function (): void {
    $webinar = publishedWebinar();

    expect(Redirect::shadowsLiveContent('/webinars/'.$webinar->slug))->toBeTrue()
        ->and(Redirect::shadowsLiveContent('/webinars/does-not-exist'))->toBeFalse()
        ->and(Redirect::shadowsLiveContent('/'))->toBeTrue();
});

// The middleware ---------------------------------------------------------------------------------

it('301s a moved URL to its destination', function (): void {
    Redirect::factory()->create(['from_path' => '/old-thing', 'to_path' => '/new-thing', 'status_code' => 301]);

    $response = test()->get('/old-thing')->assertStatus(301);

    expect($response->headers->get('Location'))->toEndWith('/new-thing/');
});

it('uses the configured status code', function (): void {
    Redirect::factory()->temporary()->create(['from_path' => '/temp', 'to_path' => '/place']);

    test()->get('/temp')->assertStatus(302);
});

it('carries the query string across an internal redirect', function (): void {
    Redirect::factory()->create(['from_path' => '/old', 'to_path' => '/new']);

    $response = test()->get('/old?utm_source=mail')->assertStatus(301);

    expect($response->headers->get('Location'))->toEndWith('/new/?utm_source=mail');
});

it('records a hit when a redirect is used', function (): void {
    $redirect = Redirect::factory()->create(['from_path' => '/counted', 'to_path' => '/target']);

    test()->get('/counted');

    expect($redirect->refresh()->hits)->toBe(1)
        ->and($redirect->last_hit_at)->not->toBeNull();
});

// Auto-301 on slug change (brief §4) -------------------------------------------------------------

it('creates a 301 when a webinar slug changes', function (): void {
    $webinar = publishedWebinar(['slug' => 'fmla-2026']);

    $webinar->update(['slug' => 'fmla-compliance-2026']);

    expect(Redirect::destinationFor('/webinars/fmla-2026'))
        ->toBe(['/webinars/fmla-compliance-2026', 301]);
});

it('creates a 301 when an article slug changes', function (): void {
    $post = Post::factory()->create(['slug' => 'old-title']);

    $post->update(['slug' => 'new-title']);

    expect(Redirect::destinationFor('/blog/old-title'))->toBe(['/blog/new-title', 301]);
});

it('creates a 301 when a page slug changes', function (): void {
    $page = Page::factory()->create(['slug' => 'about-us']);

    $page->update(['slug' => 'about']);

    expect(Redirect::destinationFor('/about-us'))->toBe(['/about', 301]);
});

/*
 * Renaming twice must not leave a chain old-1 → old-2 → new. The hop flattens it
 * so every old URL reaches the current one directly.
 */
it('flattens the chain when a slug changes twice', function (): void {
    $post = Post::factory()->create(['slug' => 'v1']);

    $post->update(['slug' => 'v2']);
    $post->update(['slug' => 'v3']);

    expect(Redirect::destinationFor('/blog/v1'))->toBe(['/blog/v3', 301])
        ->and(Redirect::destinationFor('/blog/v2'))->toBe(['/blog/v3', 301]);
});

it('does not redirect when a slug is unchanged', function (): void {
    $post = Post::factory()->create(['slug' => 'steady']);

    $post->update(['title' => 'A new title, same slug']);

    expect(Redirect::query()->count())->toBe(0);
});

// CSV import (brief §4) --------------------------------------------------------------------------

function writeCsv(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'redir').'.csv';
    file_put_contents($path, $contents);

    return $path;
}

it('imports a CSV of redirects', function (): void {
    $path = writeCsv("from,to,status\n/old-1,/new-1,301\n/old-2,https://example.test/x,302\n");

    $result = RedirectImporter::fromFile($path);

    expect($result['imported'])->toBe(2)
        ->and($result['skipped'])->toBe(0)
        ->and(Redirect::destinationFor('/old-1'))->toBe(['/new-1', 301])
        ->and(Redirect::destinationFor('/old-2'))->toBe(['https://example.test/x', 302]);

    unlink($path);
});

it('skips rows that would chain, loop or shadow a live page', function (): void {
    $webinar = publishedWebinar();
    Redirect::factory()->create(['from_path' => '/already', 'to_path' => '/onward']);

    $path = writeCsv(implode("\n", [
        'from,to',
        '/good,/fine',
        '/chain,/already',                     // chains onto an existing redirect
        '/webinars/'.$webinar->slug.',/x',      // shadows a live webinar
        '/loopy,/loopy',                        // points at itself
    ])."\n");

    $result = RedirectImporter::fromFile($path);

    expect($result['imported'])->toBe(1)
        ->and($result['skipped'])->toBe(3)
        ->and(Redirect::destinationFor('/good'))->toBe(['/fine', 301]);

    unlink($path);
});

// The admin form ---------------------------------------------------------------------------------

it('refuses to save a chain from the form', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->actingAs(userWithRole(UserRole::ContentEditor));

    Redirect::factory()->create(['from_path' => '/b', 'to_path' => '/c']);

    Livewire\Livewire::test(CreateRedirect::class)
        ->fillForm(['from_path' => '/a', 'to_path' => '/b', 'status_code' => 301, 'is_active' => true])
        ->call('create')
        ->assertHasFormErrors(['to_path']);
});

it('saves a valid redirect from the form, normalised', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->actingAs(userWithRole(UserRole::ContentEditor));

    Livewire\Livewire::test(CreateRedirect::class)
        ->fillForm(['from_path' => '/Old-Path/', 'to_path' => '/new-path', 'status_code' => 301, 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Redirect::query()->where('from_path', '/old-path')->exists())->toBeTrue();
});

it('keeps the redirect manager to roles holding the permission', function (): void {
    $this->seed(RolePermissionSeeder::class);

    expect(App\Filament\Resources\Redirects\RedirectResource::canViewAny())->toBeFalse();

    $this->actingAs(userWithRole(UserRole::ContentEditor));
    expect(App\Filament\Resources\Redirects\RedirectResource::canViewAny())->toBeTrue();
});
