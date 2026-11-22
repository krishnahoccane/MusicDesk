<?php

namespace Tests\Feature\Royalties;

use App\Enums\SystemRole;
use App\Livewire\Royalties\Imports;
use App\Livewire\Royalties\Unmatched;
use App\Models\Artist;
use App\Models\Release;
use App\Models\RoyaltyEarning;
use App\Models\RoyaltyImport;
use App\Models\RoyaltyLine;
use App\Models\RoyaltyPeriod;
use App\Models\Track;
use App\Models\User;
use App\Services\Royalties\RoyaltyCalculator;
use App\Services\SettingsService;
use App\Support\Decimal;
use Database\Seeders\CatalogReferenceSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Tests\Support\CreatesCatalog;
use Tests\TestCase;

class RoyaltyImportTest extends TestCase
{
    use CreatesCatalog, RefreshDatabase;

    protected User $accountant;

    protected RoyaltyPeriod $period;

    protected Artist $nova;

    protected Release $release;

    protected Track $glass;

    protected Track $neon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, CatalogReferenceSeeder::class]);
        $this->activateLicense();
        Storage::fake('local');
        config(['labeldesk.currency' => 'USD']);

        $this->accountant = $this->makeUser(SystemRole::Accountant);
        $this->period = RoyaltyPeriod::query()->create(['name' => '2026 Q3', 'starts_on' => '2026-07-01', 'ends_on' => '2026-09-30']);

        $this->nova = Artist::factory()->create(['name' => 'Nova Lights']);
        $this->nova->contracts()->create(['title' => 'Deal', 'type' => 'recording', 'status' => 'active', 'start_date' => '2026-01-01', 'artist_share' => '70']);

        $this->release = Release::factory()->create(['upc' => '036000291452']);
        $this->release->artists()->sync([$this->nova->id => ['role' => 'primary', 'position' => 1]]);
        $this->glass = $this->release->tracks()->create(['title' => 'Glass City', 'isrc' => 'GBAYE2600001', 'position' => 1]);
        $this->neon = $this->release->tracks()->create(['title' => 'Neon', 'isrc' => 'GBAYE2600002', 'position' => 2]);
        foreach ([$this->glass, $this->neon] as $track) {
            $track->artists()->sync([$this->nova->id => ['role' => 'primary', 'position' => 1]]);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanUploads();
        parent::tearDown();
    }

    protected function csv(array $rows, string $sep = ','): string
    {
        return implode("\n", array_map(fn ($r) => implode($sep, $r), $rows))."\n";
    }

    /**
     * Upload + map (guessed) + process through the UI component.
     */
    protected function import(string $name, string $bytes, string $currency = 'USD', string $rate = '1', string $separator = '.'): RoyaltyImport
    {
        $component = Livewire::actingAs($this->accountant)->test(Imports::class, ['period' => $this->period])
            ->call('startUpload')
            ->set('file', $this->upload($name, $bytes))
            ->set('source_name', 'Distributor')
            ->set('currency', $currency)
            ->set('exchange_rate', $rate)
            ->call('upload')
            ->assertHasNoErrors();

        $component->set('decimal_separator', $separator)->call('confirmMapping')->assertHasNoErrors();

        return RoyaltyImport::query()->latest('id')->firstOrFail();
    }

    public function test_csv_import_matches_and_allocates_exactly(): void
    {
        $import = $this->import('q3.csv', $this->csv([
            ['ISRC', 'UPC', 'Store', 'Country', 'Quantity', 'Net Revenue', 'Sale Date'],
            ['GB-AYE-26-00001', '', 'Spotify', 'IN', '1000', '3.330000', '2026-07-15'],
            ['GBAYE2600001', '', 'Apple Music', 'US', '10', '1.01', '2026-08-01'],
            ['', '036000291452', 'iTunes', 'GB', '1', '0.99', ''],              // release-level (UPC)
            ['XXAAA2600099', '', 'Deezer', 'FR', '5', '0.50', '2026-08-01'],     // not ours / unknown
            ['', '', 'Total', '', '', '5.83', ''],                                // sub-total row: skipped
        ]));

        $this->assertSame('completed', $import->status->value);
        $this->assertSame(4, $import->total_rows);
        $this->assertSame(3, $import->matched_rows);
        $this->assertSame(1, $import->skipped_rows);
        $this->assertSame('5.830000', (string) $import->net_total);

        $lines = RoyaltyLine::query()->orderBy('row_number')->get();
        $this->assertSame($this->glass->id, $lines[0]->track_id);
        $this->assertSame('2026-07-15', $lines[0]->sale_date->toDateString());
        $this->assertSame($this->release->id, $lines[2]->release_id);
        $this->assertNull($lines[2]->track_id);
        $this->assertSame('2026-09-30', $lines[2]->sale_date->toDateString());  // falls back to period end
        $this->assertSame('unmatched', $lines[3]->match_status);

        // Matched revenue 3.33 + 1.01 + 0.99 = 5.33; artist share 70%.
        $earnings = RoyaltyEarning::query()->get();
        $this->assertSame('5.330000', Decimal::sum($earnings->map(fn ($e) => (string) $e->revenue)));
        $this->assertSame('3.731000', Decimal::sum($earnings->map(fn ($e) => (string) $e->artist_amount)));
        $this->assertSame('1.599000', Decimal::sum($earnings->map(fn ($e) => (string) $e->label_amount)));
        $this->assertSame(1010, (int) $earnings->where('track_id', $this->glass->id)->sum('units'));   // UPC row is release-level
        $this->assertSame(1, (int) $earnings->whereNull('track_id')->sum('units'));
        $this->assertSame('IN', $lines[0]->territory);
        $this->assertDatabaseHas('activity_logs', ['event' => 'royalty.import_processed']);
    }

    public function test_many_tiny_amounts_add_up_exactly(): void
    {
        $rows = [['ISRC', 'Net']];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = ['GBAYE2600001', '0.001'];
        }
        $import = $this->import('tiny.csv', $this->csv($rows));

        $this->assertSame('1.000000', (string) $import->net_total);
        $earning = RoyaltyEarning::query()->sole();
        $this->assertSame('1.000000', (string) $earning->revenue);
        $this->assertSame('0.700000', (string) $earning->artist_amount);
        $this->assertSame('0.300000', (string) $earning->label_amount);
    }

    public function test_semicolon_csv_with_decimal_comma_and_exchange_rate(): void
    {
        $import = $this->import('eu.csv', $this->csv([
            ['ISRC', 'Payable (EUR)'],
            ['GBAYE2600002', '1.234,50'],
        ], ';'), 'EUR', '1.0850', ',');

        $this->assertSame('1234.500000', (string) $import->net_total);
        $this->assertSame('1339.432500', (string) $import->net_total_base);
        $this->assertSame('937.602750', (string) RoyaltyEarning::query()->sole()->artist_amount);
    }

    public function test_xlsx_import(): void
    {
        $path = storage_path('framework/testing/upload-'.uniqid().'.xlsx');
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Track ISRC', 'Streams', 'Earnings']));
        $writer->addRow(Row::fromValues(['GBAYE2600001', 120, 0.1]));
        $writer->addRow(Row::fromValues(['GBAYE2600001', 80, 0.2]));
        $writer->close();
        $this->tempUploads[] = $path;

        $import = $this->import('report.xlsx', (string) file_get_contents($path));

        $this->assertSame('0.300000', (string) $import->net_total);   // not 0.30000000000000004
        $this->assertSame(200, (int) RoyaltyEarning::query()->sum('units'));
    }

    public function test_duplicate_files_are_refused(): void
    {
        $bytes = $this->csv([['ISRC', 'Net'], ['GBAYE2600001', '1']]);
        $this->import('a.csv', $bytes);

        Livewire::actingAs($this->accountant)->test(Imports::class, ['period' => $this->period])
            ->call('startUpload')
            ->set('file', $this->upload('copy.csv', $bytes))
            ->set('source_name', 'Again')
            ->call('upload')
            ->assertHasErrors('file');
        $this->assertSame(1, RoyaltyImport::query()->count());
    }

    public function test_mapping_requires_amount_and_an_identifier(): void
    {
        $component = Livewire::actingAs($this->accountant)->test(Imports::class, ['period' => $this->period])
            ->call('startUpload')
            ->set('file', $this->upload('x.csv', $this->csv([['Title', 'Money'], ['Glass', '1']])))
            ->set('source_name', 'X')
            ->call('upload');

        $component->set('map.amount', '1')->call('confirmMapping')->assertHasErrors('map');
        $this->assertSame('uploaded', RoyaltyImport::query()->sole()->status->value);
    }

    public function test_unmatched_sales_can_be_matched_or_ignored_and_imports_reverted(): void
    {
        $import = $this->import('q3.csv', $this->csv([
            ['ISRC', 'Net'],
            ['XXAAA2600099', '2.00'],
            ['XXAAA2600099', '3.00'],
            ['QZZZZ2600001', '9.00'],
        ]));
        $this->assertSame(0, RoyaltyEarning::query()->count());

        $manager = $this->makeUser(SystemRole::LabelManager);
        Livewire::actingAs($manager)->test(Unmatched::class, ['period' => $this->period])
            ->call('startMatching', 'isrc', 'XXAAA2600099')
            ->set('trackSearch', 'Neon')
            ->assertSee('Neon')
            ->call('assign', $this->neon->id);

        $this->assertSame(2, RoyaltyLine::query()->where('track_id', $this->neon->id)->count());
        $this->assertSame('5.000000', (string) RoyaltyEarning::query()->sole()->revenue);

        Livewire::actingAs($manager)->test(Unmatched::class, ['period' => $this->period])->call('ignore', 'isrc', 'QZZZZ2600001');
        $this->assertSame(0, RoyaltyLine::query()->where('match_status', 'unmatched')->count());

        Livewire::actingAs($this->accountant)->test(Imports::class, ['period' => $this->period])->call('revert', $import->id);
        $this->assertSame('reverted', $import->fresh()->status->value);
        $this->assertSame(0, RoyaltyLine::query()->count());
        $this->assertSame(0, RoyaltyEarning::query()->count());
    }

    public function test_split_between_primary_artists_and_default_share(): void
    {
        $second = Artist::factory()->create(['name' => 'Kai']);          // no contract → default share
        $third = Artist::factory()->create(['name' => 'Ola']);
        $this->glass->artists()->sync([
            $this->nova->id => ['role' => 'primary', 'position' => 1],
            $second->id => ['role' => 'primary', 'position' => 2],
            $third->id => ['role' => 'featuring', 'position' => 3],     // featured artists are not paid by default
        ]);
        app(SettingsService::class)->set(RoyaltyCalculator::DEFAULT_SHARE_SETTING, '40', 'string');

        $this->import('split.csv', $this->csv([['ISRC', 'Net'], ['GBAYE2600001', '0.01']]));

        $byArtist = RoyaltyEarning::query()->get()->keyBy('artist_id');
        $this->assertCount(2, $byArtist);
        $this->assertSame('0.005000', (string) $byArtist[$this->nova->id]->revenue);
        $this->assertSame('0.005000', (string) $byArtist[$second->id]->revenue);
        $this->assertSame('0.003500', (string) $byArtist[$this->nova->id]->artist_amount);   // 70 % contract
        $this->assertSame('0.002000', (string) $byArtist[$second->id]->artist_amount);       // 40 % default
        $this->assertSame('40.0000', (string) $byArtist[$second->id]->artist_share);
    }

    public function test_three_way_split_keeps_every_cent(): void
    {
        $b = Artist::factory()->create();
        $c = Artist::factory()->create();
        $this->glass->artists()->sync([
            $this->nova->id => ['role' => 'primary', 'position' => 1],
            $b->id => ['role' => 'primary', 'position' => 2],
            $c->id => ['role' => 'primary', 'position' => 3],
        ]);

        $this->import('three.csv', $this->csv([['ISRC', 'Net'], ['GBAYE2600001', '0.01']]));

        $revenues = RoyaltyEarning::query()->orderBy('id')->pluck('revenue')->map(fn ($v) => (string) $v)->all();
        $this->assertSame(['0.003333', '0.003333', '0.003334'], $revenues);
        $this->assertSame('0.010000', Decimal::sum($revenues));
    }

    public function test_contract_in_force_on_the_sale_date_is_used(): void
    {
        $this->nova->contracts()->update(['end_date' => '2026-07-31', 'status' => 'expired']);
        $this->nova->contracts()->create(['title' => 'Renewal', 'type' => 'recording', 'status' => 'active', 'start_date' => '2026-08-01', 'artist_share' => '80']);

        $this->import('dates.csv', $this->csv([
            ['ISRC', 'Net', 'Sale Date'],
            ['GBAYE2600001', '10', '2026-07-20'],
            ['GBAYE2600001', '10', '2026-08-20'],
        ]));

        $this->assertEqualsCanonicalizing(['70.0000', '80.0000'], RoyaltyEarning::query()->pluck('artist_share')->map(fn ($v) => (string) $v)->all());
        $this->assertSame('15.000000', Decimal::sum(RoyaltyEarning::query()->pluck('artist_amount')->map(fn ($v) => (string) $v)));
    }

    public function test_permissions_and_plan(): void
    {
        $viewer = $this->makeUser(SystemRole::Viewer);
        $artistManager = $this->makeUser(SystemRole::ArtistManager);

        $this->actingAs($viewer)->get('/royalties')->assertForbidden();
        $this->actingAs($artistManager)->get('/royalties')->assertForbidden();
        $this->actingAs($this->accountant)->get('/royalties')->assertOk();
        $this->actingAs($this->accountant)->get(route('royalties.periods.show', $this->period))->assertOk();

        // Starter plan: no royalties.
        $this->activateLicense('STRT');
        $this->actingAs($this->accountant)->get('/royalties')->assertForbidden();
    }
}
