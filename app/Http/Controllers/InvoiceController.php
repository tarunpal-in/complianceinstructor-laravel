<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Orders\GenerateInvoicePdf;
use App\Models\Order;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Serves an order's invoice PDF — §8 / §9.
 *
 * The route is signed and expiring, which is the whole access control: a guest
 * buyer has no account to authenticate against, so possession of an unexpired
 * link — handed out only by the order-received page and the confirmation email —
 * is what §9 means by "files served through signed, expiring URLs". The link
 * carries the order id, so it grants that one invoice and no other, which closes
 * the IDOR the predecessor had on every invoice (§9 / §14 #10).
 */
class InvoiceController extends Controller
{
    public function __construct(private readonly GenerateInvoicePdf $invoices) {}

    public function download(Order $order): PdfBuilder
    {
        return $this->invoices->pdf($order)
            ->download($this->invoices->filename($order));
    }
}
