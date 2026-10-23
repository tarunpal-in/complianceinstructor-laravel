<?php

declare(strict_types=1);

namespace App\Filament\Account\Pages;

use App\Enums\EmailCategory;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Which optional emails a member wants — CLAUDE.md §6 ("notifications") / P5.
 *
 * Only the categories a recipient may decline appear here; receipts, reset links
 * and certificates are not preferences and are never on this screen (§14 #6 — a
 * preference that could suppress a receipt would be the polite version of it).
 * The toggles are opt-out, so a new category a member never saw starts on.
 *
 * Built as a native Filament form so the switches are styled by the panel's own
 * CSS. The earlier hand-written Tailwind markup rendered unstyled — its utility
 * classes are not in the panel's stylesheet — which left the toggles showing
 * their screen-reader text as plain words.
 */
class EmailPreferences extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.account.pages.email-preferences';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $title = 'Notifications';

    protected static ?string $navigationLabel = 'Notifications';

    protected static ?int $navigationSort = 8;

    /** @var array<string, bool> */
    public array $data = [];

    public function mount(): void
    {
        $user = $this->member();

        $this->getForm('form')?->fill(
            collect(EmailCategory::declinable())
                ->mapWithKeys(fn (EmailCategory $category): array => [
                    $category->value => $user->acceptsEmailCategory($category),
                ])
                ->all(),
        );
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->columns(1)
            ->components([
                Section::make('Email notifications')
                    ->description("Choose which optional emails you'd like to receive. Receipts, password resets and certificates are always sent and aren't listed here.")
                    ->schema(array_map(
                        fn (EmailCategory $category): Toggle => Toggle::make($category->value)
                            ->label($category->label())
                            ->inline(false)
                            ->live()
                            ->afterStateUpdated(function (bool $state) use ($category): void {
                                $this->persist($category, $state);
                            }),
                        EmailCategory::declinable(),
                    )),
            ]);
    }

    /**
     * Flip one category, from the test harness or anywhere that wants the whole
     * action rather than a form field. Reads the current state and persists its
     * opposite, through the same guard and save path as the toggles.
     */
    public function toggle(string $category): void
    {
        $resolved = EmailCategory::tryFrom($category);

        if ($resolved === null || ! $resolved->mayBeDeclinedByRecipient()) {
            return;
        }

        $this->persist($resolved, ! $this->member()->acceptsEmailCategory($resolved));
    }

    private function persist(EmailCategory $category, bool $accepted): void
    {
        // A category that is not declinable has no business being changed, and
        // setEmailCategoryAccepted would throw — so refuse quietly.
        if (! $category->mayBeDeclinedByRecipient()) {
            return;
        }

        $this->member()->setEmailCategoryAccepted($category, $accepted);

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
