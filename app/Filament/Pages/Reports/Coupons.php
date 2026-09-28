<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Pages\Reports\Concerns\PicksAPeriod;
use App\Models\Coupon;
use App\Reports\CouponPerformance;
use App\Reports\CouponRow;
use App\Support\Csv;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * What the discounting costs — §8's "coupon performance and discount cost".
 *
 * Gated on `view_reports` rather than on coupons: this screen is about money
 * given away, and somebody who may edit a code is not necessarily somebody who
 * should see what the discounting has cost the business.
 */
class Coupons extends Page implements HasForms
{
    use InteractsWithForms;
    use PicksAPeriod;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|null|UnitEnum $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Coupons';

    protected static ?string $title = 'Discount cost';

    protected static ?string $slug = 'reports/coupons';

    protected string $view = 'filament.pages.reports.coupons';

    public function form(Schema $schema): Schema
    {
        return $this->periodForm($schema);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view_reports') ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Export')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('export_data') ?? false)
                ->action(fn (): StreamedResponse => $this->export()),
        ];
    }

    public function getReport(): CouponPerformance
    {
        return new CouponPerformance($this->getPeriod());
    }

    /** @return Collection<int, CouponRow> */
    public function getRows(): Collection
    {
        return $this->getReport()->rows();
    }

    /** @return Collection<int, Coupon> */
    public function getNeverUsed(): Collection
    {
        return $this->getReport()->neverUsed();
    }

    /** @return Collection<int, Coupon> */
    public function getEndingSoon(): Collection
    {
        return $this->getReport()->endingSoon();
    }

    private function export(): StreamedResponse
    {
        $rows = $this->getRows();

        return Csv::download(
            Csv::filename('discount-cost'),
            ['Code', 'Discount', 'Uses', 'Discount given', 'Revenue on those orders', 'Discount rate %', 'Average order'],
            function () use ($rows): iterable {
                foreach ($rows as $row) {
                    yield [
                        $row->coupon->code,
                        $row->coupon->describeDiscount(),
                        (string) $row->uses,
                        Csv::money($row->discountCents),
                        Csv::money($row->revenueCents),
                        $row->discountRate() === null ? '' : (string) $row->discountRate(),
                        Csv::money($row->averageOrderCents()),
                    ];
                }
            },
        );
    }
}
