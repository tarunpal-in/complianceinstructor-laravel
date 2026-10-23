<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ImportMap;
use App\Models\Speaker;
use App\Models\Webinar;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Resolve duplicate speakers and webinars left by the WordPress import, losing
 * nothing.
 *
 * The import keys speakers by a hash of the typed name (SpeakerImporter), so one
 * person entered under two spellings — "Jason T. Dinesen" and "Jason Dinesen" —
 * lands as two rows on purpose, "for the client to merge". Webinars are keyed by
 * their legacy product id, so the duplicates there are genuine double-entries in
 * the source catalogue rather than a re-run artefact.
 *
 * Merging a speaker is lossless: every webinar (trashed included) is reassigned
 * to the survivor, the portrait and import mappings move with them, and the
 * emptied duplicate is soft-deleted — restorable, and its slug released
 * (ReleasesUniqueNameOnDelete). Webinars are treated far more cautiously because
 * they carry money: a duplicate is soft-deleted only when it has nothing
 * attached and nothing sold. A duplicate with offerings, orders, entitlements or
 * certificates is never touched — it is reported for a human to merge by hand,
 * because collapsing commerce records is exactly the class of change §9/§14 say
 * to be most careful with.
 *
 * Dry-run by default: it reports and writes nothing. Pass --apply to act.
 */
class DedupeCatalogue extends Command
{
    protected $signature = 'catalogue:dedupe
        {--apply : Perform the merges and prunes. Without it, this only reports (dry-run).}
        {--similar : Also merge speakers whose names match once middle initials and suffixes are ignored (e.g. "Jason T. Dinesen" ⇄ "Jason Dinesen"). Off by default, because a wrong match loses a real person.}
        {--merge=* : Resolve a specific speaker group by hand: "survivorId:dupeId[,dupeId...]". Runs before the automatic passes.}';

    protected $description = 'Find and resolve duplicate speakers (merge) and webinars (prune empty duplicates), without losing any record.';

    private bool $apply = false;

    public function handle(): int
    {
        $this->apply = (bool) $this->option('apply');

        $this->line($this->apply
            ? '<fg=yellow>Applying changes.</> Records are soft-deleted, so a mistake is restorable.'
            : '<fg=cyan>Dry run.</> Nothing is written — re-run with <options=bold>--apply</> to act.');
        $this->newLine();

        $this->resolveSpeakers();
        $this->newLine();
        $this->resolveWebinars();

        if (! $this->apply) {
            $this->newLine();
            $this->comment('No changes made. Re-run with --apply once the plan above looks right.');
        }

        return self::SUCCESS;
    }

    // Speakers --------------------------------------------------------------

    private function resolveSpeakers(): void
    {
        $this->info('Speakers');

        $mergedGroups = 0;
        $movedWebinars = 0;

        // 1. Hand-directed merges first, so an explicit choice always wins. The
        //    whole chosen group is then off-limits to the automatic passes.
        /** @var array<int, true> $resolved ids the auto passes must leave alone */
        $resolved = [];

        foreach ($this->explicitMerges() as [$survivor, $dupes]) {
            foreach ([$survivor, ...$dupes] as $speaker) {
                $resolved[$speaker->getKey()] = true;
            }
            $movedWebinars += $this->reportAndMerge($survivor, collect($dupes), 'chosen');
            $mergedGroups++;
        }

        $speakers = Speaker::query()->get()->reject(fn (Speaker $s): bool => isset($resolved[$s->getKey()]));

        // 2. Exact duplicates — same name once case and spacing are normalised.
        //    Only the duplicates leave the pool; each survivor stays eligible
        //    below, so a third spelling ("Dayna Reum" beside the "Dayna J. Reum"
        //    / "Dayna J Reum" pair) folds in on the same run rather than
        //    surfacing only after a second.
        /** @var array<int, true> $mergedAway duplicates already folded into a survivor */
        $mergedAway = [];

        $exactGroups = $speakers
            ->groupBy(fn (Speaker $s): string => $this->normalise($s->name))
            ->filter(static fn (Collection $group): bool => $group->count() >= 2);

        foreach ($exactGroups as $group) {
            [$survivor, $dupes] = $this->splitGroup($group);
            foreach ($dupes as $dupe) {
                $mergedAway[$dupe->getKey()] = true;
            }
            $movedWebinars += $this->reportAndMerge($survivor, $dupes, 'exact name');
            $mergedGroups++;
        }

        // 3. Likely duplicates — same words ignoring initials/suffixes. Reported
        //    always; merged only when --similar makes it the operator's choice.
        //    Exact survivors stay in the pool so they can anchor a group.
        $remaining = $speakers->reject(fn (Speaker $s): bool => isset($mergedAway[$s->getKey()]));
        $similarGroups = $remaining
            ->groupBy(fn (Speaker $s): string => $this->wordKey($s->name))
            ->filter(static fn (Collection $group): bool => $group->count() >= 2);

        foreach ($similarGroups as $group) {
            [$survivor, $dupes] = $this->splitGroup($group);

            if ($this->option('similar')) {
                $movedWebinars += $this->reportAndMerge($survivor, $dupes, 'similar name');
                $mergedGroups++;

                continue;
            }

            $names = $group->map(fn (Speaker $s): string => "#{$s->getKey()} {$s->name}")->implode(', ');
            $this->line("  <fg=yellow>likely dup</> {$names} — pass <options=bold>--similar</> to merge, or <options=bold>--merge={$survivor->getKey()}:".$dupes->map->getKey()->implode(',').'</>');
        }

        if ($mergedGroups === 0) {
            $this->line('  <fg=gray>No speaker duplicates to merge.</>');

            return;
        }

        $verb = $this->apply ? 'Merged' : 'Would merge';
        $this->line("  <info>{$verb} {$mergedGroups} speaker group(s); {$movedWebinars} webinar link(s) reassigned.</>");
    }

    /**
     * Report a speaker group and, on --apply, merge the duplicates into the
     * survivor. Returns the number of webinar links reassigned.
     *
     * @param  Collection<int, Speaker>  $dupes
     */
    private function reportAndMerge(Speaker $survivor, Collection $dupes, string $reason): int
    {
        if ($dupes->isEmpty()) {
            return 0;
        }

        $moved = 0;

        foreach ($dupes as $dupe) {
            $count = Webinar::withTrashed()->where('speaker_id', $dupe->getKey())->count();
            $moved += $count;

            $this->line(sprintf(
                '  <fg=green>%s</> keep #%d %s  ←  #%d %s (%s, %d webinar%s)',
                $this->apply ? 'merged' : 'merge',
                $survivor->getKey(),
                $survivor->name,
                $dupe->getKey(),
                $dupe->name,
                $reason,
                $count,
                $count === 1 ? '' : 's',
            ));

            if ($this->apply) {
                $this->mergeSpeaker($survivor, $dupe);
            }
        }

        return $moved;
    }

    /** Move everything the duplicate owns onto the survivor, then soft-delete it. */
    private function mergeSpeaker(Speaker $survivor, Speaker $dupe): void
    {
        DB::transaction(function () use ($survivor, $dupe): void {
            // Trashed webinars included: a soft-deleted session still belongs to
            // its speaker and must not be orphaned on the row about to vanish.
            Webinar::withTrashed()
                ->where('speaker_id', $dupe->getKey())
                ->update(['speaker_id' => $survivor->getKey()]);

            // The portrait moves only if the survivor has none — never overwrite
            // a chosen face. A singleFile collection means at most one row.
            if (! $this->hasPortrait($survivor)) {
                Media::query()
                    ->where('model_type', $dupe->getMorphClass())
                    ->where('model_id', $dupe->getKey())
                    ->where('collection_name', 'portrait')
                    ->update(['model_id' => $survivor->getKey()]);
            }

            // Both legacy name-keys now resolve to the survivor, so a future
            // re-import updates it instead of resurrecting the duplicate.
            ImportMap::query()
                ->where('model_type', $dupe->getMorphClass())
                ->where('model_id', $dupe->getKey())
                ->update(['model_id' => $survivor->getKey()]);

            // The survivor keeps its own SEO row; the duplicate's is dropped
            // rather than repointed, because (seoable_type, seoable_id) is unique.
            DB::table('seo_meta')
                ->where('seoable_type', $dupe->getMorphClass())
                ->where('seoable_id', $dupe->getKey())
                ->delete();

            $dupe->delete();
        });
    }

    private function hasPortrait(Speaker $speaker): bool
    {
        return Media::query()
            ->where('model_type', $speaker->getMorphClass())
            ->where('model_id', $speaker->getKey())
            ->where('collection_name', 'portrait')
            ->exists();
    }

    /**
     * The --merge groups, resolved to live speakers.
     *
     * @return array<int, array{0: Speaker, 1: array<int, Speaker>}>
     */
    private function explicitMerges(): array
    {
        $groups = [];

        /** @var array<int, string> $specs */
        $specs = (array) $this->option('merge');

        foreach ($specs as $spec) {
            if (! str_contains($spec, ':')) {
                $this->warn("  Ignoring --merge=\"{$spec}\": expected \"survivorId:dupeId,dupeId\".");

                continue;
            }

            [$keepId, $dupeIds] = explode(':', $spec, 2);
            $survivor = Speaker::query()->find((int) trim($keepId));

            if ($survivor === null) {
                $this->warn("  Ignoring --merge=\"{$spec}\": no speaker #{$keepId}.");

                continue;
            }

            $dupes = collect(explode(',', $dupeIds))
                ->map(static fn (string $id): int => (int) trim($id))
                ->filter(static fn (int $id): bool => $id !== 0 && $id !== $survivor->getKey())
                ->map(static fn (int $id): ?Speaker => Speaker::query()->find($id))
                ->filter()
                ->values()
                ->all();

            if ($dupes === []) {
                $this->warn("  Ignoring --merge=\"{$spec}\": no valid duplicate ids.");

                continue;
            }

            $groups[] = [$survivor, $dupes];
        }

        return $groups;
    }

    // Webinars --------------------------------------------------------------

    private function resolveWebinars(): void
    {
        $this->info('Webinars');

        $groups = Webinar::query()->get()
            ->groupBy(fn (Webinar $w): string => $this->normalise($w->title))
            ->filter(static fn (Collection $group): bool => $group->count() >= 2);

        if ($groups->isEmpty()) {
            $this->line('  <fg=gray>No duplicate webinar titles found.</>');

            return;
        }

        $pruned = 0;
        $kept = 0;

        foreach ($groups as $group) {
            // The survivor is the one carrying the most — sales, offerings,
            // content — so nothing of value is ever the row left behind.
            $ranked = $group->sortByDesc(fn (Webinar $w): int => $this->webinarWeight($w))->values();
            $survivor = $ranked->first();

            $this->line("  group \"{$survivor->title}\" — keep #{$survivor->getKey()}");

            foreach ($ranked->slice(1) as $dupe) {
                $weight = $this->webinarWeight($dupe);

                if ($weight > 0) {
                    $kept++;
                    $this->line("    <fg=yellow>review</> #{$dupe->getKey()} has {$weight} attached record(s) (offerings/orders/credits) — left untouched; merge by hand if it is truly a duplicate.");

                    continue;
                }

                $pruned++;
                $this->line('    <fg=green>'.($this->apply ? 'pruned' : 'prune')."</> #{$dupe->getKey()} is an empty duplicate (nothing attached, nothing sold).");

                if ($this->apply) {
                    $dupe->delete();
                }
            }
        }

        $verb = $this->apply ? 'Pruned' : 'Would prune';
        $this->line("  <info>{$verb} {$pruned} empty duplicate(s); {$kept} left for manual review.</>");
    }

    /** How much a webinar carries — zero means a safe, lossless delete. */
    private function webinarWeight(Webinar $webinar): int
    {
        $id = $webinar->getKey();

        return $webinar->offerings()->count()
            + $webinar->orderItems()->count()
            + $webinar->assets()->count()
            + $webinar->handouts()->count()
            + $webinar->sections()->count()
            + $webinar->accreditations()->count()
            + (int) DB::table('entitlements')->where('webinar_id', $id)->count()
            + (int) DB::table('certificates')->where('webinar_id', $id)->count()
            + (int) DB::table('webinar_views')->where('webinar_id', $id)->count();
    }

    // Grouping helpers ------------------------------------------------------

    /**
     * Split a speaker group into its survivor and the duplicates to fold in.
     *
     * @param  Collection<int, Speaker>  $group
     * @return array{0: Speaker, 1: Collection<int, Speaker>}
     */
    private function splitGroup(Collection $group): array
    {
        $ranked = $group->sort(fn (Speaker $a, Speaker $b): int => $this->speakerScore($b) <=> $this->speakerScore($a))->values();

        return [$ranked->first(), $ranked->slice(1)->values()];
    }

    /**
     * A comparable "keep me" score, most significant first: keep the canonical
     * record — pictured, active, with the fullest profile — over a sparse stub,
     * then the one teaching the most sessions, and a lower id breaks ties so the
     * choice is stable across runs. Webinars reassign either way, so this only
     * decides which row's own fields the merged speaker keeps.
     *
     * @return array<int, int>
     */
    private function speakerScore(Speaker $speaker): array
    {
        return [
            $this->hasPortrait($speaker) ? 1 : 0,
            $speaker->is_active ? 1 : 0,
            mb_strlen(trim((string) $speaker->bio).trim((string) $speaker->title).trim((string) $speaker->credentials)),
            Webinar::withTrashed()->where('speaker_id', $speaker->getKey())->count(),
            -$speaker->getKey(),
        ];
    }

    /** Lowercased, trimmed, punctuation-flattened, single-spaced. */
    private function normalise(string $value): string
    {
        $value = str_replace(['.', ',', '-'], ' ', mb_strtolower(trim($value)));

        return (string) preg_replace('/\s+/', ' ', $value);
    }

    /** The multiset of real name-words, so initials and one-letter tokens drop. */
    private function wordKey(string $name): string
    {
        $words = array_filter(
            explode(' ', $this->normalise($name)),
            static fn (string $word): bool => mb_strlen($word) >= 2,
        );

        $words = array_values(array_unique($words));
        sort($words);

        return implode(' ', $words);
    }
}
