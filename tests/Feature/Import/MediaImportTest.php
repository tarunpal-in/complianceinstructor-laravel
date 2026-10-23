<?php

declare(strict_types=1);

use App\Import\Importers\MediaImporter;
use App\Models\ImportMap;
use App\Models\Industry;
use App\Models\Media;
use App\Models\Speaker;
use App\Models\Webinar;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/*
 * Phase 6c — attaching the old WordPress images to the imported records from the
 * local uploads backup, driven by the dump's attachment metadata. Exercised
 * against an in-memory legacy schema and a faked `uploads` disk, so it never
 * touches the real dump or the real backup.
 */

beforeEach(function (): void {
    // Conversions queue rather than process a fixture image inline.
    Queue::fake();
    Storage::fake('uploads');
    Storage::fake('public'); // where media-library stores (disk_name default)

    config(['database.connections.legacy' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'wp_',
        'foreign_key_constraints' => false,
    ]]);
    DB::purge('legacy');

    $schema = Schema::connection('legacy');

    $schema->create('posts', function ($t): void {
        $t->integer('ID')->primary();
        $t->text('post_title')->nullable();
        $t->string('post_type')->default('post');
    });
    $schema->create('postmeta', function ($t): void {
        $t->increments('meta_id');
        $t->integer('post_id');
        $t->string('meta_key');
        $t->text('meta_value')->nullable();
    });
    $schema->create('termmeta', function ($t): void {
        $t->increments('meta_id');
        $t->integer('term_id');
        $t->string('meta_key');
        $t->text('meta_value')->nullable();
    });
});

function legacyMedia(): Illuminate\Database\Connection
{
    return DB::connection('legacy');
}

/** A real, decodable image placed on the fake uploads disk at $rel. */
function putUpload(string $rel): void
{
    Storage::disk('uploads')->put($rel, (string) UploadedFile::fake()->image('x.jpg', 24, 24)->getContent());
}

it('attaches webinar, industry, content and speaker images and preserves the backup', function (): void {
    // --- Legacy dump rows -----------------------------------------------------
    // Webinar product 100 → featured attachment 900.
    legacyMedia()->table('posts')->insert([
        ['ID' => 100, 'post_title' => 'FMLA Update', 'post_type' => 'product'],
        ['ID' => 900, 'post_title' => 'cover', 'post_type' => 'attachment'],
        ['ID' => 901, 'post_title' => 'industry', 'post_type' => 'attachment'],
        ['ID' => 902, 'post_title' => 'Margie Faulk headshot', 'post_type' => 'attachment'],
        ['ID' => 903, 'post_title' => 'inline', 'post_type' => 'attachment'],
    ]);
    legacyMedia()->table('postmeta')->insert([
        ['post_id' => 100, 'meta_key' => '_thumbnail_id', 'meta_value' => '900'],
        ['post_id' => 900, 'meta_key' => '_wp_attached_file', 'meta_value' => '2024/05/cover.jpg'],
        ['post_id' => 901, 'meta_key' => '_wp_attached_file', 'meta_value' => '2024/05/industry.jpg'],
        ['post_id' => 902, 'meta_key' => '_wp_attached_file', 'meta_value' => '2024/05/margie-faulk.jpg'],
        ['post_id' => 903, 'meta_key' => '_wp_attached_file', 'meta_value' => '2024/05/inline.jpg'],
    ]);
    // Industry term 10 → thumbnail attachment 901.
    legacyMedia()->table('termmeta')->insert([
        ['term_id' => 10, 'meta_key' => 'thumbnail_id', 'meta_value' => '901'],
    ]);

    // --- Fixture files on the backup disk ------------------------------------
    foreach (['2024/05/cover.jpg', '2024/05/industry.jpg', '2024/05/margie-faulk.jpg', '2024/05/inline.jpg'] as $rel) {
        putUpload($rel);
    }

    // --- Already-imported local records + their import map -------------------
    $webinar = Webinar::factory()->create();
    ImportMap::remember('webinar', 100, $webinar);
    $section = $webinar->sections()->create([
        'heading' => 'Overview',
        'body' => '<p>Intro</p><img src="https://old.example/wp-content/uploads/2024/05/inline.jpg" alt="x">',
        'sort_order' => 0,
        'is_visible' => true,
    ]);

    $industry = Industry::factory()->create();
    ImportMap::remember('industry', 10, $industry);

    $speaker = Speaker::factory()->create(['name' => 'Margie Faulk']);

    // --- Run ------------------------------------------------------------------
    (new MediaImporter)->run([], dryRun: false, limit: 0);

    $webinar->refresh();
    $section->refresh();

    // Webinar: one featured image fills both slots, from the library.
    expect($webinar->card_media_id)->not->toBeNull()
        ->and($webinar->banner_media_id)->toBe($webinar->card_media_id)
        ->and(Media::query()->whereKey($webinar->card_media_id)->where('model_type', App\Models\MediaFolder::class)->exists())->toBeTrue();

    // Industry card + speaker portrait attached.
    expect($industry->refresh()->hasMedia('card'))->toBeTrue()
        ->and($speaker->refresh()->hasMedia('portrait'))->toBeTrue();

    // Embedded image repointed off wp-content.
    expect($section->body)->not->toContain('wp-content/uploads/2024/05/inline.jpg')
        ->and($section->body)->toContain('<img');

    // The backup is untouched — every fixture file still there.
    foreach (['2024/05/cover.jpg', '2024/05/industry.jpg', '2024/05/margie-faulk.jpg', '2024/05/inline.jpg'] as $rel) {
        expect(Storage::disk('uploads')->exists($rel))->toBeTrue();
    }
});

it('is idempotent and dry-run writes nothing', function (): void {
    legacyMedia()->table('posts')->insert([
        ['ID' => 100, 'post_title' => 'FMLA', 'post_type' => 'product'],
        ['ID' => 900, 'post_title' => 'cover', 'post_type' => 'attachment'],
    ]);
    legacyMedia()->table('postmeta')->insert([
        ['post_id' => 100, 'meta_key' => '_thumbnail_id', 'meta_value' => '900'],
        ['post_id' => 900, 'meta_key' => '_wp_attached_file', 'meta_value' => '2024/05/cover.jpg'],
    ]);
    putUpload('2024/05/cover.jpg');

    $webinar = Webinar::factory()->create();
    ImportMap::remember('webinar', 100, $webinar);

    // Dry run changes nothing.
    (new MediaImporter)->run(['webinars'], dryRun: true, limit: 0);
    expect($webinar->refresh()->card_media_id)->toBeNull()
        ->and(Media::query()->count())->toBe(0);

    // First real run assigns; second run adds no new media (hash dedup).
    (new MediaImporter)->run(['webinars'], dryRun: false, limit: 0);
    $first = $webinar->refresh()->card_media_id;
    expect($first)->not->toBeNull();

    (new MediaImporter)->run(['webinars'], dryRun: false, limit: 0);
    expect($webinar->refresh()->card_media_id)->toBe($first)
        ->and(Media::query()->count())->toBe(1);
});
