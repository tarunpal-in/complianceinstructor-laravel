<?php

declare(strict_types=1);

use App\Enums\Format;
use App\Enums\WebinarStatus;
use App\Enums\WebinarType;
use App\Import\Importers\IndustryImporter;
use App\Import\Importers\OfferingImporter;
use App\Import\Importers\SpeakerImporter;
use App\Import\Importers\WebinarImporter;
use App\Models\Industry;
use App\Models\Offering;
use App\Models\Speaker;
use App\Models\Webinar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 1 — the catalogue importers, exercised against an in-memory legacy
 * schema seeded from the real "New W4" webinar (product 10820): a Live-type
 * product with the full eight add-on variations. Proves type detection, the
 * schedule/duration parse, the combo→offering mapping and idempotency without
 * depending on the loaded ci_legacy dump.
 */

beforeEach(function (): void {
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
        $t->integer('post_author')->default(0);
        $t->text('post_title')->nullable();
        $t->string('post_name')->nullable();
        $t->string('post_status')->default('publish');
        $t->string('post_type')->default('post');
        $t->integer('post_parent')->default(0);
        $t->text('post_excerpt')->nullable();
        $t->text('post_content')->nullable();
        $t->integer('menu_order')->default(0);
    });
    $schema->create('postmeta', function ($t): void {
        $t->increments('meta_id');
        $t->integer('post_id');
        $t->string('meta_key');
        $t->text('meta_value')->nullable();
    });
    $schema->create('terms', function ($t): void {
        $t->integer('term_id')->primary();
        $t->string('name');
        $t->string('slug');
    });
    $schema->create('term_taxonomy', function ($t): void {
        $t->integer('term_taxonomy_id')->primary();
        $t->integer('term_id');
        $t->string('taxonomy');
        $t->integer('count')->default(0);
    });
    $schema->create('term_relationships', function ($t): void {
        $t->integer('object_id');
        $t->integer('term_taxonomy_id');
    });
});

function legacy(): Illuminate\Database\Connection
{
    return DB::connection('legacy');
}

/** Seed the "New W4" Live webinar (product 100) with its 8 variations. */
function seedNewW4(): void
{
    legacy()->table('posts')->insert([
        'ID' => 100, 'post_title' => 'The New W4 — Proper Completion',
        'post_name' => 'the-new-w4', 'post_status' => 'publish', 'post_type' => 'product',
        'post_excerpt' => 'A payroll withholding session.', 'post_content' => '<p>How to complete the W4.</p>',
    ]);
    foreach ([
        ['speaker', 'Mark Schwartz'], ['date', '20230119'],
        ['time_from', '10 AM PT | 01 PM ET'], ['duration', '90 Minutes'], ['series', ''],
    ] as [$k, $v]) {
        legacy()->table('postmeta')->insert(['post_id' => 100, 'meta_key' => $k, 'meta_value' => $v]);
    }

    // Industry + a format category, both linked to the product.
    legacy()->table('terms')->insert([
        ['term_id' => 10, 'name' => 'HUMAN RESOURCES', 'slug' => 'human-resources'],
        ['term_id' => 11, 'name' => 'RECORDED', 'slug' => 'recorded'],
    ]);
    legacy()->table('term_taxonomy')->insert([
        ['term_taxonomy_id' => 500, 'term_id' => 10, 'taxonomy' => 'product_cat', 'count' => 372],
        ['term_taxonomy_id' => 501, 'term_id' => 11, 'taxonomy' => 'product_cat', 'count' => 394],
    ]);
    legacy()->table('term_relationships')->insert([
        ['object_id' => 100, 'term_taxonomy_id' => 500],
        ['object_id' => 100, 'term_taxonomy_id' => 501],
    ]);

    // The 8 variations: [id, addon slug, price].
    $variations = [
        [101, 'live', '149'], [102, 'recorded', '159'], [103, 'e-transcript', '159'],
        [104, 'live-recorded', '179'], [105, 'recordede-transcript', '179'],
        [106, 'livee-transcript', '179'], [107, 'liverecordede-transcript', '229'],
        [108, 'download-course', '199'],
    ];
    foreach ($variations as $i => [$vid, $slug, $price]) {
        legacy()->table('posts')->insert([
            'ID' => $vid, 'post_status' => 'publish', 'post_type' => 'product_variation',
            'post_parent' => 100, 'menu_order' => $i,
        ]);
        legacy()->table('postmeta')->insert([
            ['post_id' => $vid, 'meta_key' => 'attribute_pa_addons', 'meta_value' => $slug],
            ['post_id' => $vid, 'meta_key' => '_price', 'meta_value' => $price],
            ['post_id' => $vid, 'meta_key' => '_regular_price', 'meta_value' => $price],
        ]);
    }
}

function runCatalogue(): void
{
    (new SpeakerImporter)->run();
    (new IndustryImporter)->run();
    (new WebinarImporter)->run();
    (new OfferingImporter)->run();
}

// -----------------------------------------------------------------------------

it('imports the speaker, industry and webinar with parsed schedule and type', function (): void {
    seedNewW4();
    runCatalogue();

    $speaker = Speaker::query()->sole();
    expect($speaker->name)->toBe('Mark Schwartz')->and($speaker->is_active)->toBeTrue();

    // "RECORDED" is a format category and is skipped; only the real industry lands.
    $industry = Industry::query()->sole();
    expect($industry->name)->toBe('Human Resources')->and($industry->slug)->toBe('human-resources');

    $webinar = Webinar::query()->sole();
    expect($webinar->title)->toBe('The New W4 — Proper Completion')
        ->and($webinar->slug)->toBe('the-new-w4')
        ->and($webinar->type)->toBe(WebinarType::Live)           // has live variations
        ->and($webinar->status)->toBe(WebinarStatus::Completed)  // session is in the past
        ->and($webinar->speaker_id)->toBe($speaker->getKey())
        ->and($webinar->industry_id)->toBe($industry->getKey())
        ->and($webinar->duration_minutes)->toBe(90)
        ->and($webinar->timezone)->toBe('America/Los_Angeles')
        ->and($webinar->scheduled_at->copy()->setTimezone('America/Los_Angeles')->format('Y-m-d H:i'))
        ->toBe('2023-01-19 10:00');
});

it('maps each variation to one offering with the right formats and price', function (): void {
    seedNewW4();
    runCatalogue();

    $webinar = Webinar::query()->sole();
    $offerings = $webinar->offerings()->with('formats')->get();

    expect($offerings)->toHaveCount(8);

    $byFormats = $offerings->mapWithKeys(fn (Offering $o): array => [
        $o->formatEnums()->map(fn (Format $f): string => $f->value)->sort()->implode('+') => $o->price_cents,
    ]);

    expect($byFormats['live'])->toBe(14900)
        ->and($byFormats['recorded'])->toBe(15900)
        ->and($byFormats['transcript'])->toBe(15900)
        ->and($byFormats['download'])->toBe(19900)
        ->and($byFormats['live+recorded'])->toBe(17900)
        ->and($byFormats['recorded+transcript'])->toBe(17900)
        ->and($byFormats['live+transcript'])->toBe(17900)
        ->and($byFormats['live+recorded+transcript'])->toBe(22900);
});

it('is idempotent — a second run updates in place, nothing duplicates', function (): void {
    seedNewW4();
    runCatalogue();
    runCatalogue();

    expect(Speaker::query()->count())->toBe(1)
        ->and(Industry::query()->count())->toBe(1)
        ->and(Webinar::query()->count())->toBe(1)
        ->and(Offering::query()->count())->toBe(8)
        ->and(DB::table('offering_format')->count())->toBe(13)   // 4 singles + 3 pairs + 1 triple = 13
        ->and(Webinar::query()->sole()->sections()->count())->toBe(1); // sections rebuilt, not duplicated
});

it('splits the WordPress description into titled sections and a heading-less notice', function (): void {
    legacy()->table('posts')->insert([
        'ID' => 300, 'post_title' => 'Content Split Session', 'post_name' => 'content-split',
        'post_status' => 'publish', 'post_type' => 'product',
        'post_content' => implode('', [
            '<h4>LIVE ONLINE TRAINING COURSE</h4>',
            '<p>Intro paragraph about the topic.</p>',
            '<h3><span><strong>Session Highlights:</strong></span></h3>',
            '<ul><li>Point one</li><li>Point two</li></ul>',
            '<h4><strong>Why You Should Attend</strong></h4>',
            '<p>Because it matters.</p>',
            '<h4>Who Should Attend:</h4>',
            '<ul><li>Payroll pros</li></ul>',
            '<p>For Recorded and E-transcript Participants: you will get the Recording link within 24-48 hours.</p>',
        ]),
    ]);
    legacy()->table('postmeta')->insert([
        ['post_id' => 300, 'meta_key' => 'speaker', 'meta_value' => 'Jane Doe'],
        ['post_id' => 300, 'meta_key' => 'duration', 'meta_value' => '60 Minutes'],
    ]);

    (new WebinarImporter)->run();

    $sections = Webinar::query()->where('slug', 'content-split')->sole()->sections()->get();

    expect($sections->pluck('heading')->all())->toBe([
        'Webinar Overview', 'Session Highlights', 'Why You Should Attend', 'Who Should Attend', null,
    ]);

    expect($sections[0]->body)->toContain('Intro paragraph')
        ->and($sections[0]->body)->toContain('LIVE ONLINE TRAINING COURSE') // banner stays with the intro
        ->and($sections[1]->body)->toContain('Point one')
        ->and($sections[1]->body)->not->toContain('Session Highlights')      // heading dropped; section carries it
        ->and($sections[2]->body)->toContain('Because it matters')
        ->and($sections[3]->body)->toContain('Payroll pros')
        ->and($sections[3]->body)->not->toContain('Recording link')          // notice not dumped into Who
        ->and($sections[4]->body)->toContain('Recording link');              // heading-less delivery notice
});

it('keeps an unrecognised description as a single Webinar Overview section', function (): void {
    legacy()->table('posts')->insert([
        'ID' => 400, 'post_title' => 'No Headings Session', 'post_name' => 'no-headings',
        'post_status' => 'publish', 'post_type' => 'product',
        'post_content' => '<p>There are a number of misconceptions about the ADA and FMLA.</p>',
    ]);
    legacy()->table('postmeta')->insert([
        ['post_id' => 400, 'meta_key' => 'duration', 'meta_value' => '60 Minutes'],
    ]);

    (new WebinarImporter)->run();

    $sections = Webinar::query()->where('slug', 'no-headings')->sole()->sections()->get();

    expect($sections)->toHaveCount(1)
        ->and($sections[0]->heading)->toBe('Webinar Overview')
        ->and($sections[0]->body)->toContain('misconceptions about the ADA');
});

it('classifies a product with no live variation as Recorded', function (): void {
    legacy()->table('posts')->insert([
        'ID' => 200, 'post_title' => 'Recorded Only Session', 'post_name' => 'recorded-only',
        'post_status' => 'publish', 'post_type' => 'product', 'post_content' => '<p>On demand.</p>',
    ]);
    legacy()->table('postmeta')->insert([
        ['post_id' => 200, 'meta_key' => 'speaker', 'meta_value' => 'Jane Doe'],
        ['post_id' => 200, 'meta_key' => 'date', 'meta_value' => '20240101'],
        ['post_id' => 200, 'meta_key' => 'duration', 'meta_value' => '60 Minutes'],
    ]);
    foreach ([[201, 'recorded', '99'], [202, 'e-transcript', '99']] as $i => [$vid, $slug, $price]) {
        legacy()->table('posts')->insert([
            'ID' => $vid, 'post_status' => 'publish', 'post_type' => 'product_variation',
            'post_parent' => 200, 'menu_order' => $i,
        ]);
        legacy()->table('postmeta')->insert([
            ['post_id' => $vid, 'meta_key' => 'attribute_pa_addons', 'meta_value' => $slug],
            ['post_id' => $vid, 'meta_key' => '_price', 'meta_value' => $price],
        ]);
    }

    runCatalogue();

    $webinar = Webinar::query()->where('slug', 'recorded-only')->sole();
    expect($webinar->type)->toBe(WebinarType::Recorded)
        ->and($webinar->duration_minutes)->toBe(60)
        ->and($webinar->offerings()->count())->toBe(2);
});
