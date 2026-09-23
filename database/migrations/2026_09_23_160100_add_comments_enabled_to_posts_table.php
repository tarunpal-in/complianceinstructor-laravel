<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an article takes comments.
 *
 * Per article rather than site-wide, because the answer differs: a practical
 * guide benefits from readers adding cases, and a post about a contested
 * regulatory change is a moderation problem nobody asked for. On by default,
 * so the client turns it off where they mean to rather than on everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->boolean('comments_enabled')->default(true)->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropColumn('comments_enabled');
        });
    }
};
