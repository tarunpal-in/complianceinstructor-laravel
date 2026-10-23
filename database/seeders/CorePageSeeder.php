<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CorePageKey;
use App\Models\CorePage;
use Illuminate\Database\Seeder;

/**
 * The four fixed core pages, seeded with the copy that was hardcoded in their
 * blades so nothing changes visually until an editor touches them.
 *
 * firstOrCreate, not updateOrCreate: once the client edits a hero, re-running
 * the seeder (a fresh deploy, another seed pass) must not overwrite their words
 * with these defaults.
 */
class CorePageSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->pages() as $attributes) {
            $page = CorePage::query()->firstOrCreate(
                ['key' => $attributes['key']],
                $attributes,
            );

            // The contact page keeps a short FAQ — the native-<details> accordion,
            // the way the old page-builder contact page carried one. Only when it
            // has none, so a re-seed never overwrites an edit.
            if ($page->key === CorePageKey::Contact && empty($page->faq)) {
                $page->update(['faq' => [
                    ['question' => 'How do I access a webinar I bought?', 'answer' => 'Sign in and open your dashboard — live, recorded and transcript access all appear there.'],
                    ['question' => 'Where are my certificates?', 'answer' => 'They appear in your dashboard once a session is completed.'],
                ]]);
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function pages(): array
    {
        $business = config('app.name');

        return [
            [
                'key' => CorePageKey::Speakers->value,
                'hero_eyebrow' => 'JOIN OUR FACULTY',
                'hero_title' => 'Learn from the Best in Compliance',
                'hero_subtitle' => "Every {$business} speaker is a working practitioner — an attorney, CPA, certified HR professional, or regulatory specialist with real-world authority in their field.",
                'hero_overlay' => true,
            ],
            [
                'key' => CorePageKey::Membership->value,
                'hero_eyebrow' => 'MEMBERSHIPS',
                'hero_title' => 'One plan, a year of credits',
                'hero_subtitle' => 'Pick the allowance that matches how your team learns — live sessions, recordings and transcripts across the accrediting bodies you report to. Cancel anytime; your access runs to the end of the term you paid for.',
                'hero_overlay' => true,
                // Blank by default: each membership button keeps its own words and
                // the standard /membership link until an admin fills these in to
                // override them everywhere at once.
                'cta_label' => null,
                'cta_url' => null,
            ],
            [
                'key' => CorePageKey::Industries->value,
                'hero_eyebrow' => 'BY INDUSTRY',
                'hero_title' => 'Compliance training for your field',
                'hero_subtitle' => 'Browse webinars and CEU credits tailored to your industry — HR, payroll, manufacturing, finance and more.',
                'hero_overlay' => true,
            ],
            [
                'key' => CorePageKey::Contact->value,
                'hero_eyebrow' => 'WE ARE HERE TO HELP',
                'hero_title' => 'Get in touch',
                'hero_subtitle' => 'Questions about a webinar, a membership or your credits? Send us a note and a real person will get back to you.',
                'hero_overlay' => true,
            ],
        ];
    }
}
