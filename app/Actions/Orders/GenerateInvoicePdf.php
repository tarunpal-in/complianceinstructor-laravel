<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\Order;
use App\Support\Business;
use Illuminate\Contracts\View\View;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Renders an order's invoice — CLAUDE.md §8 (spatie/laravel-pdf, real CSS).
 *
 * Kept in two halves on purpose. `data()` and `view()` build the template from
 * the order's frozen `billing_snapshot` (§5.4 — a historic invoice must never
 * change because the customer later edited their profile) and need nothing but
 * the database, so they are what the tests exercise. `pdf()` wraps that view in
 * Browsershot, which needs headless Chrome and is verified by eye (§10), never
 * in CI.
 *
 * Money is read straight from the order's integer `_cents` columns (§4 rule 6);
 * nothing is recomputed here, so the invoice shows exactly what was charged.
 */
class GenerateInvoicePdf
{
    public function pdf(Order $order): PdfBuilder
    {
        return Pdf::view('pdf.invoice', $this->data($order))
            ->format('a4')
            ->name($this->filename($order));
    }

    public function view(Order $order): View
    {
        return view('pdf.invoice', $this->data($order));
    }

    public function filename(Order $order): string
    {
        return 'invoice-'.$order->number.'.pdf';
    }

    /** @return array<string, mixed> */
    public function data(Order $order): array
    {
        $order->loadMissing(['items.formats']);
        $snapshot = is_array($order->billing_snapshot) ? $order->billing_snapshot : [];

        return [
            'order' => $order,
            'status' => $order->derivedStatus()->label(),
            'billTo' => [
                'name' => $snapshot['name'] ?? $order->customerName(),
                'email' => $snapshot['email'] ?? $order->customerEmail(),
                'job_title' => $snapshot['job_title'] ?? null,
                'company' => $snapshot['company'] ?? null,
                'address_line1' => $snapshot['address_line1'] ?? null,
                'address_line2' => $snapshot['address_line2'] ?? null,
                'city' => $snapshot['city'] ?? null,
                'region' => $snapshot['region'] ?? null,
                'postal_code' => $snapshot['postal_code'] ?? null,
                'country' => $snapshot['country'] ?? null,
            ],
            'businessName' => Business::name(),
            'businessLegalName' => Business::legalName(),
            'businessTagline' => Business::tagline(),
            'businessEmail' => Business::email(),
            'businessPhone' => Business::phone(),
            'businessAddress' => Business::addressLines(),
        ];
    }
}
