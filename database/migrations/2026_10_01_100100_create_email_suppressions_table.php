<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses not to send to.
 *
 * Deliberately **not** the same table as `subscribers`, although both suppress.
 * `subscribers` is the marketing list and its status answers "may we market to
 * this person". This answers "is this mailbox worth sending to at all", and the
 * two must never be one switch: somebody who reports a newsletter as spam is
 * still owed the receipt for what they bought, and an address can bounce that was
 * never on the list.
 *
 * **Every row here is entered by a person.** A consumer Gmail account has no
 * webhook and no admin log search, so nothing will ever populate this
 * automatically — a bounce arrives as an email in the sending mailbox and
 * somebody reads it. That is also why the sending address must be one a human
 * opens rather than a `noreply@`: send from nowhere and the only bounce signal
 * available is thrown away.
 *
 * No soft delete, for the reason the subscribers table has none: the row *is*
 * the suppression, and deleting it is how an address gets mailed again by
 * accident. Lifting one is an explicit act with a note on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_suppressions', function (Blueprint $table): void {
            $table->id();

            // One row per address. A second "this bounces" is not new
            // information; it updates the first.
            $table->string('email')->unique();

            $table->string('reason', 16);

            // Where it came from — a bounce message, a phone call, an import.
            $table->string('source')->nullable();
            $table->string('note')->nullable();

            // The send that prompted it, where somebody added it from the log.
            $table->foreignId('email_send_id')->nullable()
                ->constrained('email_sends')->nullOnDelete();

            $table->foreignId('created_by_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // Lifting one is recorded rather than deleting the row, so the
            // history of an address that bounced and came back is still readable.
            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('lifted_reason')->nullable();

            $table->timestamps();

            $table->index(['reason', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
    }
};
