<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every email the application has tried to send.
 *
 * §8 asks for a send log; the reason it matters more than that sounds is §14 #6.
 * The predecessor's worst defect was a customer who paid, closed the tab and
 * received nothing — "with no record written". A row here is written *before*
 * anything is handed to a mail server, so a send that fails, defers, or is
 * refused still leaves evidence of itself. Silence that looks like success is
 * the failure mode this table exists to remove.
 *
 * **One send is one row, and a resend is a new row.** Append-only, like
 * `entitlements` in §5.2. Overwriting the original on a resend would erase the
 * failure that prompted it, which is the one fact anybody came here to find.
 *
 * Three columns need their reasoning kept close:
 *
 * `message_id` — the only thread back to a bounce. The client sends through a
 * consumer Gmail account: no webhook, no admin log search, nothing that will
 * ever tell us a message bounced. A bounce arrives as an email in the sending
 * mailbox quoting this id, and this is how somebody matches it to the row.
 *
 * `body_html` is frozen and then pruned. Frozen because a resend of a
 * two-month-old receipt must not quietly use a template edited since (the
 * `billing_snapshot` rule from §5.4). Pruned because at a realistic 40 KB a
 * send this is gigabytes a year inside a database that is dumped nightly with
 * thirty days of retention — and the backup, not the table, is what pays.
 *
 * `related_type`/`related_id` is what makes the log usable from the other end.
 * "Did this customer get their receipt?" is asked while looking at the order,
 * not while looking at a list of emails.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_sends', function (Blueprint $table): void {
            $table->id();

            // Public identifier. §5.1 puts UUIDs exactly where a value leaves
            // the system, and this one reaches a mail header.
            $table->uuid('uuid')->unique();

            // The EmailCatalogue key, e.g. "purchase.refund_issued". A string
            // rather than a foreign key: the catalogue is code, and a row must
            // outlive an email being retired from it.
            $table->string('email_key', 64);
            $table->string('category', 24);

            // The class that built it, and what it needs to be rebuilt. Without
            // the payload a resend could only replay stored HTML, which is wrong
            // for anything holding a link that expires.
            $table->string('mailable');
            $table->json('payload')->nullable();

            $table->string('to_email');
            $table->string('to_name')->nullable();
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->string('reply_to')->nullable();

            $table->string('subject');
            $table->longText('body_html')->nullable();
            // A fact rather than an inference from a null body: "we never stored
            // it" and "we no longer keep it" are different answers, and a screen
            // that cannot tell them apart teaches people to distrust it.
            $table->timestamp('body_pruned_at')->nullable();

            // Who it went to, where we know. Both nullable — an invitation can
            // precede the account, and an address need not be on the list.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscriber_id')->nullable()->constrained()->nullOnDelete();

            $table->nullableMorphs('related');

            $table->string('status', 16)->default('queued');

            // Which mail config sent it. Survives a change of SMTP host, so a
            // delivery problem can be pinned to the period it started in.
            $table->string('mailer', 32)->nullable();
            $table->string('message_id')->nullable();

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);

            // Why a row was not sent — switched off, suppressed, halted. The
            // answer to "why didn't they get it", which is the question.
            $table->string('outcome_note')->nullable();

            $table->foreignId('resent_from_id')->nullable()
                ->constrained('email_sends')->nullOnDelete();
            $table->foreignId('resent_by_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The log, newest first, and the tabs on it.
            $table->index(['status', 'id']);
            // "What have we sent this person?" — asked by a support agent with
            // an address and nothing else.
            $table->index(['to_email', 'id']);
            // The 30-day counts on the Emails screen.
            $table->index(['email_key', 'created_at']);
            // The prune sweep, which walks by age within a category.
            $table->index(['category', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_sends');
    }
};
