<?php

namespace Tests\Feature\Royalties;

use App\Enums\SystemRole;
use App\Exceptions\CatalogException;
use App\Livewire\Artists\Earnings;
use App\Livewire\Royalties\Imports;
use App\Livewire\Royalties\Index;
use App\Livewire\Royalties\PeriodShow;
use App\Models\Artist;
use App\Models\ArtistLedgerEntry;
use App\Models\Release;
use App\Models\RoyaltyImport;
use App\Models\RoyaltyPeriod;
use App\Models\User;
use App\Services\Royalties\ArtistAccountService;
use App\Services\Royalties\RoyaltyCalculator;
use App\Services\Royalties\RoyaltyImportService;
use App\Services\Royalties\RoyaltyPeriodService;
use Database\Seeders\CatalogReferenceSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\CreatesCatalog;
use Tests\TestCase;

class RoyaltyAccountTest extends TestCase
{
    use CreatesCatalog, RefreshDatabase;

    protected User $accountant;

    protected Artist $artist;

    protected RoyaltyPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, CatalogReferenceSeeder::class]);
        $this->activateLicense();
        Storage::fake('local');
        config(['labeldesk.currency' => 'USD']);

        $this->accountant = $this->makeUser(SystemRole::Accountant);
        $this->artist = Artist::factory()->create(['name' => 'Nova Lights']);
        $this->artist->contracts()->create(['title' => 'Deal', 'type' => 'recording', 'status' => 'active', 'start_date' => '2026-01-01', 'artist_share' => '50']);
        $release = Release::factory()->create();
        $release->artists()->sync([$this->artist->id => ['role' => 'primary', 'position' => 1]]);
        $track = $release->tracks()->create(['title' => 'Glass City', 'isrc' => 'GBAYE2600001', 'position' => 1]);
        $track->artists()->sync([$this->artist->id => ['role' => 'primary', 'position' => 1]]);

        $this->period = RoyaltyPeriod::query()->create(['name' => '2026 Q3', 'starts_on' => '2026-07-01', 'ends_on' => '2026-09-30']);
    }

    protected function tearDown(): void
    {
        $this->cleanUploads();
        parent::tearDown();
    }

    /** Imports net revenue for the track into a period (50 % goes to the artist). */
    protected function importRevenue(RoyaltyPeriod $period, string $net): RoyaltyImport
    {
        $service = app(RoyaltyImportService::class);
        $path = storage_path('framework/testing/upload-'.uniqid().'.csv');
        file_put_contents($path, "ISRC,Net\nGBAYE2600001,{$net}\n");
        $this->tempUploads[] = $path;

        $import = $service->upload($this->accountant, $period, new UploadedFile($path, 'sales.csv', null, null, true), 'Distributor', 'USD', '1');
        $service->configure($this->accountant, $import->load('period'), ['isrc' => 0, 'amount' => 1], '.');

        return $import->refresh();
    }

    public function test_periods_cannot_overlap_and_only_empty_ones_can_be_deleted(): void
    {
        Livewire::actingAs($this->accountant)->test(Index::class)
            ->call('startCreating')
            ->set('name', 'Overlap')->set('starts_on', '2026-09-01')->set('ends_on', '2026-12-31')
            ->call('create')->assertHasErrors('starts_on');

        Livewire::actingAs($this->accountant)->test(Index::class)
            ->call('startCreating')
            ->set('name', '2026 Q4')->set('starts_on', '2026-10-01')->set('ends_on', '2026-12-31')
            ->call('create')->assertHasNoErrors();

        $this->importRevenue($this->period, '10');
        $this->expectException(CatalogException::class);
        app(RoyaltyPeriodService::class)->delete($this->accountant, $this->period);
    }

    public function test_locking_posts_earnings_and_freezes_the_period(): void
    {
        $this->importRevenue($this->period, '100.00');

        Livewire::actingAs($this->accountant)->test(PeriodShow::class, ['period' => $this->period])->call('lock');

        $this->assertTrue($this->period->fresh()->isLocked());
        $entry = ArtistLedgerEntry::query()->sole();
        $this->assertSame('earnings', $entry->type->value);
        $this->assertSame('50.000000', (string) $entry->amount);
        $this->assertSame('2026-09-30', $entry->entry_date->toDateString());

        // Nothing in a locked period can change.
        Livewire::actingAs($this->accountant)->test(Imports::class, ['period' => $this->period->fresh()])
            ->call('revert', RoyaltyImport::query()->sole()->id)->assertDispatched('toast', type: 'danger');
        $this->expectException(CatalogException::class);
        app(RoyaltyCalculator::class)->recalculate($this->period->fresh());
    }

    public function test_lock_waits_for_unmapped_imports(): void
    {
        $path = storage_path('framework/testing/upload-'.uniqid().'.csv');
        file_put_contents($path, "ISRC,Net\nGBAYE2600001,1\n");
        $this->tempUploads[] = $path;
        app(RoyaltyImportService::class)->upload($this->accountant, $this->period, new UploadedFile($path, 'a.csv', null, null, true), 'X', 'USD', '1');

        Livewire::actingAs($this->accountant)->test(PeriodShow::class, ['period' => $this->period])->call('lock')->assertDispatched('toast', type: 'danger');
        $this->assertFalse($this->period->fresh()->isLocked());
    }

    public function test_advances_are_recouped_and_payments_cannot_exceed_the_balance(): void
    {
        $accounts = app(ArtistAccountService::class);

        // 500 advance → balance -500.
        $accounts->record($this->accountant, $this->artist, ['type' => 'advance', 'amount' => '500', 'entry_date' => '2026-06-01', 'description' => 'Signing advance']);
        $this->assertSame('-500.000000', ArtistAccountService::balance($this->artist));

        // Q3 earns 600 (1200 × 50 %) → balance +100 after recoupment.
        $this->importRevenue($this->period, '1200');
        app(RoyaltyPeriodService::class)->lock($this->accountant, $this->period);
        $this->assertSame('100.000000', ArtistAccountService::balance($this->artist));

        // Paying more than is owed is refused; paying the balance works.
        Livewire::actingAs($this->accountant)->test(Earnings::class, ['artist' => $this->artist])
            ->call('startRecording', 'payment')
            ->set('amount', '100.01')->set('description', 'Q3 payment')
            ->call('record')->assertHasErrors('amount')
            ->set('amount', '100')->call('record')->assertHasNoErrors();
        $this->assertSame('0.000000', ArtistAccountService::balance($this->artist));
        $this->assertDatabaseHas('activity_logs', ['event' => 'royalty.ledger_payment']);
    }

    public function test_entries_are_reversed_not_deleted(): void
    {
        $accounts = app(ArtistAccountService::class);
        $expense = $accounts->record($this->accountant, $this->artist, ['type' => 'expense', 'amount' => '75.50', 'entry_date' => '2026-07-10', 'description' => 'Video shoot']);

        Livewire::actingAs($this->accountant)->test(Earnings::class, ['artist' => $this->artist])->call('reverse', $expense->id);
        $this->assertSame('0.000000', ArtistAccountService::balance($this->artist));
        $this->assertSame(2, ArtistLedgerEntry::query()->count());

        $this->expectException(CatalogException::class);
        $accounts->reverse($this->accountant, $expense->fresh());
    }

    public function test_statement_shows_balances_and_exports(): void
    {
        app(ArtistAccountService::class)->record($this->accountant, $this->artist, ['type' => 'advance', 'amount' => '20', 'entry_date' => '2026-06-15', 'description' => 'Advance']);
        $this->importRevenue($this->period, '100');

        // Provisional while open.
        $this->actingAs($this->accountant)->get(route('royalties.statements.show', [$this->period, $this->artist]))
            ->assertOk()->assertSee('Provisional')->assertSee('Glass City')->assertSee('50.00 USD');

        app(RoyaltyPeriodService::class)->lock($this->accountant, $this->period);

        $page = $this->actingAs($this->accountant)->get(route('royalties.statements.show', [$this->period, $this->artist]))->assertOk();
        $page->assertDontSee('Provisional')->assertSee('-20.00 USD')->assertSee('30.00 USD');   // opening, closing

        $csv = $this->actingAs($this->accountant)->get(route('royalties.statements.export', [$this->period, $this->artist, 'csv']));
        $csv->assertOk()->assertDownload();
        $content = $csv->streamedContent();
        $this->assertStringContainsString('Glass City', $content);
        $this->assertStringContainsString('50.00', $content);
        $this->assertStringContainsString('30.00', $content);

        $this->actingAs($this->accountant)->get(route('royalties.statements.export', [$this->period, $this->artist, 'xlsx']))->assertOk()->assertDownload();
        $this->actingAs($this->accountant)->get('/royalties/periods/'.$this->period->id.'/statements/'.$this->artist->id.'/export/pdf')->assertNotFound();
    }

    public function test_account_permissions(): void
    {
        $artistManager = $this->makeUser(SystemRole::ArtistManager);   // no payments.*
        $viewer = $this->makeUser(SystemRole::Viewer);

        $this->actingAs($artistManager)->get('/royalties/balances')->assertForbidden();
        Livewire::actingAs($artistManager)->test(Earnings::class, ['artist' => $this->artist])->assertForbidden();
        $this->actingAs($viewer)->get(route('royalties.statements.show', [$this->period, $this->artist]))->assertForbidden();

        // Label Managers can view balances and record payments.
        $manager = $this->makeUser(SystemRole::LabelManager);
        $this->actingAs($manager)->get('/royalties/balances')->assertOk();
        Livewire::actingAs($manager)->test(Earnings::class, ['artist' => $this->artist])
            ->call('startRecording', 'advance')->set('amount', '10')->set('description', 'Advance')->call('record')->assertHasNoErrors();
    }
}
