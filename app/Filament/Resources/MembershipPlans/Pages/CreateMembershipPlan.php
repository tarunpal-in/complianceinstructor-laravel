<?php

declare(strict_types=1);

namespace App\Filament\Resources\MembershipPlans\Pages;

use App\Filament\Resources\MembershipPlans\MembershipPlanResource;
use App\Filament\Resources\MembershipPlans\Schemas\MembershipPlanForm;
use Filament\Resources\Pages\CreateRecord;

class CreateMembershipPlan extends CreateRecord
{
    protected static string $resource = MembershipPlanResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return MembershipPlanForm::mutate($data);
    }

    /**
     * Land on the edit page, where the subscriber list lives.
     *
     * Relation managers only render on an existing record, so the list of who
     * is on the plan cannot exist until it is saved — the same reason
     * CreateWebinar redirects rather than returning to the index.
     */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
