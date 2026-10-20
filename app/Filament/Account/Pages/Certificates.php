<?php

declare(strict_types=1);

namespace App\Filament\Account\Pages;

use App\Models\Certificate;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * The member's CEU certificates — CLAUDE.md §8 #4 / §6 ("downloadable from the
 * dashboard").
 *
 * Scoped to the signed-in account, so a member sees only their own, and the PDF
 * is reached through a signed, expiring, owner-checked link (§9) rather than a
 * guessable path. The record shows the frozen details the certificate asserts;
 * the download appears once the PDF has been rendered (P8) and a revoked one is
 * shown as revoked with nothing to download.
 */
class Certificates extends Page
{
    protected string $view = 'filament.account.pages.certificates';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $title = 'Certificates';

    protected static ?string $navigationLabel = 'Certificates';

    protected static ?int $navigationSort = 5;

    /**
     * This member's certificates, newest first.
     *
     * @return Collection<int, Certificate>
     */
    public function certificates(): Collection
    {
        /** @var User $user */
        $user = auth()->user();

        return Certificate::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->get();
    }
}
