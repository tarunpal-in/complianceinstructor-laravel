<?php

declare(strict_types=1);

use App\Import\Importers\RedirectImporter;
use App\Import\Importers\SeoImporter;
use App\Models\ImportMap;
use App\Models\Redirect;
use App\Models\Webinar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 5 — content & SEO: Yoast overrides onto the webinars, and 301s from the
 * old /product/{slug} URLs to the new /webinars/{slug} pages.
 */

beforeEach(function (): void {
    config(['database.connections.legacy' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'wp_', 'foreign_key_constraints' => false,
    ]]);
    DB::purge('legacy');

    $s = Schema::connection('legacy');
    $s->create('posts', function ($t): void {
        $t->integer('ID')->primary();
        $t->string('post_name')->nullable();
    });
    $s->create('postmeta', function ($t): void {
        $t->increments('meta_id');
        $t->integer('post_id');
        $t->string('meta_key');
        $t->text('meta_value')->nullable();
    });
});

function seedWebinarWithLegacy(int $legacyId, string $oldSlug, array $yoast = []): Webinar
{
    $webinar = Webinar::factory()->create(['slug' => $oldSlug]);
    ImportMap::remember('webinar', $legacyId, $webinar);

    DB::connection('legacy')->table('posts')->insert(['ID' => $legacyId, 'post_name' => $oldSlug]);
    foreach ($yoast as $k => $v) {
        DB::connection('legacy')->table('postmeta')->insert(['post_id' => $legacyId, 'meta_key' => $k, 'meta_value' => $v]);
    }

    return $webinar;
}

// -----------------------------------------------------------------------------

it('imports Yoast title, description and keyword onto the webinar', function (): void {
    $webinar = seedWebinarWithLegacy(100, 'payroll-2026', [
        '_yoast_wpseo_title' => 'Payroll Compliance 2026 | Expert Webinar',
        '_yoast_wpseo_metadesc' => 'Everything payroll pros need for 2026 compliance.',
        '_yoast_wpseo_focuskw' => 'payroll compliance',
    ]);

    (new SeoImporter)->run();

    $seo = $webinar->fresh()->seo;
    expect($seo)->not->toBeNull()
        ->and($seo->title)->toBe('Payroll Compliance 2026 | Expert Webinar')
        ->and($seo->description)->toBe('Everything payroll pros need for 2026 compliance.')
        ->and($seo->keywords)->toBe('payroll compliance');
});

it('drops a Yoast value that still carries a template token', function (): void {
    $webinar = seedWebinarWithLegacy(101, 'templated', [
        '_yoast_wpseo_title' => '%%title%% %%sep%% %%sitename%%',
        '_yoast_wpseo_metadesc' => 'A real description.',
    ]);

    (new SeoImporter)->run();

    $seo = $webinar->fresh()->seo;
    expect($seo->title)->toBeNull()
        ->and($seo->description)->toBe('A real description.');
});

it('creates no SEO row for a webinar with no overrides', function (): void {
    $webinar = seedWebinarWithLegacy(102, 'no-seo');

    (new SeoImporter)->run();

    expect($webinar->fresh()->seo)->toBeNull();
});

it('redirects the old product URL to the new webinar page', function (): void {
    $webinar = seedWebinarWithLegacy(103, 'i-9-updates');

    (new RedirectImporter)->run();

    expect(Redirect::destinationFor('/product/i-9-updates'))
        ->toBe(['/webinars/'.$webinar->slug, 301]);
});

it('is idempotent across both importers', function (): void {
    seedWebinarWithLegacy(104, 'fmla', ['_yoast_wpseo_title' => 'FMLA Webinar']);

    (new SeoImporter)->run();
    (new SeoImporter)->run();
    (new RedirectImporter)->run();
    (new RedirectImporter)->run();

    expect(DB::table('seo_meta')->count())->toBe(1)
        ->and(Redirect::query()->where('from_path', '/product/fmla')->count())->toBe(1);
});
