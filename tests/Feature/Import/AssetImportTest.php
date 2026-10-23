<?php

declare(strict_types=1);

use App\Enums\Format;
use App\Import\Importers\AssetImporter;
use App\Models\ImportMap;
use App\Models\Webinar;
use App\Models\WebinarAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 6 — delivery assets from the variations' downloadable files. Recording
 * links and transcript PDFs become external-URL webinar assets, one per format,
 * deduped across the variations that share a recording.
 */

beforeEach(function (): void {
    config(['database.connections.legacy' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'wp_', 'foreign_key_constraints' => false,
    ]]);
    DB::purge('legacy');

    $s = Schema::connection('legacy');
    $s->create('posts', function ($t): void {
        $t->integer('ID')->primary();
        $t->string('post_type')->default('post');
        $t->string('post_status')->default('publish');
        $t->integer('post_parent')->default(0);
    });
    $s->create('postmeta', function ($t): void {
        $t->increments('meta_id');
        $t->integer('post_id');
        $t->string('meta_key');
        $t->text('meta_value')->nullable();
    });
});

/** A downloadable-files value as WooCommerce serializes it. */
function downloadableFile(string $uuid, string $name, string $url): string
{
    return serialize([$uuid => ['id' => $uuid, 'name' => $name, 'file' => $url]]);
}

function seedVariation(int $id, int $parentProduct, string $downloadableFiles): void
{
    DB::connection('legacy')->table('posts')->insert([
        'ID' => $id, 'post_type' => 'product_variation', 'post_status' => 'publish', 'post_parent' => $parentProduct,
    ]);
    DB::connection('legacy')->table('postmeta')->insert([
        'post_id' => $id, 'meta_key' => '_downloadable_files', 'meta_value' => $downloadableFiles,
    ]);
}

// -----------------------------------------------------------------------------

it('imports a recording and a transcript as external-URL assets', function (): void {
    $webinar = Webinar::factory()->create();
    ImportMap::remember('webinar', 100, $webinar);

    seedVariation(101, 100, downloadableFile('u1', 'RECORDING', 'https://attendee.gotowebinar.com/recording/123'));
    seedVariation(102, 100, downloadableFile('u2', 'E - TRANSCRIPT', 'https://www.complianceinstructor.com/wp-content/uploads/x.pdf'));

    (new AssetImporter)->run();

    $assets = $webinar->fresh()->assets()->get()->keyBy(fn (WebinarAsset $a): string => $a->format->value);

    expect($assets)->toHaveCount(2)
        ->and($assets[Format::Recorded->value]->url)->toBe('https://attendee.gotowebinar.com/recording/123')
        ->and($assets[Format::Recorded->value]->embed)->toBeFalse()
        ->and($assets[Format::Recorded->value]->is_active)->toBeTrue()
        ->and($assets[Format::Transcript->value]->url)->toBe('https://www.complianceinstructor.com/wp-content/uploads/x.pdf');
});

it('dedupes to one asset per webinar and format across variations', function (): void {
    $webinar = Webinar::factory()->create();
    ImportMap::remember('webinar', 100, $webinar);

    // Three variations all carry the same recording.
    seedVariation(101, 100, downloadableFile('u1', 'RECORDING', 'https://attendee.gotowebinar.com/recording/123'));
    seedVariation(102, 100, downloadableFile('u2', 'RECORDING', 'https://attendee.gotowebinar.com/recording/123'));
    seedVariation(103, 100, downloadableFile('u3', 'RECORDING', 'https://attendee.gotowebinar.com/recording/123'));

    (new AssetImporter)->run();

    expect($webinar->fresh()->assets()->where('format', Format::Recorded)->count())->toBe(1);
});

it('infers download format from the file name', function (): void {
    $webinar = Webinar::factory()->create();
    ImportMap::remember('webinar', 100, $webinar);
    seedVariation(101, 100, downloadableFile('u1', 'Course Materials Download', 'https://www.complianceinstructor.com/wp-content/uploads/materials.zip'));

    (new AssetImporter)->run();

    expect($webinar->fresh()->assets()->sole()->format)->toBe(Format::Download);
});

it('is idempotent across runs', function (): void {
    $webinar = Webinar::factory()->create();
    ImportMap::remember('webinar', 100, $webinar);
    seedVariation(101, 100, downloadableFile('u1', 'RECORDING', 'https://attendee.gotowebinar.com/recording/123'));

    (new AssetImporter)->run();
    (new AssetImporter)->run();

    expect($webinar->fresh()->assets()->count())->toBe(1);
});
