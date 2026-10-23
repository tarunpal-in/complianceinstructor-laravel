<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Resources\Accreditations\Pages\EditAccreditation;
use App\Models\Accreditation;
use App\Models\Media;
use App\Models\MediaFolder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/*
 * The accreditation logo is picked from the media library (MediaPicker →
 * logo_media_id), not uploaded on the record — the same reference model webinar
 * images use. This covers the form linking a library file and the logo resolving.
 */

it('links a library logo through the accreditation form', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $media = MediaFolder::uncategorised()
        ->addMedia(UploadedFile::fake()->image('hrci.png', 240, 120))
        ->toMediaCollection(Media::LIBRARY_COLLECTION);

    $acc = Accreditation::factory()->create();

    Livewire::test(EditAccreditation::class, ['record' => $acc->getRouteKey()])
        ->set('data.logo_media_id', $media->getKey())
        ->call('save')
        ->assertHasNoFormErrors();

    expect($acc->fresh()->logo_media_id)->toBe($media->getKey())
        ->and($acc->fresh()->logoUrl())->not->toBeNull();
});
