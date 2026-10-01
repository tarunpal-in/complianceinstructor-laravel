<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Email\Dispatcher;
use App\Enums\EmailStatus;
use App\Models\EmailSend;
use Illuminate\Console\Command;

/**
 * Sends the emails that were waiting for allowance.
 *
 * A consumer Gmail account allows roughly 500 messages in a rolling 24 hours, so
 * a busy day pushes the tail of a reminder blast into tomorrow. The alternative
 * to this command is dropping those emails, which would mean a customer silently
 * not being reminded about a session they paid for.
 *
 * Transactional first, deliberately. If the allowance frees up for twenty
 * messages, a password reset should be among them rather than behind three
 * hundred queued reminders.
 */
class ReleaseDeferredEmails extends Command
{
    protected $signature = 'emails:release-deferred {--limit=100 : How many to attempt in one pass}';

    protected $description = 'Queue emails that were held back when the sending allowance was spent';

    public function handle(Dispatcher $dispatcher): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $released = 0;
        $stopped = 0;

        $waiting = EmailSend::query()
            ->where('status', EmailStatus::Deferred)
            // Transactional before bulk. `email-bulk` sorts after
            // `email-transactional`, so the plain ordering is the right one.
            ->orderByRaw('CASE WHEN category IN (?, ?, ?) THEN 1 ELSE 0 END', [
                'reminder', 'delivery', 'marketing',
            ])
            ->oldest('id')
            ->limit($limit)
            ->get();

        foreach ($waiting as $send) {
            if ($dispatcher->release($send)) {
                $released++;

                continue;
            }

            // Either the allowance is spent again or the record it described has
            // gone. Both are reasons to stop walking the list: the first means
            // every remaining row would be refused too.
            $stopped++;

            if ($send->status === EmailStatus::Deferred) {
                break;
            }
        }

        $this->info("{$released} released, {$stopped} left waiting or closed.");

        return self::SUCCESS;
    }
}
