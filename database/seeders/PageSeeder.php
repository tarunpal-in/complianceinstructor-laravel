<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PublishStatus;
use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * Two of the marketing pages §7 names, built from blocks — §5.9.
 *
 * A page builder with no pages in it cannot be reviewed: the client cannot see
 * what a block looks like, and neither can anybody checking the work. These
 * two are chosen to exercise most of the library between them — About uses the
 * hero, statistics, feature grid and call to action; the privacy policy is the
 * prose case every policy page will be.
 *
 * Copy is deliberately plain and obviously provisional. §12 has content
 * readiness as the most common cause of slipped launches, and placeholder text
 * that reads like finished copy is how it ships by accident.
 */
class PageSeeder extends Seeder
{
    public function run(): void
    {
        $this->page('About Us', 'about', PublishStatus::Published, [
            ['hero', [
                'eyebrow' => 'WHO WE ARE',
                'heading' => 'Compliance training that holds up',
                'lede' => 'Accredited continuing education for HR, payroll and safety professionals, delivered by practitioners.',
                'button_label' => 'Browse webinars',
                'button_url' => '/webinars',
            ]],
            ['stat_band', [
                'stats' => [
                    ['value' => '1,200+', 'label' => 'Webinars delivered'],
                    ['value' => '48,000+', 'label' => 'Professionals trained'],
                    ['value' => '4.9★', 'label' => 'Average rating'],
                ],
            ]],
            ['feature_grid', [
                'heading' => 'Why professionals choose us',
                'intro' => 'Placeholder copy — to be replaced with the client\'s own.',
                'features' => [
                    ['title' => 'Accredited credit', 'body' => 'Sessions carry HRCI, SHRM and CPE credit towards recertification.'],
                    ['title' => 'Practitioners, not lecturers', 'body' => 'Every speaker works in the field they teach.'],
                    ['title' => 'Yours to keep', 'body' => 'Recordings, transcripts and materials stay available after the session.'],
                ],
            ]],
            ['cta', [
                'heading' => 'Ready to start?',
                'body' => 'Browse the upcoming schedule, or take a membership and attend all year.',
                'button_label' => 'See memberships',
                'button_url' => '/#memberships',
                'tone' => 'primary',
            ]],
        ]);

        $this->page('Privacy Policy', 'privacy-policy', PublishStatus::Draft, [
            ['rich_text', [
                'heading' => 'Privacy Policy',
                'body' => '<p><strong>Placeholder.</strong> This page needs the client\'s own policy text before launch — it is not legal copy and must not be published as it stands.</p>'
                    .'<h3>What we collect</h3><p>Account details, purchase history and attendance records.</p>'
                    .'<h3>How to reach us</h3><p>Write to the address on the contact page.</p>',
            ]],
            ['faq', [
                'heading' => 'Common questions',
                'questions' => [
                    ['question' => 'How long is my data kept?', 'answer' => 'Placeholder answer, pending the client\'s retention policy.'],
                    ['question' => 'Can I ask for my data to be deleted?', 'answer' => 'Placeholder answer, pending the client\'s retention policy.'],
                ],
            ]],
        ]);
    }

    /**
     * @param  array<int, array{0: string, 1: array<string, mixed>}>  $blocks
     */
    private function page(string $title, string $slug, PublishStatus $status, array $blocks): void
    {
        $page = Page::firstOrCreate(
            ['slug' => $slug],
            [
                'title' => $title,
                'status' => $status,
                'published_at' => $status === PublishStatus::Published ? now()->subWeek() : null,
                'layout' => 'standard',
            ],
        );

        // Keyed on position, so re-running the seeder edits the blocks it made
        // rather than stacking a second copy of the page underneath the first.
        foreach ($blocks as $position => [$type, $data]) {
            $page->sections()->updateOrCreate(
                ['sort_order' => $position],
                ['type' => $type, 'data' => $data, 'is_visible' => true],
            );
        }
    }
}
