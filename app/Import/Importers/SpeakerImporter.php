<?php

declare(strict_types=1);

namespace App\Import\Importers;

use App\Import\Importer;
use App\Import\ImportResult;
use App\Import\Legacy;
use App\Models\Speaker;
use Illuminate\Support\Str;

/**
 * Speakers, from the distinct ACF `speaker` names on the products.
 *
 * The authoritative speaker of a webinar is the name typed on the product, not
 * the `speaker` CPT (which carries bios but is not reliably linked), so the set
 * of speakers is the distinct names across all products — ~25 faculty. Keyed by
 * a deterministic hash of the normalised name, so a re-run matches the same
 * person and the webinar importer can resolve a webinar's speaker by the same
 * key. Name variants of one person ("Jason T. Dinesen" / "Jason Dinesen") land
 * as separate rows for the client to merge; the import never guesses they are
 * the same.
 */
class SpeakerImporter extends Importer
{
    public function label(): string
    {
        return 'Speakers';
    }

    /** The map key for a speaker name — stable across runs. */
    public static function keyFor(string $name): int
    {
        return (int) sprintf('%u', crc32(mb_strtolower(trim($name))));
    }

    public function run(): ImportResult
    {
        $result = new ImportResult;

        $names = Legacy::table('postmeta')
            ->where('meta_key', 'speaker')
            ->pluck('meta_value')
            ->map(static fn (mixed $v): string => trim((string) $v))
            ->filter()
            ->unique()
            ->values();

        foreach ($names as $name) {
            $this->sync($result, 'speaker', self::keyFor($name), Speaker::class, function (Speaker $speaker) use ($name): void {
                $speaker->name = $name;
                $speaker->is_active = true;

                if (! $speaker->exists) {
                    $speaker->slug = $this->uniqueSlug(Speaker::class, Str::slug($name));
                }
            });
        }

        return $result;
    }
}
