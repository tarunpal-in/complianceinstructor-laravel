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
                'lede' => 'Compliance Instructor delivers accredited continuing education for HR, payroll and workplace-safety professionals — taught by practitioners who do the work, not just talk about it.',
                'button_label' => 'Browse webinars',
                'button_url' => '/webinars',
            ]],
            ['rich_text', [
                'heading' => 'Our mission',
                'body' => '<p>Regulations change constantly, and the cost of getting them wrong lands on the people responsible for getting them right. We exist to make staying current straightforward: accredited training that turns a shifting rulebook into clear, practical steps your team can act on.</p>'
                    .'<p>Every session is built around the credits professionals actually need to maintain their certification, and led by someone who has sat in the same seat as the people attending.</p>',
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
                'intro' => 'Accredited, practical and yours to keep — training built for the people who answer to the regulators.',
                'features' => [
                    ['icon' => 'award', 'title' => 'Accredited credit', 'body' => 'Sessions carry HRCI, SHRM, CPE and APA credit toward recertification — the hours you need, documented.'],
                    ['icon' => 'users-round', 'title' => 'Practitioners, not lecturers', 'body' => 'Every speaker works in the field they teach, so the guidance is grounded in real cases, not theory.'],
                    ['icon' => 'monitor', 'title' => 'Yours to keep', 'body' => 'Recordings, transcripts and materials stay available after the session, ready whenever you need to refer back.'],
                    ['icon' => 'refresh-cw', 'title' => 'Always current', 'body' => 'Content is updated as the rules change, so what you learn reflects the regulations in force today.'],
                ],
            ]],
            ['feature_grid', [
                'heading' => 'What every session includes',
                'intro' => 'One price, everything you need to earn the credit and put it to work.',
                'features' => [
                    ['icon' => 'award', 'title' => 'Certificate of completion', 'body' => 'A branded certificate with your credit hours and accrediting body, issued as soon as you qualify.'],
                    ['icon' => 'monitor', 'title' => 'On-demand recording', 'body' => 'Attend live, or watch the recording on your own schedule — the choice is yours.'],
                    ['icon' => 'info', 'title' => 'Full transcript', 'body' => 'A written transcript of every session, easy to search and reference later.'],
                    ['icon' => 'users-round', 'title' => 'Live Q&A', 'body' => 'Put your own questions to the speaker during the session and get a practitioner\'s answer.'],
                ],
            ]],
            ['rich_text', [
                'heading' => 'Who we serve',
                'body' => '<p>Our members are the people accountable for compliance day to day — HR generalists and directors, payroll and benefits managers, EHS and safety leads, and the consultants who advise them.</p>'
                    .'<p>Whether you are maintaining a single certification or training a whole team, the catalogue is built around the credits you have to earn and the questions you actually face.</p>',
            ]],
            ['cta', [
                'heading' => 'Ready to start?',
                'body' => 'Browse the upcoming schedule, or take a membership and attend all year.',
                'button_label' => 'See memberships',
                'button_url' => '/#memberships',
                'tone' => 'primary',
            ]],
        ]);

        $this->page('Contact Us', 'contact', PublishStatus::Published, [
            ['hero', [
                'eyebrow' => 'WE ARE HERE TO HELP',
                'heading' => 'Get in touch',
                'lede' => 'Questions about a webinar, a membership or your credits? Send us a note and the team will get back to you.',
            ]],
            ['contact', [
                'heading' => 'Contact us',
                'intro' => 'Reach us directly, or use the form — placeholder details, to be replaced with the client\'s own.',
                'details' => [
                    ['icon' => 'mail', 'label' => 'Email', 'value' => 'support@complianceinstructor.com', 'url' => 'mailto:support@complianceinstructor.com'],
                    ['icon' => 'phone', 'label' => 'Phone', 'value' => '(800) 555-0142', 'url' => 'tel:+18005550142'],
                    ['icon' => 'clock-3', 'label' => 'Hours', 'value' => 'Mon–Fri, 9am–5pm ET', 'url' => null],
                ],
                'form_heading' => 'Send us a message',
                'button_label' => 'Send message',
                'success_message' => 'Thanks — your message is on its way. We will be in touch shortly.',
            ]],
            ['faq', [
                'heading' => 'Before you write',
                'questions' => [
                    ['question' => 'How do I access a webinar I bought?', 'answer' => 'Placeholder answer — sign in and open your dashboard.'],
                    ['question' => 'Where are my certificates?', 'answer' => 'Placeholder answer — certificates appear in your dashboard once a session is completed.'],
                ],
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
