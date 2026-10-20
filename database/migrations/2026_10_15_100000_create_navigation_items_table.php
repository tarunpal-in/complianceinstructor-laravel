<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CLAUDE.md §5.6 — `navigation_items`. The header and footer menus, editable in
 * the admin navigation manager rather than hardcoded in the navbar and footer.
 *
 * One table, one level of nesting: `menu` separates header from footer, and
 * `parent_id` makes a header dropdown or a footer column. A parent may carry no
 * URL (a dropdown trigger or a column heading), so `url` is nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('navigation_items', function (Blueprint $table): void {
            $table->id();
            $table->string('menu')->index();
            $table->foreignId('parent_id')->nullable()->constrained('navigation_items')->cascadeOnDelete();
            $table->string('label');
            $table->string('url')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('open_in_new_tab')->default(false);
            $table->timestamps();

            $table->index(['menu', 'parent_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('navigation_items');
    }
};
