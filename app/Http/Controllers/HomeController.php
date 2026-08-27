<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The public homepage, built from design-ui/Homepage.pdf.
 *
 * Everything below is static placeholder content. CLAUDE.md §4 rule 2 —
 * "Controllers route; Actions do" — so this holds no logic, and each array here
 * is a marked swap point:
 *
 *   $webinars   → Webinar::upcoming()->with('speaker')            (P2)
 *   $categories → Industry::active()->withCount('webinars')       (P2)
 *   $speakers   → Speaker::active()->featured()                   (P2)
 *   $plans      → MembershipPlan::active()->with('entitlements')  (P4)
 *   $stats      → settings (§5.6), never hardcoded in a template
 *
 * In P6 the page itself becomes `pages` + `page_sections` rows edited through
 * Filament's Builder (§2), and each section partial becomes a block. The data
 * shapes are kept close to the eventual models so that move is mechanical.
 */
class HomeController extends Controller
{
    public function __invoke(): View
    {
        return view('home.index', [
            'stats' => $this->stats(),
            'bodies' => ['HRCI', 'SHRM', 'CPE'],
            'steps' => $this->steps(),
            'webinars' => $this->webinars(),
            'categories' => $this->categories(),
            'values' => $this->values(),
            'speakers' => $this->speakers(),
            'partners' => $this->partners(),
            'testimonials' => $this->testimonials(),
            'plans' => $this->plans(),
        ]);
    }

    /** @return array<int, array{value: string, label: string}> */
    private function stats(): array
    {
        return [
            ['value' => '1,200+', 'label' => 'Webinars Delivered'],
            ['value' => '48,000+', 'label' => 'Professionals Trained'],
            ['value' => '4.9★', 'label' => 'Average Rating'],
        ];
    }

    /** @return array<int, array{number: string, title: string, body: string, icon: string}> */
    private function steps(): array
    {
        return [
            [
                'number' => '01',
                'title' => 'Create Your Free Account',
                'body' => 'Sign up in under 60 seconds. No credit card required. Access your learner dashboard immediately.',
                'icon' => '<svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
            ],
            [
                'number' => '02',
                'title' => 'Explore Courses & Webinars',
                'body' => 'Browse hundreds of live and recorded compliance webinars across HR, payroll, manufacturing, and more.',
                'icon' => '<svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>',
            ],
            [
                'number' => '03',
                'title' => 'Enroll & Start Learning',
                'body' => 'Attend live or on-demand, earn accredited CEU credits, and receive your certificate of completion.',
                'icon' => '<svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>',
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function webinars(): array
    {
        // The comp repeats one card four times and its date badge (26 AUG)
        // contradicts its own body text (Aug 22). Real dates here; the view
        // derives badge and body from this single Carbon instance.
        $base = Carbon::create(2026, 9, 22, 10, 0);

        return collect([
            ['title' => 'FMLA Compliance 2026: Avoiding Costly Mistakes', 'speaker' => 'Sandra Torres, SHRM-SCP', 'speaker_title' => 'HR Compliance Specialist', 'accreditations' => ['HRCI', 'SHRM']],
            ['title' => 'Payroll Year-End Close: What Changed for 2026', 'speaker' => 'Marcus Reed, CPP', 'speaker_title' => 'Payroll Operations Lead', 'accreditations' => ['CPE', 'APA']],
            ['title' => 'OSHA Recordkeeping for Manufacturing Teams', 'speaker' => 'Dr. Alicia Nwosu', 'speaker_title' => 'EHS Director', 'accreditations' => ['HRCI']],
            ['title' => 'Wage & Hour Audits: Preparing Before the Letter', 'speaker' => 'David Kaplan, JD', 'speaker_title' => 'Employment Counsel', 'accreditations' => ['HRCI', 'SHRM']],
        ])->map(fn (array $webinar, int $i): array => [
            ...$webinar,
            'starts_at' => $base->copy()->addDays($i * 7),
            'time_label' => '10:00 AM PT / 1:00 PM ET',
            'duration' => '90 Min Live Webinar',
        ])->all();
    }

    /** @return array<int, array{name: string, count: int}> */
    private function categories(): array
    {
        return [
            ['name' => 'Manufacturing', 'count' => 48],
            ['name' => 'Human Resources', 'count' => 48],
            ['name' => 'Microsoft Office', 'count' => 48],
        ];
    }

    /** @return array<int, array{title: string, body: string, icon: string}> */
    private function values(): array
    {
        return [
            [
                'title' => 'Expert-Led Online Sessions',
                'body' => 'Learn from certified practitioners with 15+ years of real-world compliance experience.',
                'icon' => '<svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
            ],
            [
                'title' => 'Flexible Learning Options',
                'body' => 'Attend live webinars in real time or watch recordings on your own schedule — 24/7 access.',
                'icon' => '<svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
            ],
            [
                'title' => 'Career-Boosting Certifications',
                'body' => 'Earn HRCI, SHRM, and NASBA/CPE credits that count toward professional recertification.',
                'icon' => '<svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="9" r="6"/><path d="m8.5 14.5-1 7L12 19l4.5 2.5-1-7"/></svg>',
            ],
        ];
    }

    /** @return array<int, array{name: string, credentials: string, bodies: string}> */
    private function speakers(): array
    {
        return [
            ['name' => 'Diane Whitfield', 'credentials' => 'SPHR · Former regional HR director, Fortune 100 retail', 'bodies' => 'HRCI · SHRM'],
            ['name' => 'Marcus Reed', 'credentials' => 'CPP · 18 years in multi-state payroll operations', 'bodies' => 'CPE · APA'],
            ['name' => 'Dr. Alicia Nwosu', 'credentials' => 'CSP · EHS director across three manufacturing plants', 'bodies' => 'HRCI'],
            ['name' => 'David Kaplan', 'credentials' => 'JD · Employment counsel, wage & hour defence', 'bodies' => 'HRCI · SHRM'],
        ];
    }

    /** @return array<int, array{code: string, name: string}> */
    private function partners(): array
    {
        return [
            ['code' => 'SHRM', 'name' => 'Society for Human Resource Management'],
            ['code' => 'CPE', 'name' => 'Continuing Professional Education'],
            ['code' => 'HRCI', 'name' => 'HR Certification Institute'],
        ];
    }

    /** @return array<int, array{quote: string, name: string, role: string}> */
    private function testimonials(): array
    {
        return [
            [
                'quote' => 'The H.O.P.E. framework gave me concrete language for what great leadership looks like. I implemented the Ownership pillar the following week and saw an immediate shift in team accountability.',
                'name' => 'Sandra Mitchell',
                'role' => 'HR Director, BlueCross Health',
            ],
            [
                'quote' => 'One of the most practical webinars I have attended. The Q&A was genuinely useful, the slides are clean and referenceable, and the CPE certificate was in my inbox within minutes.',
                'name' => 'Marcus Okafor',
                'role' => 'VP of People Operations, FinServe Inc.',
            ],
            [
                'quote' => 'We send our whole compliance team now. Being able to buy seats on one invoice and have everyone credited individually was the thing that made it work for us.',
                'name' => 'Priya Sharma',
                'role' => 'L&D Manager, GlobalTech Solutions',
            ],
        ];
    }

    /**
     * Prices are integer cents per CLAUDE.md §4 rule 6.
     *
     * Two things here are inferred from the comp and must be confirmed against
     * the §17 offering matrix before P4 builds against them: which features each
     * tier actually includes (the comp strikes rows through without a legend),
     * and that the lower tiers are limited to SHRM & HRCI credits while the
     * upper two carry all providers — a plan-level accreditation scope that
     * §5.5 `plan_entitlements` currently cannot express.
     *
     * @return array<int, array<string, mixed>>
     */
    private function plans(): array
    {
        $features = static fn (string $ceu, string $support, int $included): array => collect([
            $ceu,
            'Certificate of Completion',
            'PDF Study Materials',
            'E-Transcript Access',
            'Recording Download',
            $support,
        ])->map(fn (string $label, int $i): array => [
            'label' => $label,
            'included' => $i < $included,
        ])->all();

        return [
            [
                'name' => 'Regular',
                'price_cents' => 29900,
                'term' => '3 months',
                'allowance' => '3 Live + Recording',
                'featured' => false,
                'features' => $features('CEU Credits (SHRM & HRCI)', 'Dedicated Support Specialist', 3),
            ],
            [
                'name' => 'Plus',
                'price_cents' => 49900,
                'term' => '5 months',
                'allowance' => '5 Live Webinars',
                'featured' => false,
                'features' => $features('CEU Credits (SHRM & HRCI)', 'Dedicated Support Specialist', 4),
            ],
            [
                'name' => 'Standard',
                'price_cents' => 79900,
                'term' => '7 months',
                'allowance' => '7 Live Webinars',
                'featured' => false,
                'features' => $features('CEU Credits (All Providers)', 'Priority Support Line', 5),
            ],
            [
                'name' => 'Business',
                'price_cents' => 99900,
                'term' => '12 months',
                'allowance' => '10 Live Webinars',
                'featured' => true,
                'features' => $features('CEU Credits (All Providers)', 'Dedicated Account Manager', 6),
            ],
        ];
    }
}
