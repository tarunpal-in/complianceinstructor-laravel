<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Format;
use App\Enums\WebinarStatus;
use App\Enums\WebinarType;
use App\Models\Accreditation;
use App\Models\Industry;
use App\Models\Redirect;
use App\Models\Speaker;
use App\Models\Webinar;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Imports one real catalogue webinar from the live WooCommerce site —
 * "2026 Form 1099 Reporting: New Rules, Thresholds & Key Updates".
 *
 * Content, speaker and the eight purchase options are taken verbatim from
 * complianceinstructor.com/product/2026-form-1099-reporting-…. The live page
 * carries no accreditation line, so the CPE/1.5-credit award here was confirmed
 * with the client (a 90-minute tax topic = 1.5 credits under CreditHours) rather
 * than guessed — §1/§12 of the risk register treats credit awarding as
 * compliance, not preference.
 *
 * Keyed on the slug and fully re-runnable: seeding twice leaves exactly one of
 * each row. Run with `php artisan db:seed --class=Form1099WebinarSeeder`.
 */
class Form1099WebinarSeeder extends Seeder
{
    /** The live product slug, reused so the public URL mirrors the old one. */
    private const SLUG = '2026-form-1099-reporting-new-rules-thresholds-key-updates';

    public function run(): void
    {
        $cpe = Accreditation::firstOrCreate(
            ['code' => 'CPE'],
            [
                'name' => 'Continuing Professional Education',
                'description' => 'Continuing professional education credit for accounting and tax professionals.',
                'is_active' => true,
            ],
        );

        $industry = Industry::firstOrCreate(
            ['slug' => 'human-resources'],
            ['name' => 'Human Resources', 'is_active' => true],
        );

        $speaker = Speaker::firstOrCreate(
            ['slug' => 'jason-dinesen'],
            [
                'name' => 'Jason Dinesen',
                'title' => 'President, Dinesen Tax & Accounting, P.C.',
                'credentials' => 'LPA, EA',
                'bio' => 'Jason Dinesen is the President of Dinesen Tax & Accounting, P.C., a public '
                    .'accounting firm in Indianola, Iowa, focused on tax and accounting for small '
                    ."businesses and individuals.\n\nWith extensive experience — including work with a "
                    .'third-party administrator of retirement plans — and many 1099 seminars delivered, '
                    .'he is known nationwide as "The 1099 Guy."',
                'is_active' => true,
            ],
        );

        $webinar = Webinar::updateOrCreate(
            ['slug' => self::SLUG],
            [
                'type' => WebinarType::Live,
                'status' => WebinarStatus::Scheduled,
                'title' => '2026 Form 1099 Reporting: New Rules, Thresholds & Key Updates',
                'summary' => 'A practical, up-to-date guide to Form 1099 compliance for 2026 — due '
                    .'diligence, backup withholding, missing SSNs, and preparing for IRS scrutiny and '
                    .'the changes under the One Big Beautiful Bill.',
                'description' => 'Live, expert-led training on Form 1099 reporting for 2026 — covering '
                    .'1099-NEC, 1099-MISC and 1099-K, backup withholding, due diligence, and the '
                    .'proposed changes to the $600 threshold.',
                'attendee_instructions' => 'If you have enrolled in the Live Webinar, you will receive your '
                    .'instruction kit at least 24 hours before the live class. If you signed up for the '
                    .'Recorded or E-Transcript option, you will get access to the recording link or the PDF '
                    .'within 24–48 hours of the live class.',
                'speaker_id' => $speaker->id,
                'industry_id' => $industry->id,
                // 10:00 AM PT on the live page. Stored in UTC so it displays
                // correctly whatever timezone the viewer's hero renders in.
                'scheduled_at' => Carbon::parse('2026-11-16 10:00:00', 'America/Los_Angeles')->utc(),
                'timezone' => 'America/Los_Angeles',
                'duration_minutes' => 90,
                'seats_total' => null,
            ],
        );

        // Content sections — delete-and-recreate keeps the seeder idempotent, the
        // same way the webinar importer rebuilds them from the legacy blob.
        $webinar->sections()->delete();
        $webinar->sections()->createMany([
            [
                'heading' => 'Webinar Overview',
                'body' => 'This live session goes beyond the basics of Form 1099 reporting into the '
                    .'areas that trip filers up: due diligence, backup withholding, and missing SSNs. Led '
                    .'by Jason Dinesen — known nationwide as "The 1099 Guy" — it covers Form 1099-NEC for '
                    .'independent contractors, Form 1099-MISC for payments such as rent, and Form 1099-K, '
                    ."including payments made through apps like PayPal and Venmo.\n\nWith IRS penalties for "
                    .'incorrect or unfiled 1099s rising and enforcement of backup withholding increasing, '
                    .'this session is built for AP professionals, accountants, and small business owners '
                    .'who need to get it right. A live Q&A follows, where Jason answers your specific '
                    .'reporting questions.',
                'sort_order' => 0,
            ],
            [
                'heading' => 'Session Highlights',
                'body' => implode("\n\n", [
                    '• Determining when a payment is reportable on a 1099, and who must issue it',
                    '• Due diligence for collecting and keeping Form W-9s — even when reporting isn\'t initially required',
                    '• When backup withholding is mandatory under IRC § 3406, and how to calculate, deposit, and report it',
                    '• Handling CP-2100 letters and B Notices, including the difference between first and second notices',
                    '• Avoiding common pitfalls — missing TINs, late filings, improper e-filing — and building a reasonable-cause defense if penalties are assessed',
                    '• Preparing for changes under the "One Big Beautiful Bill," including the possible end of the $600 reporting threshold and updates to IRIS and FIRE',
                    '• Credit card and payment-app reporting (e.g. Venmo), the e-file mandate, corporate filing, Form W-9, and backup withholding',
                ]),
                'sort_order' => 1,
            ],
            [
                'heading' => 'Why You Should Attend',
                'body' => 'The One Big Beautiful Bill may significantly change Form 1099 reporting — '
                    .'including the long-standing $600 threshold. This session helps businesses, freelancers, '
                    .'and tax professionals understand what is changing and prepare for the new requirements '
                    .'before filing season, rather than scrambling after a notice arrives.',
                'sort_order' => 2,
            ],
            [
                'heading' => 'Who Should Attend',
                'body' => implode("\n\n", [
                    'This session is designed for:',
                    '• Accountants and Bookkeepers',
                    '• Office Managers',
                    '• Human Resources Professionals',
                    '• Compliance Professionals',
                    '• Business Owners',
                ]),
                'sort_order' => 3,
            ],
        ]);

        // 1.5 CPE credits — 90 minutes under the CreditHours rule. sync() sets the
        // pivot idempotently, so a re-run never stacks a second row.
        $webinar->accreditations()->sync([$cpe->id => ['credits' => 1.50]]);

        $this->syncOfferings($webinar);

        // Legacy /product/… URL from the WordPress site → the new /webinars/…
        // path, so inbound links and search results survive the migration.
        Redirect::updateOrCreate(
            ['from_path' => Redirect::normalise('/product/'.self::SLUG)],
            [
                'to_path' => Redirect::normaliseTarget('/webinars/'.self::SLUG),
                'status_code' => 301,
                'is_active' => true,
                'source' => Redirect::SOURCE_IMPORT,
            ],
        );
    }

    /**
     * The eight purchase options from the live product page, at their real
     * prices. Combined formats are one offering carrying several format rows —
     * the whole reason formats live on a pivot (§5.3) — not extra enum cases.
     */
    private function syncOfferings(Webinar $webinar): void
    {
        // Clear any prior import so re-running leaves exactly these eight.
        $webinar->offerings()->withTrashed()->get()->each(function ($offering): void {
            $offering->formats()->delete();
            $offering->forceDelete();
        });

        /** @var array<int, array{formats: array<int, Format>, cents: int, sort: int}> $options */
        $options = [
            ['formats' => [Format::Live], 'cents' => 17900, 'sort' => 10],
            ['formats' => [Format::Recorded], 'cents' => 18900, 'sort' => 20],
            ['formats' => [Format::Transcript], 'cents' => 18900, 'sort' => 30],
            ['formats' => [Format::Live, Format::Recorded], 'cents' => 19900, 'sort' => 40],
            ['formats' => [Format::Recorded, Format::Transcript], 'cents' => 19900, 'sort' => 50],
            ['formats' => [Format::Live, Format::Transcript], 'cents' => 19900, 'sort' => 60],
            ['formats' => [Format::Live, Format::Recorded, Format::Transcript], 'cents' => 22900, 'sort' => 70],
            ['formats' => [Format::Download], 'cents' => 19900, 'sort' => 80],
        ];

        foreach ($options as $option) {
            $sku = 'F1099-2026-'.implode('-', array_map(
                static fn (Format $format): string => strtoupper($format->value),
                $option['formats'],
            ));

            $offering = $webinar->offerings()->create([
                'sku' => $sku,
                'price_cents' => $option['cents'],
                'currency' => 'USD',
                'seats_per_unit' => 1,
                'is_active' => true,
                'sort_order' => $option['sort'],
            ]);

            foreach ($option['formats'] as $format) {
                $offering->formats()->create(['format' => $format]);
            }
        }
    }
}
