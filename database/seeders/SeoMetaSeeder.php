<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Page;
use App\Models\Post;
use App\Models\Webinar;
use Illuminate\Database\Seeder;

/**
 * A handful of SEO overrides, so the seeded site shows the feature populated.
 *
 * Deliberately sparse. The point of per-page SEO is that most pages are fine on
 * their computed defaults and the team overrides only where needed — a seeder
 * that filled in every field would misrepresent how the feature is used, and
 * §5.9 wants the seeded database to be a realistic review environment.
 *
 * Defensive throughout: it attaches to whatever content exists and skips what
 * does not, so it runs cleanly whether or not the catalogue has been seeded yet.
 */
class SeoMetaSeeder extends Seeder
{
    public function run(): void
    {
        // A webinar with a hand-written result snippet — the common real case,
        // where the team wants the title to lead with the credit on offer.
        Webinar::query()->latest('id')->take(2)->get()->each(function (Webinar $webinar): void {
            $webinar->seo()->updateOrCreate([], [
                'title' => $webinar->title.' — Earn CEU Credits',
                'description' => 'Join this live session and earn HRCI, SHRM or CPE recertification credits. '
                    .'Certificate and recording included.',
                'keywords' => 'compliance webinar, ceu credits',
            ]);
        });

        // One article pointed at a cleaner canonical, to demonstrate the override
        // without breaking anything — it points at its own live URL.
        Post::query()->latest('id')->first()?->seo()->updateOrCreate([], [
            'description' => 'Practical, up-to-date guidance for HR, payroll and safety professionals.',
        ]);

        // A marketing page explicitly kept out of search — e.g. a thank-you page.
        Page::query()->where('slug', 'like', '%thank%')->first()?->seo()->updateOrCreate([], [
            'robots' => 'noindex, follow',
        ]);

        // One example migration redirect, so the register is not empty on review.
        $webinar = Webinar::query()->latest('id')->first();

        if ($webinar !== null) {
            \App\Models\Redirect::query()->updateOrCreate(
                ['from_path' => '/legacy/fmla-webinar-2025'],
                [
                    'to_path' => '/webinars/'.$webinar->slug,
                    'status_code' => 301,
                    'source' => \App\Models\Redirect::SOURCE_IMPORT,
                    'is_active' => true,
                    'note' => 'Example: an old WordPress URL pointed at its replacement.',
                ],
            );
        }
    }
}
