<?php

declare(strict_types=1);

namespace App\Console\Commands\Import;

use App\Models\Industry;
use App\Models\Speaker;
use App\Models\User;
use App\Models\Webinar;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Removes the demo seed data that coexists with the migration, leaving only the
 * imported records and the infrastructure.
 *
 * `migrate:fresh --seed` fills the catalogue and commerce tables with demo rows
 * (DemoCatalogueSeeder, OrderSeeder, …) so the app is reviewable; after a real
 * import those duplicate the imported data (two "Human Resources", demo
 * webinars, demo orders). The import map marks every imported row, so anything
 * not in it — a catalogue/commerce row without a map entry — is seeded and
 * removed here. Kept: the admin and any staff account, roles, settings, pages,
 * navigation, membership plans, media. Deletes cascade at the database level, so
 * removing a seeded webinar or order also clears its children.
 *
 * Shows what it would delete and changes nothing unless `--execute` is passed.
 */
class CleanupSeedData extends Command
{
    protected $signature = 'migration:cleanup-seed {--execute : Actually delete (otherwise only report)}';

    protected $description = 'Remove demo seed data, keeping imported records and infrastructure.';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $userMorph = (new User)->getMorphClass();

        $targets = [
            'Orders (+ items, payments, refunds)' => fn (): Builder => DB::table('orders')->whereNotExists($this->unmapped('orders', 'order')),
            'Webinars (+ entitlements, assets)' => fn (): Builder => DB::table('webinars')->whereNotExists($this->unmapped('webinars', 'webinar')),
            'Offerings' => fn (): Builder => DB::table('offerings')->whereNotExists($this->unmapped('offerings', 'offering')),
            'Coupons' => fn (): Builder => DB::table('coupons')->whereNotExists($this->unmapped('coupons', 'coupon')),
            'Subscriptions' => fn (): Builder => DB::table('subscriptions'),
            'Demo customers' => fn (): Builder => $this->demoCustomers($userMorph),
            'Speakers' => fn (): Builder => DB::table('speakers')->whereNotExists($this->unmapped('speakers', 'speaker')),
            'Industries' => fn (): Builder => DB::table('industries')->whereNotExists($this->unmapped('industries', 'industry')),
        ];

        $this->info($execute ? 'Removing demo seed data' : 'Demo seed data that WOULD be removed (pass --execute to delete)');
        $this->newLine();

        $rows = [];

        foreach ($targets as $label => $query) {
            $rows[] = [$label, number_format((clone $query())->count())];
        }

        $this->table(['Entity', 'Rows'], $rows);

        if (! $execute) {
            $this->newLine();
            $this->line('<comment>Nothing deleted. Re-run with --execute to apply. Admin, staff, settings, pages, navigation and membership plans are always kept.</comment>');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($targets): void {
                // FK cascades handle the children (a deleted subscription clears
                // its subscription_entitlements, a deleted order its items and
                // payments, and so on).
                foreach ($targets as $query) {
                    $query()->delete();
                }

                // seo_meta is polymorphic (no FK), so a deleted webinar/speaker/
                // industry leaves an orphan behind — clear those.
                $this->clearOrphanSeo();
            });
        } catch (Throwable $e) {
            $this->error('Cleanup failed and was rolled back: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Demo seed data removed.');

        return self::SUCCESS;
    }

    /** A "not imported" (no map row) existence check for a table. */
    private function unmapped(string $table, string $legacyType): Closure
    {
        return function (Builder $q) use ($table, $legacyType): void {
            $q->selectRaw('1')
                ->from('import_maps')
                ->where('import_maps.legacy_type', $legacyType)
                ->whereColumn('import_maps.model_id', $table.'.id');
        };
    }

    /** Seeded customers: no import map and no staff role. */
    private function demoCustomers(string $userMorph): Builder
    {
        return DB::table('users')
            ->whereNotExists($this->unmapped('users', 'user'))
            ->whereNotExists(function (Builder $q) use ($userMorph): void {
                $q->selectRaw('1')
                    ->from('model_has_roles')
                    ->where('model_has_roles.model_type', $userMorph)
                    ->whereColumn('model_has_roles.model_id', 'users.id');
            });
    }

    private function clearOrphanSeo(): void
    {
        foreach ([(new Webinar)->getMorphClass() => 'webinars', (new Speaker)->getMorphClass() => 'speakers', (new Industry)->getMorphClass() => 'industries'] as $morph => $table) {
            DB::table('seo_meta')
                ->where('seoable_type', $morph)
                ->whereNotExists(function (Builder $q) use ($table): void {
                    $q->selectRaw('1')->from($table)->whereColumn($table.'.id', 'seo_meta.seoable_id');
                })
                ->delete();
        }
    }
}
