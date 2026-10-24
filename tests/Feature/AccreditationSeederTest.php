<?php

declare(strict_types=1);

use App\Models\Accreditation;
use Database\Seeders\AccreditationSeeder;

/*
 * The accrediting bodies are reference data seeded on every site, so the
 * homepage section never falls back to placeholders. Keyed on the code, so
 * running it against a database that already has some is safe.
 */

it('seeds the four accrediting bodies active, and is idempotent', function (): void {
    (new AccreditationSeeder)->run();
    (new AccreditationSeeder)->run();

    expect(Accreditation::query()->whereIn('code', ['SHRM', 'HRCI', 'CPE', 'APA'])->where('is_active', true)->count())->toBe(4)
        // The second run must not duplicate CPE.
        ->and(Accreditation::query()->where('code', 'CPE')->count())->toBe(1);
});

it('does not overwrite a body that already exists', function (): void {
    Accreditation::factory()->create(['code' => 'CPE', 'name' => 'Custom CPE Name', 'is_active' => true]);

    (new AccreditationSeeder)->run();

    expect(Accreditation::query()->where('code', 'CPE')->value('name'))->toBe('Custom CPE Name');
});
