<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mailing list — §5.7's `subscribers`.
 *
 * Kept apart from `inquiries`, which §5.7 also lists and which were folded into
 * one register on 2026-09-24. These genuinely are different: an inquiry is a
 * message expecting a reply, and a subscriber is a standing permission to send
 * email that nobody asked for that day. One is answered and finished with; the
 * other has to be honoured for years and proved on request.
 *
 * **Two decisions here are load-bearing.**
 *
 * *Email is UNIQUE.* The opposite of `inquiries`, deliberately. A second
 * inquiry from one address is a second message and evidence about the first; a
 * second subscriber row is two copies of every campaign landing in one inbox,
 * which is how an address complains. Signing up again updates the row.
 *
 * *There is no soft delete, and unsubscribing is a status.* This table is a
 * suppression list as much as a mailing list: the row recording that somebody
 * refused is the only thing standing between them and the next import putting
 * them straight back on. Deleting an unsubscribed row deletes the refusal. The
 * admin screen offers no delete for ordinary use, and the one that exists for a
 * genuine erasure request says plainly what it costs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscribers', function (Blueprint $table): void {
            $table->id();

            // The identity, and the reason there is no soft delete: a unique
            // index cannot protect somebody whose refusal has been deleted.
            $table->string('email')->unique();

            // A signup box asks for an email. Anything else is optional and
            // most rows will not have it.
            $table->string('name')->nullable();

            $table->string('status', 16)->default('pending')->index();
            $table->string('source', 16);

            /*
             * Consent, in three parts: when they asked, when they proved it was
             * their mailbox, and what we could see of them at the time. This is
             * the evidence a client sells compliance training on the strength
             * of being able to produce.
             */
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('consent_ip', 45)->nullable();
            $table->string('consent_user_agent', 255)->nullable();

            $table->timestamp('unsubscribed_at')->nullable();
            $table->string('unsubscribe_reason')->nullable();

            // Written by the mail provider's webhook when it lands (§11 has
            // Postmark or SES). The columns exist now so that webhook has
            // somewhere to write without a migration behind it.
            $table->timestamp('bounced_at')->nullable();
            $table->string('bounce_reason')->nullable();

            $table->timestamps();

            // The list itself, newest first, and the tabs on the admin screen.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscribers');
    }
};
