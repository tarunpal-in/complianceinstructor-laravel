<?php

declare(strict_types=1);

namespace App\Actions\Certificates;

use App\Models\Certificate;
use App\Support\Business;
use Illuminate\Contracts\View\View;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Renders a CEU certificate — CLAUDE.md §8 improvement #4 (spatie/laravel-pdf,
 * real CSS).
 *
 * Split the same way as GenerateInvoicePdf. `data()` and `view()` build the
 * document from the certificate's frozen columns (§5.8 — what it said at issue,
 * not what the webinar is retitled to later) and need only the database, so they
 * are what the tests exercise. `pdf()` wraps that view in Browsershot, which
 * needs headless Chrome and is verified by eye (§10), never in CI.
 *
 * Generated on demand from the stored record, like the invoice — the record,
 * serial and register are the durable artefact; the PDF is a rendering of them.
 */
class GenerateCertificatePdf
{
    public function pdf(Certificate $certificate): PdfBuilder
    {
        return Pdf::view('pdf.certificate', $this->data($certificate))
            ->format('a4')
            ->landscape()
            ->name($this->filename($certificate));
    }

    public function view(Certificate $certificate): View
    {
        return view('pdf.certificate', $this->data($certificate));
    }

    public function filename(Certificate $certificate): string
    {
        return 'certificate-'.$certificate->serial.'.pdf';
    }

    /** @return array<string, mixed> */
    public function data(Certificate $certificate): array
    {
        return [
            'certificate' => $certificate,
            'learnerName' => $certificate->learner_name,
            'webinarTitle' => $certificate->webinar_title,
            'credits' => $certificate->describeCredits(),
            'accreditationCode' => $certificate->accreditation_code,
            'issuedAt' => $certificate->issued_at,
            'serial' => $certificate->serial,
            'businessName' => Business::name(),
            'businessLegalName' => Business::legalName(),
            'businessTagline' => Business::tagline(),
        ];
    }
}
