<?php

declare(strict_types=1);

use App\Enums\Format;
use App\Enums\WebinarStatus;
use App\Enums\WebinarType;
use App\Models\Redirect;
use App\Models\Webinar;
use Database\Seeders\Form1099WebinarSeeder;
use Database\Seeders\SettingSeeder;

/*
 * The seeder imports one real catalogue webinar from the live site. These tests
 * stand in for the manual check I cannot run (no DB access here): that the import
 * is publishable, priced correctly, and safe to re-run.
 */

const FORM_1099_SLUG = '2026-form-1099-reporting-new-rules-thresholds-key-updates';

function form1099(): Webinar
{
    return Webinar::where('slug', FORM_1099_SLUG)->sole();
}

beforeEach(function (): void {
    $this->seed(SettingSeeder::class);
    $this->seed(Form1099WebinarSeeder::class);
});

it('imports the webinar with the source title, schedule and speaker', function (): void {
    $webinar = form1099();

    expect($webinar->title)->toBe('2026 Form 1099 Reporting: New Rules, Thresholds & Key Updates')
        ->and($webinar->type)->toBe(WebinarType::Live)
        ->and($webinar->status)->toBe(WebinarStatus::Scheduled)
        ->and($webinar->duration_minutes)->toBe(90)
        ->and($webinar->timezone)->toBe('America/Los_Angeles')
        ->and($webinar->scheduled_at->timezone('America/Los_Angeles')->format('Y-m-d H:i'))->toBe('2026-11-16 10:00')
        ->and($webinar->speaker->name)->toBe('Jason Dinesen')
        ->and($webinar->industry->name)->toBe('Human Resources');
});

it('is publishable and publicly visible', function (): void {
    $webinar = form1099();

    expect($webinar->publishableProblems())->toBe([])
        ->and($webinar->isPubliclyVisible())->toBeTrue();
});

it('awards 1.5 CPE credits', function (): void {
    $webinar = form1099();

    expect($webinar->creditBodies())->toBe('CPE')
        ->and((float) $webinar->accreditations->firstWhere('code', 'CPE')->pivot->credits)->toBe(1.5);
});

it('creates the eight purchase options at their real prices', function (): void {
    $offerings = form1099()->offerings()->where('is_active', true)->get();

    expect($offerings)->toHaveCount(8);

    // Prices in integer cents — never a float on money (§6).
    expect($offerings->pluck('price_cents')->sort()->values()->all())
        ->toBe([17900, 18900, 18900, 19900, 19900, 19900, 19900, 22900]);

    expect($offerings->every(fn ($o): bool => $o->currency === 'USD'))->toBeTrue();
});

it('expresses the three-format bundle as one offering with three format rows', function (): void {
    $bundle = form1099()->offerings()->with('formats')->get()->first(
        fn ($o): bool => $o->price_cents === 22900,
    );

    expect($bundle->formatEnums()->all())
        ->toEqualCanonicalizing([Format::Live, Format::Recorded, Format::Transcript]);
});

it('registers a 301 from the legacy WordPress product URL', function (): void {
    $redirect = Redirect::where('from_path', '/product/'.FORM_1099_SLUG)->sole();

    expect($redirect->to_path)->toBe('/webinars/'.FORM_1099_SLUG)
        ->and($redirect->status_code)->toBe(301)
        ->and($redirect->source)->toBe(Redirect::SOURCE_IMPORT);
});

it('renders the public webinar page with its title and a price', function (): void {
    $response = $this->get(route('webinars.show', form1099()))->assertSuccessful();

    expect($response->getContent())
        ->toContain('2026 Form 1099 Reporting')
        ->toContain('Jason Dinesen')
        ->toContain('$179');
});

it('is safe to run twice', function (): void {
    // A second run must not stack duplicate offerings, credits or redirects.
    $this->seed(Form1099WebinarSeeder::class);

    expect(Webinar::where('slug', FORM_1099_SLUG)->count())->toBe(1)
        ->and(form1099()->offerings()->count())->toBe(8)
        ->and(form1099()->accreditations()->count())->toBe(1)
        ->and(Redirect::where('from_path', '/product/'.FORM_1099_SLUG)->count())->toBe(1);
});
