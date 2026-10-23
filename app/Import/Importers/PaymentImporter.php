<?php

declare(strict_types=1);

namespace App\Import\Importers;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Import\Importer;
use App\Import\ImportResult;
use App\Import\Legacy;
use App\Import\Maps\LegacyGateway;
use App\Import\Maps\LegacyOrderStatus;
use App\Import\Support\LegacyMoney;
use App\Models\ImportMap;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Support\Carbon;

/**
 * Payments and refunds — Phase 3.
 *
 * One payment per order records the gateway attempt: succeeded where money was
 * taken, failed/cancelled/pending otherwise, so the order's derived status
 * (§14 #2) lines up with its history. The gateway transaction id is the
 * idempotency guard (§5.4); orders without one (a failed attempt) get a stable
 * `wc-order-{id}` reference instead. Each `shop_order_refund` becomes a
 * succeeded refund against that payment, keeping the original amount readable.
 * Runs after OrderImporter.
 */
class PaymentImporter extends Importer
{
    public function label(): string
    {
        return 'Payments';
    }

    public function run(): ImportResult
    {
        $result = new ImportResult;

        $this->importPayments($result);
        $this->importRefunds($result);

        return $result;
    }

    private function importPayments(ImportResult $result): void
    {
        $orders = Legacy::table('posts')
            ->where('post_type', 'shop_order')
            ->whereIn('post_status', ['wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending', 'wc-refunded', 'wc-cancelled', 'wc-failed'])
            ->get();

        foreach ($orders as $post) {
            $id = (int) $post->ID;
            $orderId = ImportMap::localId('order', $id);

            if ($orderId === null) {
                $result->skipped();

                continue;
            }

            $meta = Legacy::postMetaMap($id);
            $txn = trim((string) ($meta['_transaction_id'] ?? ''));

            $this->sync($result, 'payment', $id, Payment::class, function (Payment $payment) use ($post, $meta, $id, $orderId, $txn): void {
                $payment->order_id = $orderId;
                $payment->gateway = LegacyGateway::toEnum((string) ($meta['_payment_method'] ?? ''));
                $payment->gateway_payment_id = $txn !== '' ? $txn : 'wc-order-'.$id;
                $payment->status = $this->paymentStatus((string) $post->post_status);
                $payment->amount_cents = LegacyMoney::toCents($meta['_order_total'] ?? '0');
                $payment->currency = (string) ($meta['_order_currency'] ?? 'USD') ?: 'USD';
                $payment->processed_at = $this->paidAt($meta, (string) $post->post_date);
            });
        }
    }

    private function importRefunds(ImportResult $result): void
    {
        $refunds = Legacy::table('posts')
            ->where('post_type', 'shop_order_refund')
            ->get();

        foreach ($refunds as $post) {
            $id = (int) $post->ID;
            $parentOrderLegacyId = (int) $post->post_parent;
            $paymentId = ImportMap::localId('payment', $parentOrderLegacyId);

            if ($paymentId === null) {
                $result->skipped();

                continue;
            }

            $meta = Legacy::postMetaMap($id);

            $this->sync($result, 'refund', $id, Refund::class, function (Refund $refund) use ($post, $meta, $id, $paymentId): void {
                $refund->payment_id = $paymentId;
                $refund->gateway_refund_id = 'wc-refund-'.$id;
                $refund->status = RefundStatus::Succeeded;
                $refund->amount_cents = LegacyMoney::toCents($meta['_refund_amount'] ?? '0');
                $refund->reason = ($reason = trim(strip_tags((string) ($post->post_excerpt ?? '')))) !== '' ? $reason : null;
                $refund->processed_at = $post->post_date ? Carbon::parse((string) $post->post_date) : null;
            });
        }
    }

    private function paymentStatus(string $wpStatus): PaymentStatus
    {
        return match (LegacyOrderStatus::toEnum($wpStatus)) {
            OrderStatus::Paid, OrderStatus::PartiallyRefunded, OrderStatus::Refunded => PaymentStatus::Succeeded,
            OrderStatus::Pending => PaymentStatus::Pending,
            OrderStatus::Cancelled => PaymentStatus::Cancelled,
            OrderStatus::Failed => PaymentStatus::Failed,
        };
    }

    /** @param  array<string, string>  $meta */
    private function paidAt(array $meta, string $postDate): ?Carbon
    {
        $ts = (int) ($meta['_date_paid'] ?? 0);

        if ($ts > 0) {
            return Carbon::createFromTimestamp($ts);
        }

        return $postDate !== '' ? Carbon::parse($postDate) : null;
    }
}
