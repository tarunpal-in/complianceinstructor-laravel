<?php

declare(strict_types=1);

use App\Enums\Format;
use App\Import\FileMigrator;
use App\Models\Webinar;
use App\Models\WebinarAsset;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * Phase 6b — moving a WP-hosted delivery file onto the private disk and dropping
 * the external URL.
 */

beforeEach(function (): void {
    $this->disk = (string) config('filesystems.webinar_assets');
    Storage::fake($this->disk);
});

it('downloads a WP-hosted file, stores it and drops the URL', function (): void {
    Http::fake(['*' => Http::response('%PDF-1.7 fake transcript', 200)]);

    $webinar = Webinar::factory()->create();
    $asset = WebinarAsset::factory()->for($webinar)->format(Format::Transcript)->create([
        'url' => 'https://www.complianceinstructor.com/wp-content/uploads/woocommerce_uploads/2022/01/t.pdf',
        'disk_path' => null,
    ]);

    $outcome = (new FileMigrator($this->disk))->migrate($asset);
    $asset->refresh();

    expect($outcome)->toBe('downloaded')
        ->and($asset->disk_path)->not->toBeNull()
        ->and($asset->url)->toBeNull()
        ->and(str_ends_with($asset->disk_path, '.pdf'))->toBeTrue();

    Storage::disk($this->disk)->assertExists($asset->disk_path);
});

it('leaves a GoToWebinar recording as an external URL', function (): void {
    $webinar = Webinar::factory()->create();
    $asset = WebinarAsset::factory()->for($webinar)->format(Format::Recorded)->create([
        'url' => 'https://attendee.gotowebinar.com/recording/123',
        'disk_path' => null,
    ]);

    $outcome = (new FileMigrator($this->disk))->migrate($asset);

    expect($outcome)->toBe('skipped')
        ->and($asset->fresh()->url)->toBe('https://attendee.gotowebinar.com/recording/123')
        ->and($asset->fresh()->disk_path)->toBeNull();
});

it('is idempotent — an already-moved asset is skipped', function (): void {
    Http::fake(['*' => Http::response('bytes', 200)]);

    $webinar = Webinar::factory()->create();
    $asset = WebinarAsset::factory()->for($webinar)->format(Format::Transcript)->create([
        'url' => 'https://www.complianceinstructor.com/wp-content/uploads/x.pdf',
        'disk_path' => null,
    ]);

    $migrator = new FileMigrator($this->disk);
    $migrator->migrate($asset);
    $second = $migrator->migrate($asset->refresh());

    expect($second)->toBe('skipped');
});

it('reports a failed download without changing the asset', function (): void {
    Http::fake(['*' => Http::response('not found', 404)]);

    $webinar = Webinar::factory()->create();
    $asset = WebinarAsset::factory()->for($webinar)->format(Format::Download)->create([
        'url' => 'https://www.complianceinstructor.com/wp-content/uploads/gone.zip',
        'disk_path' => null,
    ]);

    expect((new FileMigrator($this->disk))->migrate($asset))->toBe('failed')
        ->and($asset->fresh()->disk_path)->toBeNull()
        ->and($asset->fresh()->url)->not->toBeNull();
});
