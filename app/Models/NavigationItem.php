<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NavigationMenu;
use Database\Factories\NavigationItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One entry in the header or footer menu — CLAUDE.md §5.6.
 *
 * A top-level row (`parent_id` null) is a header link/dropdown trigger or a
 * footer column heading; its children are the dropdown items or the column's
 * links. Ordered by `sort_order` within its menu and parent.
 */
class NavigationItem extends Model
{
    /** @use HasFactory<NavigationItemFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'menu', 'parent_id', 'label', 'url', 'sort_order', 'is_active', 'open_in_new_tab',
    ];

    protected function casts(): array
    {
        return [
            'menu' => NavigationMenu::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'open_in_new_tab' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['menu', 'parent_id', 'label', 'url', 'sort_order', 'is_active', 'open_in_new_tab'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<NavigationItem, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<NavigationItem, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    /** @param  Builder<NavigationItem>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param  Builder<NavigationItem>  $query */
    public function scopeForMenu(Builder $query, NavigationMenu $menu): void
    {
        $query->where('menu', $menu->value);
    }

    /** @param  Builder<NavigationItem>  $query */
    public function scopeTopLevel(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    public function hasChildren(): bool
    {
        return $this->children->isNotEmpty();
    }
}
