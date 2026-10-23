<?php

declare(strict_types=1);

namespace App\Console\Commands\Import;

use App\Import\Legacy;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Throwable;

/**
 * The first smoke test of the migration pipeline: confirm the WordPress source
 * is reachable and summarise what it holds, so loading the dump can be verified
 * before any importer runs.
 */
class ImportStatus extends Command
{
    protected $signature = 'import:status';

    protected $description = 'Check the legacy WordPress source is reachable and summarise what it holds.';

    public function handle(): int
    {
        if (! Legacy::configured()) {
            $this->error('The legacy database is not configured. Set LEGACY_DB_DATABASE (and credentials) and load the WordPress dump first.');

            return self::FAILURE;
        }

        try {
            Legacy::connection()->getPdo();
        } catch (Throwable $e) {
            $this->error('Could not connect to the legacy database: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Connected to the legacy source.');

        /** @var array<int, array{0: string, 1: array{0: string, 1: ?callable}}> $metrics */
        $metrics = [
            ['Users (wp_users)', ['users', null]],
            ['Customers (buyers)', ['wc_customer_lookup', null]],
            ['Webinar products', ['posts', fn (Builder $q): Builder => $q->where('post_type', 'product')]],
            ['Format variations', ['posts', fn (Builder $q): Builder => $q->where('post_type', 'product_variation')]],
            ['Speakers', ['posts', fn (Builder $q): Builder => $q->where('post_type', 'speaker')]],
            ['Orders', ['wc_order_stats', null]],
            ['Order line items', ['woocommerce_order_items', fn (Builder $q): Builder => $q->where('order_item_type', 'line_item')]],
            ['Coupons', ['posts', fn (Builder $q): Builder => $q->where('post_type', 'shop_coupon')]],
        ];

        $rows = [];

        foreach ($metrics as [$label, [$table, $scope]]) {
            $query = Legacy::table($table);

            if ($scope !== null) {
                $scope($query);
            }

            $rows[] = [$label, number_format($query->count())];
        }

        $this->table(['Source', 'Rows'], $rows);

        return self::SUCCESS;
    }
}
