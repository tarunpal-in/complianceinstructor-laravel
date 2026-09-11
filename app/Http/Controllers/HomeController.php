<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MembershipPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The public homepage.
 *
 * Content and data shapes mirror design-ui/homepage-by-bolt/src/App.tsx, which
 * is the authoritative implementation of the Figma comp.
 *
 * CLAUDE.md §4 rule 2 — "Controllers route; Actions do" — so this holds no
 * logic. Every array below is a marked swap point:
 *
 *   $webinars   → Webinar::upcoming()->with('speaker')            (P2)
 *   $categories → Industry::active()->withCount('webinars')       (P2)
 *   $speakers   → Speaker::active()->featured()                   (P2)
 *   $stats      → settings (§5.6), never hardcoded in a template
 *
 * `$plans` has been swapped — it reads `membership_plans` (§5.5).
 *
 * In P6 the page becomes `pages` + `page_sections` rows edited through
 * Filament's Builder (§2), and each section component becomes a block.
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
            'benefits' => $this->benefits(),
            'speakers' => $this->speakers(),
            'partners' => $this->partners(),
            'stories' => $this->stories(),
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

    /** @return array<int, array{number: string, icon: string, title: string, body: string}> */
    private function steps(): array
    {
        return [
            ['number' => '01', 'icon' => 'user-round', 'title' => 'Create Your Free Account', 'body' => 'Sign up in under 60 seconds. No credit card required. Access your learner dashboard immediately.'],
            ['number' => '02', 'icon' => 'monitor', 'title' => 'Explore Courses & Webinars', 'body' => 'Browse hundreds of live and recorded compliance webinars across HR, payroll, manufacturing, and more.'],
            ['number' => '03', 'icon' => 'star', 'title' => 'Enroll & Start Learning', 'body' => 'Attend live or on-demand, earn accredited CEU credits, and receive your certificate of completion.'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function webinars(): array
    {
        // The comp repeats one placeholder card four times, and its date badge
        // (26 AUG) contradicts its own body text (Aug 22). Real dates here; the
        // view derives badge and body from this single Carbon instance.
        $base = Carbon::create(2026, 9, 22, 10, 0);

        return collect([
            ['title' => 'FMLA Compliance 2026: Avoiding Costly Mistakes', 'speaker' => 'Sandra Torres, SHRM–SCP', 'speaker_title' => 'HR Compliance Specialist', 'speaker_initials' => 'ST', 'accreditations' => ['HRCI', 'SHRM']],
            ['title' => 'Payroll Year-End Close: What Changed for 2026', 'speaker' => 'Marcus Reed, CPP', 'speaker_title' => 'Payroll Operations Lead', 'speaker_initials' => 'MR', 'accreditations' => ['CPE', 'APA']],
            ['title' => 'OSHA Recordkeeping for Manufacturing Teams', 'speaker' => 'Dr. Alicia Nwosu', 'speaker_title' => 'EHS Director', 'speaker_initials' => 'AN', 'accreditations' => ['HRCI', 'SHRM']],
            ['title' => 'Wage & Hour Audits: Preparing Before the Letter', 'speaker' => 'David Kaplan, JD', 'speaker_title' => 'Employment Counsel', 'speaker_initials' => 'DK', 'accreditations' => ['HRCI', 'SHRM']],
        ])->map(fn (array $webinar, int $i): array => [
            ...$webinar,
            'starts_at' => $base->copy()->addDays($i * 7),
            'time_label' => '10:00 AM PT / 1:00 PM ET',
            'duration' => '90 Min',
        ])->all();
    }

    /** @return array<int, array{name: string, count: int, image: string}> */
    private function categories(): array
    {
        return [
            ['name' => 'Manufacturing', 'count' => 48, 'image' => 'laptop'],
            ['name' => 'Human Resource', 'count' => 48, 'image' => 'office'],
            ['name' => 'Microsoft Office', 'count' => 48, 'image' => 'seminar'],
        ];
    }

    /** @return array<int, array{icon: string, title: string, body: string}> */
    private function benefits(): array
    {
        return [
            ['icon' => 'users-round', 'title' => 'Expert-Led Online Sessions', 'body' => 'Learn from certified practitioners with 15+ years of real-world compliance experience.'],
            ['icon' => 'monitor', 'title' => 'Flexible Learning Options', 'body' => 'Attend live webinars in real-time or watch recordings on your own schedule — 24/7 access.'],
            ['icon' => 'award', 'title' => 'Career-Boosting Certifications', 'body' => 'Earn HRCI, SHRM, and NASBA/CPE credits that count toward professional recertification.'],
        ];
    }

    /** @return array<int, array{name: string, credentials: string, bodies: string, image: string}> */
    private function speakers(): array
    {
        return [
            ['name' => 'Diane Whitfield', 'credentials' => 'SPHR · Former regional HR director, Fortune 100 retail', 'bodies' => 'HRCI · SHRM', 'image' => 'seminar'],
            ['name' => 'Marcus Reed', 'credentials' => 'CPP · 18 years in multi-state payroll operations', 'bodies' => 'CPE · APA', 'image' => 'office'],
            ['name' => 'Dr. Alicia Nwosu', 'credentials' => 'CSP · EHS director across three manufacturing plants', 'bodies' => 'HRCI', 'image' => 'workshop'],
            ['name' => 'David Kaplan', 'credentials' => 'JD · Employment counsel, wage & hour defence', 'bodies' => 'HRCI · SHRM', 'image' => 'laptop'],
        ];
    }

    /** @return array<int, array{code: string, badge: string, name: string}> */
    private function partners(): array
    {
        return [
            ['code' => 'SHRM', 'badge' => 'RECERTIFICATION<br>PROVIDER', 'name' => 'Society for Human Resource Mgmt'],
            ['code' => 'CPE', 'badge' => 'NATIONAL REGISTRY OF<br>CPE SPONSORS', 'name' => 'Continuing Professional Education'],
            ['code' => 'HRCI', 'badge' => 'RECERTIFICATION<br>PROVIDER', 'name' => 'HR Certification Institute'],
        ];
    }

    /** @return array<int, array{quote: string, name: string, role: string, initials: string}> */
    private function stories(): array
    {
        return [
            [
                'quote' => "Jason's H.O.P.E. framework gave me a concrete language to describe what great leadership looks like. I implemented the Ownership pillar the following week and saw an immediate shift in team accountability.",
                'name' => 'Sandra Mitchell',
                'role' => 'HR Director · BlueCross Health',
                'initials' => 'SM',
            ],
            [
                // The comp cites "Comply Masters" here — another brand entirely.
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
        ];
    }

    /**
     * The membership tiers, from the database — §5.5.
     *
     * The card's quota badge is derived from `plan_entitlements`, so the
     * headline number on the pricing page and the allowance the subscriber
     * actually receives cannot drift apart. The tick-and-cross list underneath
     * is `highlights`, which is copy and enforces nothing.
     *
     * One thing the comp implies that nothing here expresses: the lower tiers
     * are limited to SHRM & HRCI credits while the upper two carry all
     * providers. On the card that is a bullet. If it is meant to be a rule, it
     * needs a real column, and the §17 offering matrix has to say so first.
     *
     * @return Collection<int, MembershipPlan>
     */
    private function plans(): Collection
    {
        return MembershipPlan::query()
            ->active()
            ->with('entitlements')
            ->get();
    }
}
