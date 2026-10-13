<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\WebinarStatus;
use App\Enums\WebinarType;
use App\Models\Accreditation;
use App\Models\Industry;
use App\Models\Offering;
use App\Models\Speaker;
use App\Models\Webinar;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * A small, realistic catalogue for the seeded demo — CLAUDE.md §4 rule 1 / §5.9.
 *
 * The homepage's live sections (upcoming webinars, categories, faculty) read the
 * catalogue, so without one the review environment's front page would be half
 * empty. This seeds enough speakers, industries and upcoming webinars — each
 * valid and publishable (speaker, accreditation with credits, active offerings)
 * — to populate those sections and give OrderSeeder something to sell.
 *
 * A placeholder of the real catalogue the client builds in the admin, not a
 * substitute for it: it stands aside the moment any real webinar exists, so
 * importing one (as with the Form 1099 webinar) does not stack demo rows beneath
 * it.
 */
class DemoCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        // Stand aside once there is a real catalogue — the client's own webinars
        // are the point, and these are only scaffolding until then.
        if (Webinar::query()->exists()) {
            return;
        }

        $credits = $this->accreditations();

        $industries = collect([
            ['Human Resources', 'Employment law, benefits and workforce compliance.'],
            ['Payroll & Tax', 'Multi-state payroll, withholding and year-end.'],
            ['Workplace Safety', 'OSHA recordkeeping, EHS and incident response.'],
            ['Finance & Accounting', 'Controls, reporting and 1099 compliance.'],
        ])->map(fn (array $row, int $i): Industry => Industry::firstOrCreate(
            ['slug' => \Illuminate\Support\Str::slug($row[0])],
            ['name' => $row[0], 'description' => $row[1], 'is_active' => true, 'sort_order' => $i],
        ));

        $speakers = collect([
            ['Diane Whitfield', 'Regional HR Director', 'SPHR · SHRM-SCP', ['FMLA & Leave', 'Employee Relations', 'HR Compliance']],
            ['Marcus Reed', 'Payroll Operations Lead', 'CPP · CPA', ['Multi-State Payroll', 'Withholding', 'Year-End Close']],
            ['Dr. Alicia Nwosu', 'EHS Director', 'CSP', ['OSHA Safety', 'Incident Response', 'EHS Programs']],
            ['David Kaplan', 'Employment Counsel', 'JD · SHRM-SCP', ['Wage & Hour', 'Workplace Investigations', 'Employment Law']],
            ['Priya Anand', 'Benefits Consultant', 'PHR · SHRM-CP', ['Benefits', 'ACA Compliance', 'Open Enrollment']],
            ['Thomas Byrne', 'Tax & Accounting Principal', 'CPA · EA', ['1099 Reporting', 'Backup Withholding', 'IRS Compliance']],
        ])->map(fn (array $row, int $i): Speaker => Speaker::factory()->create([
            'name' => $row[0],
            'title' => $row[1],
            'credentials' => $row[2],
            'expertise' => $row[3],
            'is_active' => true,
            // Hand-entered marketing figures, as the homepage stat band is.
            'rating' => [4.9, 4.8, 4.7, 4.8, 4.6, 4.9][$i],
            'reviews_count' => [980, 742, 410, 655, 288, 834][$i],
            'years_teaching' => [18, 14, 12, 16, 9, 20][$i],
        ]));

        $titles = [
            'FMLA Compliance 2027: Avoiding Costly Mistakes',
            'Multi-State Payroll: Withholding Done Right',
            'OSHA Recordkeeping for Manufacturing Teams',
            'Wage & Hour Audits: Preparing Before the Letter',
            '1099 Reporting: New Thresholds and Rules',
            'Form I-9 and E-Verify: Getting It Right',
            'Year-End Close: What Changed This Year',
            'Workplace Investigations That Hold Up',
        ];

        foreach ($titles as $i => $title) {
            $this->webinar(
                $title,
                $speakers[$i % $speakers->count()],
                $industries[$i % $industries->count()],
                // A spread of upcoming dates, weekly from next week.
                Carbon::now()->addWeeks($i + 1)->setTime(10, 0),
                $credits,
            );
        }
    }

    /**
     * One publishable, upcoming webinar with its offerings and credits.
     *
     * @param  \Illuminate\Support\Collection<string, Accreditation>  $credits
     */
    private function webinar(string $title, Speaker $speaker, Industry $industry, Carbon $when, \Illuminate\Support\Collection $credits): void
    {
        $webinar = Webinar::factory()->create([
            'type' => WebinarType::Live,
            'status' => WebinarStatus::Scheduled,
            'title' => $title,
            'slug' => \Illuminate\Support\Str::slug($title),
            'speaker_id' => $speaker->id,
            'industry_id' => $industry->id,
            'scheduled_at' => $when,
            'duration_minutes' => 90,
        ]);

        // Two bodies at 1.5 credits each (90 minutes) — enough to be publishable.
        $webinar->accreditations()->sync([
            $credits['HRCI']->id => ['credits' => 1.50],
            $credits['SHRM']->id => ['credits' => 1.50],
        ]);

        // The usual spread of purchase options, from the factory's panel states.
        Offering::factory()->live()->create($this->owned($webinar));
        Offering::factory()->recorded()->create($this->owned($webinar));
        Offering::factory()->transcript()->create($this->owned($webinar));
        Offering::factory()->everything()->create($this->owned($webinar));
    }

    /**
     * The polymorphic owner columns for an offering on a webinar.
     *
     * @return array<string, mixed>
     */
    private function owned(Webinar $webinar): array
    {
        return ['offerable_type' => Webinar::class, 'offerable_id' => $webinar->id];
    }

    /**
     * The four accrediting bodies, kept by code so the demo shares them with any
     * real webinar imported later.
     *
     * @return \Illuminate\Support\Collection<string, Accreditation>
     */
    private function accreditations(): \Illuminate\Support\Collection
    {
        return collect(['HRCI', 'SHRM', 'CPE', 'APA'])
            ->mapWithKeys(fn (string $code): array => [
                $code => Accreditation::firstOrCreate(
                    ['code' => $code],
                    ['name' => $code.' recertification', 'is_active' => true],
                ),
            ]);
    }
}
