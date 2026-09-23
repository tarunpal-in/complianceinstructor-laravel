<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blog comments.
 *
 * New scope: not in §6's module list or §7's phases. The nearest thing the plan
 * has is the forum, which §13 cut first for "lowest commercial value, highest
 * moderation burden" — and a comment box carries a smaller version of exactly
 * that burden, every day, forever. Built at the client's request on 2026-09-23
 * with that said plainly.
 *
 * Everything the moderation screen needs to judge a comment is stored with it.
 * The author's name and email are copied rather than joined to an account,
 * because most commenters will not have one — and where they do, the account
 * being renamed later must not rewrite what was posted, for the same reason an
 * order freezes its billing snapshot.
 *
 * The IP and user agent are kept because they are what a moderator uses to
 * recognise the fourth comment from the same source this morning. §9 makes that
 * personal data with a retention question attached, which belongs in the
 * client's privacy policy rather than being decided here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('post_id')->constrained()->cascadeOnDelete();

            /*
             * One level of replies. A moderator answering a commenter in public
             * is the thing a blog actually needs; arbitrarily deep threading is
             * a forum, and the forum was cut.
             */
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();

            // Set where the commenter was signed in, so a member's comment can
            // be traced to their account. Null for a guest, which is most.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('author_name');
            $table->string('author_email');
            $table->text('body');

            $table->string('status', 16)->default('pending')->index();

            // Why the filter thought it was spam, for a moderator overruling it.
            $table->string('spam_reason')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->timestamp('moderated_at')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // The article's own comment list, and the moderation queue.
            $table->index(['post_id', 'status']);
            $table->index(['status', 'created_at']);

            // "Has this person commented before", which decides whether their
            // next one waits in the queue.
            $table->index('author_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
