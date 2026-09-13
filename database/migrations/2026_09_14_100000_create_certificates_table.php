<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CEU certificates — CLAUDE.md §5.8 and §8 improvement #4.
 *
 * The credits are what customers are actually buying (§1), which makes this the
 * most consequential document the platform produces: an accrediting body may
 * audit it years later, and the learner's professional certification depends on
 * it holding up.
 *
 * So it is **frozen at issue**. §5.4 already says historic invoices must never
 * mutate; a certificate is a stronger case of the same rule. The learner's name,
 * the webinar title, the accrediting body's code and the credit hours are all
 * copied onto the row rather than read through a relation — rename a webinar,
 * correct a learner's name or restate a body's credits next year and every
 * certificate already in an auditor's hands still says what it said on the day.
 * The foreign keys stay for navigation; the snapshot is what the document is.
 *
 * `pdf_path` is nullable and stays empty for now. §8 wants a branded PDF on a
 * private disk, which needs headless Chrome (§11) — the record and the serial
 * come first, and a certificate that exists without its PDF is recoverable,
 * where a PDF without a record is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accreditation_id')->constrained()->cascadeOnDelete();

            /*
             * §5.1 — UUIDs only where a value is public, and this is one of the
             * two named cases. Not sequential: a verification URL that can be
             * walked from CI-0001 to CI-0002 hands anybody the whole register
             * of who has been certified in what.
             */
            $table->string('serial', 32)->unique();

            // What the document says, as it said it.
            $table->string('learner_name');
            $table->string('webinar_title');
            $table->string('accreditation_code', 16);
            $table->decimal('credits', 4, 2);

            $table->timestamp('issued_at');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();

            // §8 — "Admin can revoke and reissue". A revoked certificate stays
            // on the record: an auditor asking about a serial must be told it
            // was withdrawn, not that it never existed.
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoked_reason')->nullable();

            $table->string('pdf_path')->nullable();

            $table->timestamps();

            // "What has this learner earned" and "who holds a certificate for
            // this webinar" — the two lists anybody actually asks for.
            $table->index(['user_id', 'issued_at']);
            $table->index(['webinar_id', 'accreditation_id']);

            /*
             * Deliberately no UNIQUE on (user, webinar, accreditation). One
             * person must not hold two *live* certificates for the same webinar
             * from the same body — but reissuing after a revocation needs a
             * second row, and a database constraint cannot tell the difference.
             * IssueCertificate guards the live case, the same way RequestRefund
             * guards one refund in flight per order.
             */
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
