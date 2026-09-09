<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Payments\SendRefundToGateway;
use App\Models\Refund;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends every refund whose cooling-off window has closed.
 *
 * A swept queue rather than a delayed job, deliberately. A delayed job is lost
 * if the queue is flushed, silently ignored when the connection is `sync`, and
 * invisible until someone asks why a refund never went. A row with a timestamp
 * is none of those things: the sweep is idempotent, restartable, and the
 * pending work is a query anyone can run.
 */
class ReleaseDueRefunds extends Command
{
    protected $signature = 'refunds:release-due';

    protected $description = 'Send refunds whose cooling-off window has passed to their gateway';

    public function handle(SendRefundToGateway $send): int
    {
        $due = Refund::query()->due()->with('payment.order')->get();

        if ($due->isEmpty()) {
            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        /** @var Collection<int, Refund> $due */
        foreach ($due as $refund) {
            try {
                $result = $send($refund);

                $result->status->holdsFunds() ? $sent++ : $failed++;
            } catch (Throwable $e) {
                // One gateway being unreachable must not stop the rest going
                // out. The refund stays scheduled and the next sweep retries it.
                $failed++;

                Log::error('Releasing a refund failed.', [
                    'refund_id' => $refund->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->components->info("Released {$sent} refund(s); {$failed} did not go through.");

        return self::SUCCESS;
    }
}
