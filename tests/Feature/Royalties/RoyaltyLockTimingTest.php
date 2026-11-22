<?php

namespace Tests\Feature\Royalties;

use App\Enums\SystemRole;
use App\Exceptions\CatalogException;
use App\Models\RoyaltyPeriod;
use App\Services\Royalties\RoyaltyPeriodService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoyaltyLockTimingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_period_cannot_be_locked_before_it_has_ended(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->activateLicense();
        $user = $this->makeUser(SystemRole::Accountant);

        $today = now()->setTimezone(config('labeldesk.display_timezone'));
        $current = RoyaltyPeriod::query()->create([
            'name' => 'Current', 'starts_on' => $today->copy()->startOfMonth()->toDateString(), 'ends_on' => $today->toDateString(),
        ]);

        try {
            app(RoyaltyPeriodService::class)->lock($user, $current);
            $this->fail('Expected refusal.');
        } catch (CatalogException) {
            $this->assertFalse($current->fresh()->isLocked());
        }

        $past = RoyaltyPeriod::query()->create(['name' => 'Past', 'starts_on' => '2020-01-01', 'ends_on' => '2020-03-31']);
        app(RoyaltyPeriodService::class)->lock($user, $past);
        $this->assertTrue($past->fresh()->isLocked());
    }
}
