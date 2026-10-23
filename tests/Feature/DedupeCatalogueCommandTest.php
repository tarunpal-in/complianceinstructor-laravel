<?php

declare(strict_types=1);

use App\Models\Accreditation;
use App\Models\Speaker;
use App\Models\Webinar;
use Illuminate\Support\Facades\DB;

/*
 * `catalogue:dedupe` resolves the duplicates the WordPress import leaves behind
 * (SpeakerImporter keys by name, so one person under two spellings is two rows).
 * The contract it must keep: no webinar and no speaker is ever lost — a merged
 * speaker is soft-deleted with every webinar reassigned first, and a webinar
 * carrying anything (sales, credits, content) is never deleted automatically.
 */

/** Two speakers for the same person, the first with more to keep. */
function duplicateSpeakers(string $kept = 'Jason T. Dinesen', string $dupe = 'jason  dinesen'): array
{
    $survivor = Speaker::factory()->create(['name' => $kept, 'slug' => 'jason-dinesen']);
    $duplicate = Speaker::factory()->create(['name' => $dupe, 'slug' => 'jason-dinesen-2', 'bio' => '', 'title' => '', 'credentials' => '']);

    return [$survivor->fresh(), $duplicate->fresh()];
}

it('reports but writes nothing in dry-run', function (): void {
    [, $duplicate] = duplicateSpeakers();
    Webinar::factory()->create(['speaker_id' => $duplicate->getKey()]);

    $this->artisan('catalogue:dedupe')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(Speaker::query()->count())->toBe(2)
        ->and($duplicate->fresh()->trashed())->toBeFalse();
});

it('merges exact-name duplicate speakers, reassigning every webinar and losing none', function (): void {
    [$survivor, $duplicate] = duplicateSpeakers('Jane Doe', 'jane  DOE');

    $keptWebinar = Webinar::factory()->create(['speaker_id' => $survivor->getKey()]);
    $movedWebinar = Webinar::factory()->create(['speaker_id' => $duplicate->getKey()]);
    $trashedWebinar = Webinar::factory()->create(['speaker_id' => $duplicate->getKey()]);
    $trashedWebinar->delete();

    $this->artisan('catalogue:dedupe --apply')->assertSuccessful();

    // The duplicate is gone from view but restorable; the survivor remains.
    expect($duplicate->fresh()->trashed())->toBeTrue()
        ->and($survivor->fresh()->trashed())->toBeFalse();

    // No webinar lost — all three now belong to the survivor, trashed included.
    expect(Webinar::withTrashed()->count())->toBe(3)
        ->and($keptWebinar->fresh()->speaker_id)->toBe($survivor->getKey())
        ->and($movedWebinar->fresh()->speaker_id)->toBe($survivor->getKey())
        ->and($trashedWebinar->fresh()->speaker_id)->toBe($survivor->getKey());
});

it('leaves name variants alone unless --similar is given', function (): void {
    // Same words, different spelling (middle initial) — a "likely" dup only.
    $a = Speaker::factory()->create(['name' => 'Margie Faulk', 'slug' => 'margie-faulk']);
    $b = Speaker::factory()->create(['name' => 'Margie T Faulk', 'slug' => 'margie-t-faulk']);

    $this->artisan('catalogue:dedupe --apply')->assertSuccessful();
    expect(Speaker::query()->count())->toBe(2);

    $this->artisan('catalogue:dedupe --apply --similar')->assertSuccessful();
    expect(Speaker::query()->count())->toBe(1);
});

it('folds a third spelling in on the same run as the exact pair', function (): void {
    // "Dayna J. Reum" / "Dayna J Reum" are an exact pair; "Dayna Reum" is a
    // third spelling. The exact pass must not hide the pair's survivor from the
    // similar pass, or the third would only surface on a second run.
    $withDot = Speaker::factory()->create(['name' => 'Dayna J. Reum', 'slug' => 'dayna-j-reum']);
    Speaker::factory()->create(['name' => 'Dayna J Reum', 'slug' => 'dayna-j-reum-2']);
    Speaker::factory()->create(['name' => 'Dayna Reum', 'slug' => 'dayna-reum']);
    Webinar::factory()->create(['speaker_id' => $withDot->getKey()]);

    $this->artisan('catalogue:dedupe --apply --similar')->assertSuccessful();

    // One pass resolves all three, and a second finds nothing left.
    expect(Speaker::query()->count())->toBe(1);
    $this->artisan('catalogue:dedupe --apply --similar')
        ->expectsOutputToContain('No speaker duplicates to merge')
        ->assertSuccessful();
});

it('merges a specific group on demand with --merge', function (): void {
    $survivor = Speaker::factory()->create(['name' => 'Robert Smith', 'slug' => 'robert-smith']);
    $dupe = Speaker::factory()->create(['name' => 'Bob Smith', 'slug' => 'bob-smith']);
    Webinar::factory()->create(['speaker_id' => $dupe->getKey()]);

    $this->artisan("catalogue:dedupe --apply --merge={$survivor->getKey()}:{$dupe->getKey()}")
        ->assertSuccessful();

    expect($dupe->fresh()->trashed())->toBeTrue()
        ->and(Webinar::query()->where('speaker_id', $survivor->getKey())->count())->toBe(1);
});

it('prunes an empty duplicate webinar but never one carrying anything', function (): void {
    $speaker = Speaker::factory()->create();

    // Three webinars, same title. One carries an accreditation (has value), one
    // is a bare empty duplicate, and the survivor is picked by weight.
    $withCredits = Webinar::factory()->create(['title' => 'FMLA Basics', 'slug' => 'fmla-basics', 'speaker_id' => $speaker->getKey()]);
    $withCredits->accreditations()->attach(Accreditation::factory()->create(), ['credits' => 1.0]);

    $empty = Webinar::factory()->create(['title' => 'FMLA Basics', 'slug' => 'fmla-basics-2', 'speaker_id' => $speaker->getKey()]);
    $alsoEmpty = Webinar::factory()->create(['title' => 'fmla  basics', 'slug' => 'fmla-basics-3', 'speaker_id' => $speaker->getKey()]);

    $this->artisan('catalogue:dedupe --apply')->assertSuccessful();

    // The accredited one survives (highest weight); both empty duplicates carry
    // nothing, so both are pruned — a lossless delete. The accredited row is
    // never the one removed.
    expect($withCredits->fresh()->trashed())->toBeFalse()
        ->and($empty->fresh()->trashed())->toBeTrue()
        ->and($alsoEmpty->fresh()->trashed())->toBeTrue()
        ->and(Webinar::withTrashed()->count())->toBe(3)
        ->and(Webinar::query()->count())->toBe(1);

    // The accredited webinar kept its pivot — nothing of value was severed.
    expect(DB::table('webinar_accreditation')->where('webinar_id', $withCredits->getKey())->count())->toBe(1);
});

it('keeps one survivor when every duplicate in a group is empty', function (): void {
    $speaker = Speaker::factory()->create();

    Webinar::factory()->create(['title' => 'Empty Twins', 'slug' => 'empty-twins', 'speaker_id' => $speaker->getKey()]);
    Webinar::factory()->create(['title' => 'empty  twins', 'slug' => 'empty-twins-2', 'speaker_id' => $speaker->getKey()]);

    $this->artisan('catalogue:dedupe --apply')->assertSuccessful();

    // One is pruned, one is always left standing — a group is never wiped out.
    expect(Webinar::query()->count())->toBe(1)
        ->and(Webinar::withTrashed()->count())->toBe(2);
});
