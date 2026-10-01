<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActivityLog\Pages;

use App\Filament\Resources\ActivityLog\ActivityResource;
use App\Support\ActivityNarrator;
use Filament\Resources\Pages\ViewRecord;
use Spatie\Activitylog\Models\Activity;

class ViewActivity extends ViewRecord
{
    protected static string $resource = ActivityResource::class;

    public function getTitle(): string
    {
        /** @var Activity $record */
        $record = $this->getRecord();

        return ActivityNarrator::describe($record);
    }

    public function getSubheading(): ?string
    {
        /** @var Activity $record */
        $record = $this->getRecord();

        return ActivityNarrator::causerName($record)
            .' · '.($record->created_at?->format('j M Y, H:i:s') ?? 'unknown time');
    }

    /**
     * No actions at all.
     *
     * Nothing may be done to an entry — not edited, not deleted, not by a
     * Super Admin. The person most likely to want a row gone is the one the
     * row is about.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
