<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Resources\Accreditations\Pages\EditAccreditation;
use App\Models\Accreditation;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

/*
 * The MediaPicker holds its whole Alpine component in one x-data="{…}" attribute.
 * A stray double quote inside it (a "quoted" word in a comment, say) silently
 * ends the attribute, so the rest of the expression leaks onto the page as text
 * and Alpine never initialises — the modal renders blank. Non-browser tests do
 * not run the JS, so this asserts the structural invariant instead: the picker's
 * methods must sit inside an x-data value that contains no double quote.
 */

it('renders the media picker x-data as one unbroken attribute', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->actingAs(userWithRole(UserRole::SuperAdmin));

    $acc = Accreditation::factory()->create();

    $html = Livewire::test(EditAccreditation::class, ['record' => $acc->getRouteKey()])->html();

    // From x-data="{ up to the closing quote there must be no " — and the
    // picker's own methods must fall within that span. If a quote broke the
    // attribute early, openModal()/onUploaded() would leak out as text and the
    // tempered match would never reach them.
    expect($html)->toMatch('/x-data="\{(?:(?!").)*openModal\(\)(?:(?!").)*onUploaded\(item\)/s');
});
