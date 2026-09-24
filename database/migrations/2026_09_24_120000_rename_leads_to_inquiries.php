<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * `leads` becomes `inquiries`.
 *
 * The register was built as Leads on 2026-09-24 and renamed the same day, once
 * it was clear the client's intake is the website contact form and that the
 * Inbox wanted one screen rather than two. §5.7 lists `leads` and `inquiries`
 * separately, and in practice the distinction is thin: an inquiry is somebody
 * asking a question and a lead is somebody worth ringing back, and on a site
 * selling invoiced team seats they are usually the same message. One register,
 * with `source` recording how they arrived, says everything two tables would.
 *
 * A rename rather than an edit to the create migration. The table had already
 * been migrated into a working database, and rewriting history would have meant
 * rebuilding it — which is a needless thing to ask of anybody, and the
 * migration that created `leads` is a true record of what happened.
 *
 * Nothing references this table by foreign key, so the rename is the whole
 * change. Index names carry the old prefix; leaving them is deliberate, since
 * renaming an index is a second migration's worth of risk for a string only
 * ever read by somebody running EXPLAIN.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leads') && ! Schema::hasTable('inquiries')) {
            Schema::rename('leads', 'inquiries');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('inquiries') && ! Schema::hasTable('leads')) {
            Schema::rename('inquiries', 'leads');
        }
    }
};
