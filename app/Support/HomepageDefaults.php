<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Page;

/**
 * The homepage's sections as the comp ships them — CLAUDE.md §2 / §5.6.
 *
 * The homepage is one `Page` (slug `home`) whose sections are `page_sections`
 * blocks, edited through the "Homepage Sections" screen. This is the one place
 * the default copy for those blocks lives, so the seeder (which populates a
 * fresh install) and the editor (which backfills a page someone emptied) can
 * never disagree about what the starting homepage is.
 *
 * Values here are the exact literals the hand-built components shipped with, so
 * `migrate:fresh --seed` renders the homepage pixel-for-pixel as before — now
 * from the database rather than from a controller.
 */
class HomepageDefaults
{
    /**
     * The ordered sections, as `['type' => ..., 'data' => [...]]`.
     *
     * Blocks are added here as each homepage section is migrated onto the
     * builder. Today: the hero.
     *
     * @return array<int, array{type: string, data: array<string, mixed>}>
     */
    public static function sections(): array
    {
        return [
            [
                'type' => 'home_hero',
                'data' => [
                    'eyebrow' => '#1 COMPLIANCE TRAINING PLATFORM',
                    'heading_lead' => 'Stay Ahead —',
                    'heading_highlight' => 'Learn from',
                    'heading_tail' => 'the Best.',
                    'lede' => 'Access live and recorded compliance webinars led by certified industry '
                        .'experts. Earn HRCI, SHRM, and NASBA/CPE recertification credits — complete '
                        .'with study materials, certificates, and transcripts.',
                    'primary_label' => 'Start Learning Now',
                    'secondary_label' => 'View Courses',
                    'secondary_url' => '#webinars',
                    'stats' => [
                        ['value' => '1,200+', 'label' => 'Webinars Delivered'],
                        ['value' => '48,000+', 'label' => 'Professionals Trained'],
                        ['value' => '4.9★', 'label' => 'Average Rating'],
                    ],
                    'bodies' => [
                        ['label' => 'HRCI'],
                        ['label' => 'SHRM'],
                        ['label' => 'CPE'],
                    ],
                ],
            ],
            [
                'type' => 'home_process',
                'data' => [
                    'eyebrow' => 'SIMPLE PROCESS',
                    'heading' => 'How It Works',
                    'intro' => 'From registration to certification in three straightforward steps.',
                    'steps' => [
                        ['icon' => 'user-round', 'title' => 'Create Your Free Account', 'body' => 'Sign up in under 60 seconds. No credit card required. Access your learner dashboard immediately.'],
                        ['icon' => 'monitor', 'title' => 'Explore Courses & Webinars', 'body' => 'Browse hundreds of live and recorded compliance webinars across HR, payroll, manufacturing, and more.'],
                        ['icon' => 'star', 'title' => 'Enroll & Start Learning', 'body' => 'Attend live or on-demand, earn accredited CEU credits, and receive your certificate of completion.'],
                    ],
                ],
            ],
            [
                'type' => 'home_benefits',
                'data' => [
                    'benefits' => [
                        ['icon' => 'users-round', 'title' => 'Expert-Led Online Sessions', 'body' => 'Learn from certified practitioners with 15+ years of real-world compliance experience.'],
                        ['icon' => 'monitor', 'title' => 'Flexible Learning Options', 'body' => 'Attend live webinars in real-time or watch recordings on your own schedule — 24/7 access.'],
                        ['icon' => 'award', 'title' => 'Career-Boosting Certifications', 'body' => 'Earn HRCI, SHRM, and NASBA/CPE credits that count toward professional recertification.'],
                    ],
                ],
            ],
            [
                'type' => 'home_stories',
                'data' => [
                    'eyebrow' => 'WHAT PROFESSIONALS SAY',
                    'heading' => 'Success Stories',
                    'rating' => '4.8',
                    'reviews' => 312,
                    'stories' => [
                        [
                            'quote' => "Jason's H.O.P.E. framework gave me a concrete language to describe what great leadership looks like. I implemented the Ownership pillar the following week and saw an immediate shift in team accountability.",
                            'name' => 'Sandra Mitchell',
                            'role' => 'HR Director · BlueCross Health',
                            'initials' => 'SM',
                        ],
                        [
                            'quote' => 'One of the most practical webinars I have attended. The Q&A was genuinely useful, the slides are clean and reference-able, and the CPE certificate was in my inbox within minutes.',
                            'name' => 'Marcus Okafor',
                            'role' => 'VP of People Operations · FinServe Inc.',
                            'initials' => 'MO',
                        ],
                        [
                            'quote' => 'We send our whole compliance team now. Buying seats on one invoice and having everyone credited individually was the thing that made it work for us.',
                            'name' => 'Priya Sharma',
                            'role' => 'L&D Manager · GlobalTech Solutions',
                            'initials' => 'PS',
                        ],
                    ],
                ],
            ],
            [
                'type' => 'home_webinars',
                'data' => [
                    'eyebrow' => 'LIVE EVENTS',
                    'heading' => 'Upcoming Webinars',
                    'intro' => 'Expert-led sessions with recognized CEU credits. Register today.',
                    'link_label' => 'All Upcoming Webinars',
                    'link_url' => '#webinars',
                    'source' => 'auto',
                    'limit' => 3,
                ],
            ],
            [
                'type' => 'home_categories',
                'data' => [
                    'eyebrow' => 'BROWSE BY INDUSTRY',
                    'heading' => 'Webinar Categories',
                    'intro' => 'Built around the regulations you actually answer to',
                    'source' => 'auto',
                    'limit' => 3,
                ],
            ],
            [
                'type' => 'home_faculty',
                'data' => [
                    'eyebrow' => 'OUR INSTRUCTORS',
                    'heading' => 'Experienced Faculty',
                    'intro' => "Our speakers have argued the cases, run the audits and built the programs. They bring the edge cases you won't find in a compliance handbook — and they stay on the line until your question is answered.",
                    'button_label' => 'Meet the Speakers',
                    'button_url' => '#speakers',
                    'source' => 'auto',
                    'limit' => 6,
                ],
            ],
            [
                'type' => 'home_accreditation',
                'data' => [
                    'eyebrow' => 'TRUSTED CREDENTIALS',
                    'heading' => 'Accreditation Partners',
                    'intro' => 'Every webinar carries credits recognized by the industry\'s leading professional certification bodies.',
                    'partners' => [
                        ['code' => 'SHRM', 'badge' => "RECERTIFICATION\nPROVIDER", 'name' => 'Society for Human Resource Mgmt'],
                        ['code' => 'CPE', 'badge' => "NATIONAL REGISTRY OF\nCPE SPONSORS", 'name' => 'Continuing Professional Education'],
                        ['code' => 'HRCI', 'badge' => "RECERTIFICATION\nPROVIDER", 'name' => 'HR Certification Institute'],
                    ],
                ],
            ],
            [
                'type' => 'home_pricing',
                'data' => [
                    'eyebrow' => 'FLEXIBLE PRICING',
                    'heading' => 'Membership Plans',
                    'intro' => 'Invest in your professional development with a plan that matches your learning pace.',
                    'callback_heading' => 'Not sure which plan is right for you?',
                    'callback_body' => 'Our team can help match you with the best training package for your goals and budget.',
                    'callback_button' => 'Request a Callback',
                ],
            ],
        ];
    }

    /**
     * Write the default sections onto a page that has none.
     *
     * Keyed on position so a re-run edits rather than stacks, matching
     * PageSeeder. Used to backfill the home page if it is ever emptied — the
     * editor locks adding/removing blocks, so an empty home page would otherwise
     * be unrecoverable from the screen itself.
     */
    public static function apply(Page $page): void
    {
        foreach (self::sections() as $position => $section) {
            $page->sections()->updateOrCreate(
                ['sort_order' => $position],
                ['type' => $section['type'], 'data' => $section['data'], 'is_visible' => true],
            );
        }
    }
}
