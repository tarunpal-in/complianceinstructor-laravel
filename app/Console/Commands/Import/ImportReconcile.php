<?php

declare(strict_types=1);

namespace App\Console\Commands\Import;

use App\Enums\EntitlementSource;
use App\Import\Legacy;
use App\Models\Entitlement;
use App\Models\ImportMap;
use App\Models\Redirect;
use App\Models\SeoMeta;
use App\Models\WebinarAsset;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;

/**
 * Phase 7 — the migration reconciliation report.
 *
 * Puts the legacy source count beside what was imported, per entity, so "is the
 * migration complete and correct?" has a single answer to sign off on. A clean
 * delta is not always zero — speakers merge on name, coupons on code, and guest
 * orders have no account to grant to — so each expected gap is annotated rather
 * than flagged. Read-only.
 */
class ImportReconcile extends Command
{
    protected $signature = 'import:reconcile';

    protected $description = 'Compare the legacy source with what was imported, per entity.';

    public function handle(): int
    {
        if (! Legacy::configured()) {
            $this->error('The legacy database is not configured. Run import:status first.');

            return self::FAILURE;
        }

        $this->info('Migration reconciliation — legacy source vs imported');
        $this->newLine();

        $rows = [];

        foreach ($this->mapped() as [$label, $legacy, $type, $note]) {
            $imported = ImportMap::query()->where('legacy_type', $type)->count();
            $rows[] = $this->row($label, $legacy, $imported, $note);
        }

        // Derived or target-only rows (no 1:1 legacy source).
        $rows[] = ['Entitlements', '—', (string) Entitlement::query()->fromSource(EntitlementSource::Order)->whereNull('revoked_at')->count(), 'derived; active grants'];
        $rows[] = ['SEO meta', '—', (string) SeoMeta::query()->count(), 'webinars with a Yoast override'];
        $rows[] = ['Redirects', '—', (string) Redirect::query()->where('source', Redirect::SOURCE_IMPORT)->count(), '/product → /webinars'];
        $rows[] = ['Webinar assets', '—', (string) WebinarAsset::query()->count(), 'deduped per webinar × format'];

        $this->table(['Entity', 'Legacy', 'Imported', 'Status', 'Note'], $rows);

        $this->newLine();
        $this->line('<comment>Known, expected gaps:</comment>');
        $this->line('  • Guests: ~2,352 guest orders have no account, so no entitlement until the buyer registers with that email.');
        $this->line('  • Assets: GoToWebinar recordings stay external; WP-hosted files move to R2 via import:files.');
        $this->line('  • Accreditation: not in the dump — assign per the client workshop (§12).');

        return self::SUCCESS;
    }

    /**
     * Entities with a 1:1 legacy source, each: [label, legacy count, map type, note].
     *
     * @return array<int, array{0: string, 1: int, 2: string, 3: string}>
     */
    private function mapped(): array
    {
        return [
            ['Speakers', (int) Legacy::table('postmeta')->where('meta_key', 'speaker')->where('meta_value', '<>', '')->distinct()->count('meta_value'), 'speaker', 'merged on name'],
            ['Industries', $this->industryCount(), 'industry', 'format categories skipped'],
            ['Webinars', (int) Legacy::table('posts')->where('post_type', 'product')->whereIn('post_status', ['publish', 'draft', 'pending'])->count(), 'webinar', ''],
            ['Offerings', (int) Legacy::table('posts')->where('post_type', 'product_variation')->whereIn('post_status', ['publish', 'private'])->count(), 'offering', 'one per variation'],
            ['Customers', $this->customerCount(), 'user', 'spam subscribers excluded'],
            ['Coupons', (int) Legacy::table('posts')->where('post_type', 'shop_coupon')->whereIn('post_status', ['publish', 'draft', 'pending'])->count(), 'coupon', 'merged on code'],
            ['Orders', $this->orderCount(), 'order', ''],
            ['Payments', $this->orderCount(), 'payment', 'one per order'],
            ['Refunds', (int) Legacy::table('posts')->where('post_type', 'shop_order_refund')->count(), 'refund', ''],
            ['Coupon uses', (int) Legacy::table('wc_order_coupon_lookup')->count(), 'coupon_redemption', 'deleted coupons/orders skipped'],
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    private function row(string $label, int $legacy, int $imported, string $note): array
    {
        $status = match (true) {
            $imported === $legacy => '✓',
            $imported < $legacy => '~ '.($legacy - $imported).' fewer',
            default => '+ '.($imported - $legacy),
        };

        return [$label, number_format($legacy), number_format($imported), $status, $note];
    }

    private function industryCount(): int
    {
        return (int) Legacy::table('term_taxonomy')
            ->where('taxonomy', 'product_cat')
            ->whereNotIn('term_id', function (Builder $q): void {
                $q->select('term_id')->from('terms')->whereIn('slug', ['recorded', 'live', 'packages', 'uncategorized', 'training', 'industry']);
            })
            ->count();
    }

    private function customerCount(): int
    {
        return (int) Legacy::table('users as u')
            ->join('usermeta as caps', fn ($j) => $j->on('caps.user_id', '=', 'u.ID')->where('caps.meta_key', 'wp_capabilities'))
            ->leftJoin('usermeta as bf', fn ($j) => $j->on('bf.user_id', '=', 'u.ID')->where('bf.meta_key', 'billing_first_name'))
            ->where(function ($w): void {
                $w->where('caps.meta_value', 'like', '%customer%')
                    ->orWhere(fn ($b) => $b->whereNotNull('bf.meta_value')->where('bf.meta_value', '<>', ''));
            })
            ->distinct()
            ->count('u.ID');
    }

    private function orderCount(): int
    {
        return (int) Legacy::table('posts')
            ->where('post_type', 'shop_order')
            ->whereIn('post_status', ['wc-completed', 'wc-processing', 'wc-on-hold', 'wc-pending', 'wc-refunded', 'wc-cancelled', 'wc-failed'])
            ->count();
    }
}
