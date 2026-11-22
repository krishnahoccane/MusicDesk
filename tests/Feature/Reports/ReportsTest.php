<?php

namespace Tests\Feature\Reports;

use App\Enums\SystemRole;
use App\Livewire\Reports\Show;
use App\Models\Artist;
use App\Models\ArtistLedgerEntry;
use App\Models\Dsp;
use App\Models\ExportLog;
use App\Models\Release;
use App\Models\RoyaltyPeriod;
use App\Models\User;
use App\Reports\Definitions\ArtistBalancesReport;
use App\Reports\Definitions\ReleasesReport;
use App\Reports\Definitions\RevenueReport;
use App\Reports\ReportExporter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Artist $artist;

    protected Release $release;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->activateLicense();

        $this->artist = Artist::factory()->create(['name' => 'Nova Lights']);
        $this->release = Release::factory()->create(['title' => 'Glass City', 'status' => 'ready', 'release_date' => '2026-10-01']);
        $this->release->artists()->attach($this->artist->id, ['role' => 'primary', 'position' => 1]);
        $this->release->tracks()->create(['title' => 'Glass City', 'isrc' => 'GBAYE2600001', 'position' => 1]);
        $this->release->tracks()->create(['title' => 'No Code', 'position' => 2]);
    }

    protected function seedRoyalties(): RoyaltyPeriod
    {
        $period = RoyaltyPeriod::query()->create(['name' => '2026 Q3', 'starts_on' => '2026-07-01', 'ends_on' => '2026-09-30']);
        $import = DB::table('royalty_imports')->insertGetId([
            'royalty_period_id' => $period->id, 'source_name' => 'Test', 'original_name' => 'x.csv', 'file_path' => 'x.csv',
            'file_hash' => str_repeat('a', 64), 'status' => 'processed', 'currency' => 'INR', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $track = $this->release->tracks()->first();
        foreach ([['Spotify', 'IN', 100, '75.500000'], ['Spotify', 'US', 50, '24.500000'], ['Apple Music', 'IN', 10, '100.000000']] as $i => [$store, $territory, $units, $amount]) {
            DB::table('royalty_lines')->insert([
                'royalty_import_id' => $import, 'royalty_period_id' => $period->id, 'row_number' => $i + 2, 'track_id' => $track->id,
                'release_id' => $this->release->id, 'store' => $store, 'territory' => $territory, 'units' => $units,
                'net_amount' => $amount, 'net_amount_base' => $amount, 'match_status' => 'matched',
            ]);
        }
        DB::table('royalty_earnings')->insert([
            'royalty_period_id' => $period->id, 'artist_id' => $this->artist->id, 'track_id' => $track->id, 'release_id' => $this->release->id,
            'units' => 160, 'revenue' => '200.000000', 'artist_share' => '50.0000', 'artist_amount' => '100.000000', 'label_amount' => '100.000000',
        ]);

        return $period;
    }

    public function test_reports_index_lists_only_permitted_reports(): void
    {
        $this->actingAs($this->admin)->get('/reports')->assertOk()
            ->assertSee('Releases by status and date')->assertSee('Royalty revenue')->assertSee('Artist balances');

        // Viewers have reports.view but no financial permissions.
        $this->actingAs($this->makeUser(SystemRole::Viewer))->get('/reports')->assertOk()
            ->assertSee('Releases by status and date')->assertDontSee('Royalty revenue')->assertDontSee('Contracts ending soon');

        $this->actingAs($this->makeUser(SystemRole::Viewer))->get('/reports/revenue')->assertForbidden();
        $this->actingAs($this->admin)->get('/reports/unknown')->assertNotFound();
    }

    public function test_plan_locked_reports_are_shown_locked_and_refused(): void
    {
        $this->activateLicense('STRT'); // Starter: no advanced_reports

        $this->actingAs($this->admin)->get('/reports')->assertOk()->assertSee('Not included in your license plan');
        $this->actingAs($this->admin)->get('/reports/catalogue-gaps')->assertForbidden();
        $this->actingAs($this->admin)->get('/reports/catalogue-gaps/export/csv')->assertForbidden();
        $this->actingAs($this->admin)->get('/reports/releases')->assertOk();
    }

    public function test_releases_report_filters_by_status(): void
    {
        Release::factory()->create(['title' => 'Draft Thing', 'status' => 'draft']);

        Livewire::actingAs($this->admin)->test(Show::class, ['report' => 'releases'])
            ->assertSee('Glass City')->assertSee('Draft Thing')
            ->set('filters.status', 'ready')
            ->assertSee('Glass City')->assertDontSee('Draft Thing');
    }

    public function test_catalogue_gaps_lists_missing_isrc_and_audio(): void
    {
        Livewire::actingAs($this->admin)->test(Show::class, ['report' => 'catalogue-gaps'])
            ->set('filters.missing', 'isrc')
            ->assertSee('No Code')
            ->assertViewHas('count', 1);
    }

    public function test_revenue_report_totals_are_exact(): void
    {
        $period = $this->seedRoyalties();
        $report = new RevenueReport;

        $byStore = collect($report->rows(['by' => 'store', 'period' => (string) $period->id]))->keyBy('name');
        $this->assertSame('100.000000', $byStore['Spotify']['revenue']);
        $this->assertSame(150, $byStore['Spotify']['units']);
        $this->assertSame('50.00', $byStore['Apple Music']['share']);

        $byTerritory = collect($report->rows(['by' => 'territory', 'period' => '']))->keyBy('name');
        $this->assertSame('175.500000', $byTerritory['IN']['revenue']);

        $byArtist = collect($report->rows(['by' => 'artist', 'period' => '']))->first();
        $this->assertSame('Nova Lights', $byArtist['name']);
        $this->assertSame('100.000000', $byArtist['artist_amount']);
    }

    public function test_artist_balances_report(): void
    {
        foreach ([['earnings', '500'], ['advance', '-200'], ['payment', '-100']] as [$type, $amount]) {
            ArtistLedgerEntry::query()->forceCreate(['artist_id' => $this->artist->id, 'type' => $type, 'amount' => $amount, 'entry_date' => '2026-10-01', 'description' => $type]);
        }

        $row = collect((new ArtistBalancesReport)->rows(['show' => '', 'as_of' => null]))->first();
        $this->assertSame('200.000000', $row['balance']);
        $this->assertSame('200.000000', $row['recoupable']);
        $this->assertSame('100.000000', $row['paid']);

        $this->assertCount(0, iterator_to_array((new ArtistBalancesReport)->rows(['show' => 'unrecouped', 'as_of' => null])));
        $this->assertCount(0, iterator_to_array((new ArtistBalancesReport)->rows(['show' => '', 'as_of' => '2026-09-30'])));
    }

    public function test_store_summary_and_contracts_reports(): void
    {
        $dsp = Dsp::query()->create(['name' => 'Spotify']);
        $this->release->deliveries()->forceCreate(['dsp_id' => $dsp->id, 'status' => 'live']);
        $this->artist->contracts()->create(['title' => 'Old deal', 'type' => 'recording', 'status' => 'active', 'start_date' => '2025-01-01', 'end_date' => now()->addDays(20)->toDateString()]);

        Livewire::actingAs($this->admin)->test(Show::class, ['report' => 'stores'])->assertSee('Spotify')->assertViewHas('count', 1);
        Livewire::actingAs($this->admin)->test(Show::class, ['report' => 'contracts-ending'])
            ->assertSee('Old deal')
            ->set('filters.within', '30')->assertSee('Old deal');
    }

    public function test_csv_export_is_streamed_and_logged(): void
    {
        $this->seedRoyalties();

        $response = $this->actingAs($this->admin)->get('/reports/revenue/export/csv?f[by]=store');
        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();

        $this->assertStringContainsString('Store,Units,"Net revenue","Share of total"', $csv);
        $this->assertStringContainsString('Spotify,150,100,', $csv);
        $this->assertStringContainsString('Total,160,200,100', $csv);

        $log = ExportLog::query()->sole();
        $this->assertSame('revenue', $log->report);
        $this->assertSame(['by' => 'store'], $log->filters);
        $this->assertSame(2, $log->row_count);
        $this->assertSame($this->admin->id, $log->user_id);
    }

    public function test_xlsx_export_writes_a_workbook(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ldx').'.xlsx';
        $count = app(ReportExporter::class)->write(app(ReleasesReport::class), [], 'xlsx',
            app(ReleasesReport::class)->columns([]), $path);

        $this->assertSame(1, $count);
        $this->assertStringStartsWith('PK', (string) file_get_contents($path, false, null, 0, 2));
        @unlink($path);
    }

    public function test_export_needs_the_export_permission(): void
    {
        // Viewers may view reports but not export them.
        $viewer = $this->makeUser(SystemRole::Viewer);
        $this->actingAs($viewer)->get('/reports/releases')->assertOk()->assertDontSee('Excel (.xlsx)');
        $this->actingAs($viewer)->get('/reports/releases/export/csv')->assertForbidden();
        $this->assertSame(0, ExportLog::query()->count());
    }

    public function test_unknown_filter_values_are_ignored(): void
    {
        Livewire::actingAs($this->admin)->test(Show::class, ['report' => 'revenue'])
            ->set('filters.by', 'password')
            ->assertViewHas('columns', fn ($c) => isset($c['share']) && $c['name']['label'] === 'Period');
    }
}
