<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-page SEO overrides — CLAUDE.md §5.6, and the client's SEO brief §1/§3.
 *
 * One polymorphic row per content record (webinar, article, speaker, page,
 * category) carrying what the SEO team edits from the CMS: the title and
 * description search engines show, a canonical override, and whether the page
 * may be indexed. §5.6 replaces "16 hardcoded page types" with this one table.
 *
 * **Every column is an override, and every one is nullable on purpose.** A page
 * with no row, or a row with blank fields, falls back to sensible values
 * computed from the content itself (see App\Seo\Seo). The SEO team fills a field
 * only when the default is not good enough, which is how "no developer for
 * routine SEO changes" — the brief's Main Goal — actually works.
 *
 * `keywords` holds the **focus keyword**, and the brief is emphatic about it:
 * it is for internal SEO tracking and **must never render a
 * `<meta name="keywords">` tag**. Google has ignored that tag since 2009 and
 * treats a stuffed one as a spam signal. The column exists; nothing reads it
 * into the page head, and a test proves that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_meta', function (Blueprint $table): void {
            $table->id();

            // The content record this describes. Indexed as a pair so the
            // lookup from a page to its SEO row is one indexed hit.
            $table->morphs('seoable');

            // What search results show. Null means "work it out from the title
            // and summary", which is the common case.
            $table->string('title')->nullable();
            $table->text('description')->nullable();

            // The focus keyword — internal only. See the class note: this never
            // becomes a meta tag.
            $table->string('keywords')->nullable();

            // A chosen social-share image, overriding the content's own. A plain
            // id rather than a foreign key, matching how settings hold brand
            // media: the media library manages its own rows, and a hard
            // constraint here would couple this table to its churn.
            $table->unsignedBigInteger('og_image_media_id')->nullable();

            // A manual canonical, for the rare page that should point elsewhere
            // (a syndicated article, a duplicate landing). Blank means the page
            // is its own canonical, which is almost always right.
            $table->string('canonical')->nullable();

            // The robots directive, e.g. "noindex, follow". Null renders the
            // site default of "index, follow".
            $table->string('robots', 64)->nullable();

            $table->timestamps();

            // One SEO row per record. A second would be ambiguous — which title
            // wins? — so the database refuses it rather than the code guessing.
            $table->unique(['seoable_type', 'seoable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_meta');
    }
};
