<?php

namespace Tests\Feature\Royalties;

use App\Enums\SystemRole;
use App\Livewire\Reports\SalesImports;
use App\Models\RoyaltyPeriod;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SalesImportPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_report_import_page(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->activateLicense();

        $this->actingAs($admin)->get('/reports/import')->assertOk()->assertSee('Import sales reports');
        $this->actingAs($this->makeUser(SystemRole::Viewer))->get('/reports/import')->assertForbidden();

        Livewire::actingAs($admin)->test(SalesImports::class)
            ->call('startCreating')
            ->set('name', '2026 October')->set('starts_on', '2026-10-01')->set('ends_on', '2026-10-31')
            ->call('createPeriod')
            ->assertHasNoErrors();

        $period = RoyaltyPeriod::query()->where('name', '2026 October')->sole();
        $this->actingAs($admin)->get('/reports/import?period='.$period->id)->assertOk()->assertSee('Upload, map columns and process');
        $this->actingAs($admin)->get('/reports')->assertSee(route('reports.import'), false);
    }
}
