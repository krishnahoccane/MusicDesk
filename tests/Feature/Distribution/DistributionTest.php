<?php

namespace Tests\Feature\Distribution;

use App\Enums\SystemRole;
use App\Exceptions\CatalogException;
use App\Livewire\Distribution\Dsps;
use App\Livewire\Distribution\Index;
use App\Livewire\Releases\Distribution;
use App\Livewire\Releases\Show;
use App\Models\Dsp;
use App\Models\LicenseActivation;
use App\Models\Release;
use App\Models\ReleaseDelivery;
use App\Models\User;
use App\Services\Catalog\ReleaseWorkflow;
use App\Services\Distribution\DistributionService;
use App\Services\License\LicenseValidationService;
use Database\Seeders\CatalogReferenceSeeder;
use Database\Seeders\DspSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CreatesCatalog;
use Tests\TestCase;

class DistributionTest extends TestCase
{
    use CreatesCatalog, RefreshDatabase;

    protected User $manager;

    protected Release $release;

    protected Dsp $spotify;

    protected Dsp $apple;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, CatalogReferenceSeeder::class, DspSeeder::class]);
        $this->activateLicense();
        Storage::fake('local');

        $this->manager = $this->makeUser(SystemRole::DistributionManager);
        $this->release = $this->completeRelease();
        $this->release->forceFill(['status' => 'ready'])->save();
        $this->spotify = Dsp::query()->where('name', 'Spotify')->firstOrFail();
        $this->apple = Dsp::query()->where('name', 'Apple Music')->firstOrFail();
    }

    protected function tab(?User $user = null): Testable
    {
        return Livewire::actingAs($user ?? $this->manager)->test(Distribution::class, ['release' => $this->release]);
    }

    protected function deliveryFor(Dsp $dsp): ReleaseDelivery
    {
        return ReleaseDelivery::query()->where('release_id', $this->release->id)->where('dsp_id', $dsp->id)->firstOrFail();
    }

    public function test_store_list_is_seeded_idempotently(): void
    {
        $this->seed(DspSeeder::class);
        $this->assertSame(count(DspSeeder::DSPS), Dsp::query()->count());
    }

    public function test_permission_boundaries(): void
    {
        $viewer = $this->makeUser(SystemRole::Viewer);           // distribution.view
        $accountant = $this->makeUser(SystemRole::Accountant);   // no distribution
        $artistManager = $this->makeUser(SystemRole::ArtistManager);

        $this->actingAs($viewer)->get('/distribution')->assertOk();
        $this->actingAs($accountant)->get('/distribution')->assertForbidden();
        $this->actingAs($viewer)->get('/distribution/stores')->assertForbidden();
        $this->actingAs($this->manager)->get('/distribution/stores')->assertOk()->assertSee('Spotify');

        $this->tab($viewer)->call('startAdding')->assertForbidden();
        $this->tab($artistManager)->set('selectedDsps', [$this->spotify->id])->call('add')->assertForbidden();
        $this->assertSame(0, ReleaseDelivery::query()->count());
    }

    public function test_only_ready_releases_can_be_sent_to_stores(): void
    {
        $this->release->forceFill(['status' => 'approved'])->save();

        $this->tab()->call('startAdding')->set('selectedDsps', [$this->spotify->id])->call('add')->assertDispatched('toast', type: 'danger');
        $this->assertSame(0, ReleaseDelivery::query()->count());
    }

    public function test_stores_are_added_once_and_inactive_stores_are_skipped(): void
    {
        $this->apple->update(['is_active' => false]);

        $this->tab()->call('startAdding')->set('selectedDsps', [$this->spotify->id, $this->apple->id])->call('add');
        $this->tab()->call('startAdding')->set('selectedDsps', [$this->spotify->id])->call('add');

        $this->assertSame([$this->spotify->id], ReleaseDelivery::query()->pluck('dsp_id')->all());
        $delivery = $this->deliveryFor($this->spotify);
        $this->assertSame('pending', $delivery->status->value);
        $this->assertSame(1, $delivery->events()->count());
        $this->assertDatabaseHas('activity_logs', ['event' => 'distribution.added']);
    }

    public function test_full_delivery_lifecycle_with_history(): void
    {
        $service = app(DistributionService::class);
        $service->addDeliveries($this->manager, $this->release, [$this->spotify->id]);
        $delivery = $this->deliveryFor($this->spotify);
        $yesterday = now()->setTimezone(config('labeldesk.display_timezone'))->subDay()->format('Y-m-d');

        // Deliver (back-dated), then live with store link.
        $this->tab()->call('openPanel', $delivery->id, 'deliver')->set('date', $yesterday)->call('submitPanel')->assertHasNoErrors();
        $this->assertSame('delivered', $delivery->fresh()->status->value);
        $this->assertSame($yesterday, $delivery->fresh()->delivered_on->format('Y-m-d'));

        $this->tab()->call('openPanel', $delivery->id, 'go_live')
            ->set('store_url', 'javascript:alert(1)')->call('submitPanel')->assertHasErrors('store_url')
            ->set('store_url', 'https://open.spotify.com/album/abc')->set('store_id', 'abc')->call('submitPanel')->assertHasNoErrors();
        $fresh = $delivery->fresh();
        $this->assertSame('live', $fresh->status->value);
        $this->assertSame('https://open.spotify.com/album/abc', $fresh->store_url);
        $this->assertNotNull($fresh->live_on);

        // Takedown requires a reason; can be cancelled or confirmed.
        $this->tab()->call('openPanel', $delivery->id, 'request_takedown')->call('submitPanel')->assertHasErrors('comment');
        $this->tab()->call('openPanel', $delivery->id, 'request_takedown')->set('comment', 'Artist request')->call('submitPanel')->assertHasNoErrors();
        $this->assertSame('takedown_requested', $delivery->fresh()->status->value);
        $this->tab()->call('openPanel', $delivery->id, 'confirm_takedown')->call('submitPanel')->assertHasNoErrors();
        $this->assertSame('taken_down', $delivery->fresh()->status->value);

        $this->assertSame(
            ['pending', 'delivered', 'live', 'takedown_requested', 'taken_down'],
            $delivery->events()->get()->reverse()->map(fn ($e) => $e->to_status->value)->values()->all(),
        );
        $this->assertSame('Artist request', $delivery->events()->where('to_status', 'takedown_requested')->value('comment'));
        $this->assertDatabaseHas('activity_logs', ['event' => 'distribution.confirm_takedown']);
    }

    public function test_invalid_steps_and_future_dates_are_refused(): void
    {
        $service = app(DistributionService::class);
        $service->addDeliveries($this->manager, $this->release, [$this->spotify->id]);
        $delivery = $this->deliveryFor($this->spotify);

        // Pending cannot go live directly (UI refuses to open the step).
        $this->tab()->call('openPanel', $delivery->id, 'go_live')->assertForbidden();

        try {
            $service->transition($this->manager, $delivery, 'go_live');
            $this->fail('Expected refusal.');
        } catch (CatalogException) {
            $this->assertSame('pending', $delivery->fresh()->status->value);
        }

        $tomorrow = now()->setTimezone(config('labeldesk.display_timezone'))->addDays(2)->format('Y-m-d');
        $this->tab()->call('openPanel', $delivery->id, 'deliver')->set('date', $tomorrow)->call('submitPanel')->assertDispatched('toast', type: 'danger');
        $this->assertSame('pending', $delivery->fresh()->status->value);
    }

    public function test_bulk_delivery_and_removing_pending_stores(): void
    {
        $service = app(DistributionService::class);
        $service->addDeliveries($this->manager, $this->release, [$this->spotify->id, $this->apple->id]);

        $this->tab()->call('openPanel', 'all', 'deliver')->call('submitPanel')->assertHasNoErrors();
        $this->assertSame(['delivered', 'delivered'], ReleaseDelivery::query()->pluck('status')->map->value->all());

        // Delivered stores cannot simply be removed.
        $this->tab()->call('remove', $this->deliveryFor($this->spotify)->id)->assertDispatched('toast', type: 'danger');
        $this->assertSame(2, ReleaseDelivery::query()->count());

        $deezer = Dsp::query()->where('name', 'Deezer')->first();
        $service->addDeliveries($this->manager, $this->release, [$deezer->id]);
        $this->tab()->call('remove', $this->deliveryFor($deezer)->id);
        $this->assertSame(2, ReleaseDelivery::query()->count());
    }

    public function test_release_cannot_be_reopened_while_in_stores(): void
    {
        $service = app(DistributionService::class);
        $service->addDeliveries($this->manager, $this->release, [$this->spotify->id]);
        $delivery = $this->deliveryFor($this->spotify);
        $service->transition($this->manager, $delivery, 'deliver');

        // The page no longer offers "Reopen", and the service refuses it too.
        Livewire::actingAs($this->manager)->test(Show::class, ['release' => $this->release])
            ->assertDontSee('Reopen as draft')
            ->call('act', 'reopen')->assertForbidden();
        try {
            app(ReleaseWorkflow::class)->transition($this->manager, $this->release, 'reopen', 'Fix typo');
            $this->fail('Expected refusal.');
        } catch (CatalogException) {
            $this->assertSame('ready', $this->release->fresh()->status->value);
        }

        $service->transition($this->manager, $delivery->fresh(), 'request_takedown', 'Fix metadata');
        $service->transition($this->manager, $delivery->fresh(), 'confirm_takedown');

        app(ReleaseWorkflow::class)->transition($this->manager, $this->release->fresh(), 'reopen', 'Fix typo');
        $this->assertSame('draft', $this->release->fresh()->status->value);
    }

    public function test_store_management(): void
    {
        Livewire::actingAs($this->manager)->test(Dsps::class)
            ->call('create')->set('name', 'Spotify')->call('save')->assertHasErrors('name')
            ->set('name', 'Local Radio Store')->set('website', 'ftp://nope')->call('save')->assertHasErrors('website')
            ->set('website', 'https://radio.example')->set('lead_time_days', 5)->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('dsps', ['name' => 'Local Radio Store', 'lead_time_days' => 5]);

        // A store with deliveries cannot be deleted (deactivate instead).
        app(DistributionService::class)->addDeliveries($this->manager, $this->release, [$this->spotify->id]);
        Livewire::actingAs($this->manager)->test(Dsps::class)->call('delete', $this->spotify->id)->assertDispatched('toast', type: 'danger');
        $this->assertModelExists($this->spotify);

        Livewire::actingAs($this->manager)->test(Dsps::class)->call('toggleActive', $this->spotify->id);
        $this->assertFalse($this->spotify->fresh()->is_active);
    }

    public function test_overview_filters_and_ready_releases(): void
    {
        $other = $this->completeRelease();
        $other->forceFill(['status' => 'ready', 'title' => 'Waiting Room'])->save();

        app(DistributionService::class)->addDeliveries($this->manager, $this->release, [$this->spotify->id, $this->apple->id]);
        app(DistributionService::class)->transition($this->manager, $this->deliveryFor($this->spotify), 'deliver');

        $stores = fn ($deliveries) => collect($deliveries->items())->map(fn ($d) => $d->dsp->name)->sort()->values()->all();

        Livewire::actingAs($this->manager)->test(Index::class)
            ->assertSee('Waiting Room')                       // ready, not sent anywhere
            ->set('status', 'delivered')->assertViewHas('deliveries', fn ($d) => $stores($d) === ['Spotify'])
            ->set('status', '')->set('dsp', (string) $this->apple->id)->assertViewHas('deliveries', fn ($d) => $stores($d) === ['Apple Music']);
    }

    public function test_module_requires_the_license_feature(): void
    {
        LicenseActivation::query()->update(['status' => 'deactivated']);
        app(LicenseValidationService::class)->forget();

        $this->actingAs($this->manager)->get('/distribution')->assertForbidden();
    }
}
