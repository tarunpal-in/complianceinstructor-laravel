<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\PermissionCatalogue;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * An admin role — CLAUDE.md §8 improvement #3.
 *
 * @property bool $is_system
 * @property string|null $description
 *
 * Extends spatie's so the four seeded roles can be told apart from any the
 * client creates. `is_system` is the whole distinction: RolePermissionSeeder
 * owns the permissions on a system role and syncs them on every deploy, so
 * editing one in the admin would be reverted without anybody noticing. Custom
 * roles are the client's and the seeder never touches them.
 */
class Role extends SpatieRole
{
    protected $fillable = ['name', 'guard_name', 'is_system', 'description'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    /**
     * Whether this role's permissions are owned by the seeder.
     *
     * The admin shows a system role in full and refuses to change it — hiding
     * it would leave "why can Sarah not see refunds" unanswerable, which is
     * half the reason this screen exists.
     */
    public function isSystem(): bool
    {
        return (bool) $this->is_system;
    }

    /** How this role reads, rather than `content_editor`. */
    public function displayName(): string
    {
        return \App\Enums\UserRole::tryFrom($this->name)?->label()
            ?? str($this->name)->replace('_', ' ')->title()->value();
    }

    /**
     * Its permissions, grouped the way the panel is grouped.
     *
     * @return Collection<string, Collection<int, string>>
     */
    public function permissionsByGroup(): Collection
    {
        $held = $this->permissions->pluck('name')->all();

        return collect(PermissionCatalogue::grouped())
            ->map(fn (array $permissions): Collection => collect(array_keys($permissions))
                ->filter(fn (string $permission): bool => in_array($permission, $held, true))
                ->values())
            ->filter(fn (Collection $permissions): bool => $permissions->isNotEmpty());
    }

    /**
     * Why this role cannot be deleted, or null if it may be.
     *
     * Two reasons, and both are about somebody else's access rather than about
     * the role: a system role is part of how the application gates itself, and
     * deleting a role somebody holds silently removes their access with no
     * record of what they had.
     */
    public function deletionBlockReason(): ?string
    {
        if ($this->isSystem()) {
            return 'This is one of the four built-in roles. It cannot be deleted.';
        }

        $holders = $this->users()->count();

        if ($holders > 0) {
            return trans_choice(
                ':count person still holds this role. Move them to another role first.|:count people still hold this role. Move them to another role first.',
                $holders,
                ['count' => $holders],
            );
        }

        return null;
    }

    public function isDeletable(): bool
    {
        return $this->deletionBlockReason() === null;
    }
}
