<?php

declare(strict_types=1);

namespace App\Filament\Resources\Coupons\Pages;

use App\Filament\Resources\Coupons\CouponResource;
use App\Filament\Resources\Coupons\Schemas\CouponForm;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditCoupon extends EditRecord
{
    protected static string $resource = CouponResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            // The way back. A deleted coupon is reachable through the list's
            // "Deleted coupons" filter, and this is what undoes it.
            RestoreAction::make(),
        ];
    }

    /**
     * The same mapping as create, so a coupon edited cannot end up shaped
     * differently from one created.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return CouponForm::mutate($data);
    }
}
