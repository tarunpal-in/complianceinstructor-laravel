<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Accreditation;
use Illuminate\Database\Seeder;

/**
 * The four accrediting bodies the business works with — HRCI, SHRM, CPE, APA
 * (CLAUDE.md §1). Reference data, so it is seeded unconditionally rather than
 * inside DemoCatalogueSeeder, which stands aside the moment a real webinar
 * exists — without this the homepage's Accreditation Partners section would fall
 * back to hardcoded placeholders on any site that has imported real content.
 *
 * Keyed on the code and idempotent: safe to run against a live database to
 * populate the bodies, and re-runnable without duplicating. Logos are not
 * seeded (none ship with the repo) — each body's logo is picked in
 * Admin → Accreditations, after which the section shows it in place of the code.
 *
 * `description` is what the homepage renders as the small badge line under each
 * logo, so it is kept short and set-in-caps rather than a full sentence.
 */
class AccreditationSeeder extends Seeder
{
    public function run(): void
    {
        $bodies = [
            ['code' => 'SHRM', 'name' => 'Society for Human Resource Management', 'badge' => "RECERTIFICATION\nPROVIDER"],
            ['code' => 'HRCI', 'name' => 'HR Certification Institute', 'badge' => "RECERTIFICATION\nPROVIDER"],
            ['code' => 'CPE', 'name' => 'Continuing Professional Education', 'badge' => "NATIONAL REGISTRY OF\nCPE SPONSORS"],
            ['code' => 'APA', 'name' => 'American Payroll Association', 'badge' => "APPROVED\nPROVIDER"],
        ];

        foreach ($bodies as $sort => $body) {
            Accreditation::firstOrCreate(
                ['code' => $body['code']],
                [
                    'name' => $body['name'],
                    'description' => $body['badge'],
                    'is_active' => true,
                    'sort_order' => $sort,
                ],
            );
        }
    }
}
