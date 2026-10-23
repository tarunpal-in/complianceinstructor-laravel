<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Resources\Webinars\Pages\EditWebinar;
use App\Filament\Resources\Webinars\RelationManagers\AssetsRelationManager;
use App\Filament\Resources\Webinars\RelationManagers\HandoutsRelationManager;
use App\Models\Webinar;
use App\Models\WebinarAsset;
use App\Models\WebinarHandout;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

/*
 * Deliverables and handouts are the webinar's, so every mutating action on them
 * is gated on `update_webinar` — §4 #4 / §9. A view-only role (Support holds
 * `view_webinar` but not `update_webinar`) may look but not change or delete.
 */

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->webinar = Webinar::factory()->create();
});

function handoutsManagerAs(UserRole $role, Webinar $webinar): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::actingAs(userWithRole($role))->test(HandoutsRelationManager::class, [
        'ownerRecord' => $webinar,
        'pageClass' => EditWebinar::class,
    ]);
}

function assetsManagerAs(UserRole $role, Webinar $webinar): \Livewire\Features\SupportTesting\Testable
{
    return Livewire::actingAs(userWithRole($role))->test(AssetsRelationManager::class, [
        'ownerRecord' => $webinar,
        'pageClass' => EditWebinar::class,
    ]);
}

// Handouts --------------------------------------------------------------------

it('withholds every mutating handout action from a view-only role', function (): void {
    $handout = WebinarHandout::factory()->for($this->webinar)->create();

    handoutsManagerAs(UserRole::Support, $this->webinar)
        ->assertCanSeeTableRecords([$handout]) // can look…
        ->assertTableActionHidden('create')    // …but not add,
        ->assertTableActionHidden('edit', $handout)   // change,
        ->assertTableActionHidden('delete', $handout); // or delete
});

it('lets a content editor manage handouts', function (): void {
    $handout = WebinarHandout::factory()->for($this->webinar)->create();

    handoutsManagerAs(UserRole::ContentEditor, $this->webinar)
        ->assertTableActionVisible('create')
        ->assertTableActionVisible('edit', $handout)
        ->assertTableActionVisible('delete', $handout);
});

// Deliverables ----------------------------------------------------------------

it('withholds every mutating deliverable action from a view-only role', function (): void {
    $asset = WebinarAsset::factory()->for($this->webinar)->create();

    assetsManagerAs(UserRole::Support, $this->webinar)
        ->assertCanSeeTableRecords([$asset])
        ->assertTableActionHidden('create')
        ->assertTableActionHidden('edit', $asset)
        ->assertTableActionHidden('delete', $asset);
});

it('lets a content editor manage deliverables', function (): void {
    $asset = WebinarAsset::factory()->for($this->webinar)->create();

    assetsManagerAs(UserRole::ContentEditor, $this->webinar)
        ->assertTableActionVisible('create')
        ->assertTableActionVisible('edit', $asset)
        ->assertTableActionVisible('delete', $asset);
});
