<?php

namespace Tests\Feature\Artists;

use App\Enums\SystemRole;
use App\Livewire\Artists\Contracts;
use App\Livewire\Artists\Documents;
use App\Livewire\Contracts\Index as ContractIndex;
use App\Models\Artist;
use App\Models\ArtistContract;
use App\Models\ArtistDocument;
use App\Models\User;
use App\Support\Format;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ContractsAndDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->activateLicense();
        $this->artist = Artist::factory()->create(['name' => 'Nova Lights']);
        Storage::fake('local');
    }

    /**
     * A test upload backed by a real file (fake()->createWithContent() temp
     * handles are unreliable on Windows when several are used in one test).
     */
    protected function tearDown(): void
    {
        foreach (glob(storage_path('framework/testing/upload-*')) ?: [] as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    protected function realUpload(string $name, string $content): File
    {
        $path = storage_path('framework/testing/'.uniqid('upload-', true));
        file_put_contents($path, $content);

        return new File($name, fopen($path, 'r'));
    }

    protected function addContract(array $overrides = []): ArtistContract
    {
        Livewire::actingAs($this->admin)->test(Contracts::class, ['artist' => $this->artist])
            ->call('create')
            ->set('title', $overrides['title'] ?? 'Album deal')
            ->set('status', $overrides['status'] ?? 'active')
            ->set('start_date', $overrides['start_date'] ?? '2026-01-01')
            ->set('end_date', $overrides['end_date'] ?? '')
            ->set('artist_share', $overrides['artist_share'] ?? '62.5')
            ->set('advance_amount', $overrides['advance_amount'] ?? '12,500.75')
            ->set('currency', 'EUR')
            ->call('save')
            ->assertHasNoErrors();

        return ArtistContract::query()->latest('id')->firstOrFail();
    }

    public function test_contract_amounts_are_stored_as_exact_decimals(): void
    {
        $contract = $this->addContract();

        $this->assertSame('62.5000', (string) $contract->artist_share);
        $this->assertSame('12500.750000', (string) $contract->advance_amount);
        $this->assertSame('37.5000', $contract->labelShare());
        $this->assertSame('12,500.75 EUR', Format::money($contract->advance_amount, $contract->currency));
        $this->assertSame('62.5%', Format::percent($contract->artist_share));
        $this->assertDatabaseHas('activity_logs', ['event' => 'contract.created']);
    }

    public function test_contract_validation(): void
    {
        Livewire::actingAs($this->admin)->test(Contracts::class, ['artist' => $this->artist])
            ->call('create')
            ->set('title', '')
            ->set('status', 'active')
            ->set('start_date', '')
            ->set('end_date', '2020-01-01')
            ->set('artist_share', '101')
            ->set('advance_amount', '12.1234567')
            ->call('save')
            ->assertHasErrors(['title', 'start_date', 'artist_share', 'advance_amount']);

        Livewire::actingAs($this->admin)->test(Contracts::class, ['artist' => $this->artist])
            ->call('create')
            ->set('title', 'Bad dates')
            ->set('start_date', '2026-06-01')
            ->set('end_date', '2026-01-01')
            ->call('save')
            ->assertHasErrors(['end_date']);
    }

    public function test_active_contract_past_its_end_date_shows_as_expired(): void
    {
        $contract = $this->addContract(['start_date' => '2020-01-01', 'end_date' => now()->subDay()->format('Y-m-d')]);
        $this->assertSame('expired', $contract->effectiveStatus()->value);

        $soon = $this->addContract(['title' => 'Ending soon', 'end_date' => now()->addDays(20)->format('Y-m-d')]);

        Livewire::actingAs($this->admin)->test(ContractIndex::class)
            ->set('status', 'expiring')->assertSee('Ending soon')->assertDontSee('Album deal')
            ->set('status', 'expired')->assertSee('Album deal')->assertDontSee('Ending soon');

        $this->assertSame('active', $soon->effectiveStatus()->value);
    }

    public function test_contract_permissions(): void
    {
        $artistManager = $this->makeUser(SystemRole::ArtistManager); // contracts.view only
        $this->addContract();

        $this->actingAs($artistManager)->get('/contracts')->assertOk()->assertSee('Album deal');
        Livewire::actingAs($artistManager)->test(Contracts::class, ['artist' => $this->artist])->call('create')->assertForbidden();

        $viewer = $this->makeUser(SystemRole::Viewer);
        $this->actingAs($viewer)->get('/contracts')->assertForbidden();
    }

    public function test_documents_upload_download_and_delete(): void
    {
        $contract = $this->addContract();

        Livewire::actingAs($this->admin)->test(Documents::class, ['artist' => $this->artist])
            ->set('file', UploadedFile::fake()->createWithContent('signed_contract.pdf', "%PDF-1.4\n%test\n"))
            ->assertSet('title', 'signed contract')
            ->set('category', 'contract')
            ->set('contract_id', (string) $contract->id)
            ->call('storeDocument')
            ->assertHasNoErrors();

        $document = ArtistDocument::query()->firstOrFail();
        $this->assertSame('signed_contract.pdf', $document->original_name);
        $this->assertSame($contract->id, $document->artist_contract_id);
        $this->assertStringStartsWith('artists/'.$this->artist->id.'/documents/', $document->path);
        $this->assertStringNotContainsString('signed_contract', $document->path);
        Storage::disk('local')->assertExists($document->path);
        $this->assertArrayNotHasKey('path', $document->toArray());

        $response = $this->actingAs($this->admin)->get(route('artists.documents.download', [$this->artist, $document]));
        $response->assertOk()->assertDownload('signed_contract.pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertDatabaseHas('activity_logs', ['event' => 'document.downloaded']);

        // A document cannot be fetched through another artist's URL.
        $other = Artist::factory()->create();
        $this->actingAs($this->admin)->get(route('artists.documents.download', [$other, $document]))->assertNotFound();

        Livewire::actingAs($this->admin)->test(Documents::class, ['artist' => $this->artist])->call('delete', $document->id);
        $this->assertModelMissing($document);
        Storage::disk('local')->assertMissing($document->path);
    }

    public function test_real_office_documents_are_accepted(): void
    {
        $path = storage_path('framework/testing/upload-'.uniqid().'.docx');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>');
        $zip->close();

        Livewire::actingAs($this->admin)->test(Documents::class, ['artist' => $this->artist])
            ->set('file', new File('W-9 form.docx', fopen($path, 'r')))
            ->set('category', 'tax')
            ->call('storeDocument')
            ->assertHasNoErrors();

        $this->assertSame('W-9 form.docx', ArtistDocument::query()->value('original_name'));
    }

    public function test_disallowed_and_spoofed_files_are_rejected(): void
    {
        $php = $this->realUpload('shell.php', '<?php echo 1;');
        $spoofed = $this->realUpload('invoice.pdf', '<?php echo "not a pdf"; ?>');

        Livewire::actingAs($this->admin)->test(Documents::class, ['artist' => $this->artist])
            ->set('file', $php)
            ->assertHasErrors('file');

        // A PHP file renamed to .pdf fails the content check.
        Livewire::actingAs($this->admin)->test(Documents::class, ['artist' => $this->artist])
            ->set('file', $spoofed)
            ->set('title', 'Invoice')
            ->call('storeDocument')
            ->assertHasErrors('file');

        $this->assertSame(0, ArtistDocument::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('artists'));
    }

    public function test_document_permissions_and_plan(): void
    {
        $viewer = $this->makeUser(SystemRole::Viewer);
        $document = $this->artist->documents()->make(['title' => 'Rider', 'category' => 'other']);
        $document->forceFill(['disk' => 'local', 'path' => 'artists/x/doc.pdf', 'original_name' => 'rider.pdf', 'mime_type' => 'application/pdf', 'size' => 10])->save();
        Storage::disk('local')->put('artists/x/doc.pdf', '%PDF-1.4');

        // Viewers lack documents.view.
        $this->actingAs($viewer)->get(route('artists.documents.download', [$this->artist, $document]))->assertForbidden();

        // Starter plan has no "documents" feature: contracts and downloads are unavailable.
        $this->activateLicense('STRT');
        $this->actingAs($this->admin)->get('/contracts')->assertForbidden();
        $this->actingAs($this->admin)->get(route('artists.documents.download', [$this->artist, $document]))->assertForbidden();
        $this->actingAs($this->admin)->get('/artists')->assertOk();
    }
}
