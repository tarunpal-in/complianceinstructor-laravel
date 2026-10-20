<?php

declare(strict_types=1);

namespace App\Filament\Account\Pages;

use App\Enums\EmailCategory;
use App\Models\User;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Which optional emails a member wants — CLAUDE.md §6 ("notifications") / P5.
 *
 * Only the categories a recipient may decline appear here; receipts, reset links
 * and certificates are not preferences and are never on this screen (§14 #6 — a
 * preference that could suppress a receipt would be the polite version of it).
 * The toggles are opt-out, so a new category a member never saw starts on.
 */
class EmailPreferences extends Page
{
    protected string $view = 'filament.account.pages.email-preferences';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $title = 'Notifications';

    protected static ?string $navigationLabel = 'Notifications';

    protected static ?int $navigationSort = 8;

    /**
     * Each declinable category and whether this member currently accepts it.
     *
     * @return array<int, array{category: EmailCategory, accepted: bool}>
     */
    public function preferences(): array
    {
        $user = $this->member();

        return array_map(
            fn (EmailCategory $category): array => [
                'category' => $category,
                'accepted' => $user->acceptsEmailCategory($category),
            ],
            EmailCategory::declinable(),
        );
    }

    public function toggle(string $category): void
    {
        $resolved = EmailCategory::tryFrom($category);

        // A category that is not declinable has no business being toggled, and
        // setEmailCategoryAccepted would throw — so refuse quietly here.
        if ($resolved === null || ! $resolved->mayBeDeclinedByRecipient()) {
            return;
        }

        $user = $this->member();
        $user->setEmailCategoryAccepted($resolved, ! $user->acceptsEmailCategory($resolved));

        Notification::make()
            ->title('Preferences updated')
            ->success()
            ->send();
    }

    private function member(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
