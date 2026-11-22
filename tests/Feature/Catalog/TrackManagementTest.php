<?php

namespace Tests\Feature\Catalog;

use App\Enums\SystemRole;
use App\Livewire\Contributors\Index as ContributorIndex;
use App\Livewire\Releases\Tracks;
use App\Livewire\Tracks\Form;
use App\Models\Artist;
use App\Models\Contributor;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use Database\Seeders\CatalogReferenceSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CreatesCatalog;
use Tests\TestCase;

class TrackManagementTest extends TestCase
{
    use CreatesCatalog, RefreshDatabase;

    protected User $admin;

    protected Artist $artist;

    protected Release $release;

    protected Contributor $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, CatalogReferenceSeeder::class]);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->activateLicense();
        Storage::fake('local');

        $this->artist = Artist::factory()->create(['name' => 'Nova Lights']);
        $this->release = Release::factory()->create(['title' => 'Midnight Static', 'type' => 'album']);
        $this->release->artists()->sync([$this->artist->id => ['role' => 'primary', 'position' => 1]]);
        $this->writer = Contributor::query()->create(['name' => 'Wren Writer']);
    }

    protected function tearDown(): void
    {
        $this->cleanUploads();
        parent::tearDown();
    }

    protected function addTrack(array $overrides = []): Testable
    {
        $test = Livewire::actingAs($this->admin)->test(Form::class, ['release' => $this->release])
            ->set('title', $overrides['title'] ?? 'Glass City')
            ->set('isrc', $overrides['isrc'] ?? '')
            ->set('explicit', $overrides['explicit'] ?? false)
            ->set('creditRows', $overrides['credits'] ?? [['contributor_id' => $this->writer->id, 'role' => 'songwriter', 'share' => '100']]);

        if (isset($overrides['audio'])) {
            $test->set('audio', $overrides['audio']);
        }

        return $test->call('save');
    }

    public function test_track_is_added_with_release_artists_credits_and_audio(): void
    {
        $this->addTrack([
            'isrc' => 'gb-aye-26-00001',
            'explicit' => true,
            'audio' => $this->upload('glass-city.wav', $this->wavBytes(4, 48000, 24)),
        ])->assertHasNoErrors()->assertRedirect(route('releases.show', ['release' => $this->release, 'tab' => 'tracks']));

        $track = Track::query()->firstOrFail();
        $this->assertSame('GBAYE2600001', $track->isrc);
        $this->assertSame('GB-AYE-26-00001', $track->formattedIsrc());
        $this->assertSame('Nova Lights', $track->artistLine());          // inherited from the release
        $this->assertSame(1, $track->position);
        $this->assertSame(4, $track->duration_seconds);                    // read from the WAV header
        $this->assertSame(48000, $track->audio_sample_rate);
        $this->assertSame(24, $track->audio_bit_depth);
        $this->assertSame('songwriter', $track->credits()->first()->role->value);
        Storage::disk('local')->assertExists($track->audio_path);
        $this->assertTrue($this->release->fresh()->explicit);              // explicit follows tracks
        $this->assertArrayNotHasKey('audio_path', $track->toArray());
    }

    public function test_invalid_audio_is_rejected_but_details_are_kept(): void
    {
        $this->addTrack(['audio' => $this->upload('low.wav', $this->wavBytes(1, 22050))])
            ->assertHasErrors('audio');

        $track = Track::query()->firstOrFail();
        $this->assertNull($track->audio_path);
        $this->assertSame([], Storage::disk('local')->allFiles('releases'));

        $this->addTrack(['title' => 'Fake', 'audio' => $this->upload('song.mp3', 'ID3'.str_repeat("\0", 100))])->assertHasErrors('audio');
    }

    public function test_isrc_must_be_valid_and_unique_except_on_compilations(): void
    {
        $this->addTrack(['isrc' => 'NOT-AN-ISRC'])->assertHasErrors('isrc');
        $this->addTrack(['isrc' => 'GBAYE2600001'])->assertHasNoErrors();
        $this->addTrack(['title' => 'Again', 'isrc' => 'GB-AYE-26-00001'])->assertHasErrors('form');

        $compilation = Release::factory()->create(['type' => 'compilation']);
        $compilation->artists()->sync([$this->artist->id => ['role' => 'primary', 'position' => 1]]);
        Livewire::actingAs($this->admin)->test(Form::class, ['release' => $compilation])
            ->set('title', 'Glass City')->set('isrc', 'GBAYE2600001')
            ->set('creditRows', [['contributor_id' => $this->writer->id, 'role' => 'songwriter', 'share' => '']])
            ->call('save')->assertHasNoErrors();
    }

    public function test_publishing_shares_cannot_exceed_100_percent(): void
    {
        $other = Contributor::query()->create(['name' => 'Otto Other']);

        $this->addTrack(['credits' => [
            ['contributor_id' => $this->writer->id, 'role' => 'composer', 'share' => '60'],
            ['contributor_id' => $other->id, 'role' => 'composer', 'share' => '50.5'],
        ]])->assertHasErrors('form');

        $this->addTrack(['credits' => [
            ['contributor_id' => $this->writer->id, 'role' => 'composer', 'share' => '60'],
            ['contributor_id' => $other->id, 'role' => 'composer', 'share' => '40'],
            ['contributor_id' => $other->id, 'role' => 'producer', 'share' => '99'], // non-publishing role: share ignored
        ]])->assertHasNoErrors();

        $this->assertNull(Track::query()->first()->credits()->where('role', 'producer')->value('share'));
    }

    public function test_master_audio_access_requires_download_permission(): void
    {
        $this->addTrack(['audio' => $this->upload('glass.flac', $this->flacBytes(200))])->assertHasNoErrors();
        $track = Track::query()->firstOrFail();
        $this->assertSame(200, $track->duration_seconds);

        $viewer = $this->makeUser(SystemRole::Viewer);
        $distribution = $this->makeUser(SystemRole::DistributionManager);

        $this->actingAs($viewer)->get(route('tracks.show', $track))->assertOk()->assertDontSee(route('tracks.audio', $track));
        $this->actingAs($viewer)->get(route('tracks.audio', $track))->assertForbidden();
        $this->actingAs($viewer)->get(route('tracks.download', $track))->assertForbidden();

        $this->actingAs($distribution)->get(route('tracks.audio', $track))->assertOk()->assertHeader('Accept-Ranges', 'bytes');
        $this->actingAs($distribution)->get(route('tracks.download', $track))->assertOk()->assertDownload('Nova Lights - Glass City.flac');
        $this->assertDatabaseHas('activity_logs', ['event' => 'track.master_downloaded']);
    }

    public function test_reorder_and_delete(): void
    {
        $this->addTrack(['title' => 'One'])->assertHasNoErrors();
        $this->addTrack(['title' => 'Two', 'audio' => $this->upload('two.wav', $this->wavBytes())])->assertHasNoErrors();
        $this->addTrack(['title' => 'Three'])->assertHasNoErrors();

        $two = Track::query()->where('title', 'Two')->first();
        Livewire::actingAs($this->admin)->test(Tracks::class, ['release' => $this->release])->call('move', $two->id, -1);
        $this->assertSame(['Two', 'One', 'Three'], $this->release->tracks()->pluck('title')->all());

        $path = $two->fresh()->audio_path;
        Livewire::actingAs($this->admin)->test(Tracks::class, ['release' => $this->release])->call('delete', $two->id);
        $this->assertSame(['One', 'Three'], $this->release->tracks()->pluck('title')->all());
        $this->assertSame([1, 2], $this->release->tracks()->pluck('position')->all());
        Storage::disk('local')->assertMissing($path);
    }

    public function test_track_permissions(): void
    {
        $this->addTrack()->assertHasNoErrors();
        $track = Track::query()->first();
        $viewer = $this->makeUser(SystemRole::Viewer);

        $this->actingAs($viewer)->get(route('releases.tracks.create', $this->release))->assertForbidden();
        $this->actingAs($viewer)->get(route('tracks.edit', $track))->assertForbidden();
        Livewire::actingAs($viewer)->test(Form::class, ['track' => $track])->set('title', 'Hacked')->call('save')->assertForbidden();
        Livewire::actingAs($viewer)->test(Tracks::class, ['release' => $this->release])->call('delete', $track->id)->assertForbidden();
        $this->assertSame('Glass City', $track->fresh()->title);
    }

    public function test_contributors_can_be_created_inline_and_managed(): void
    {
        Livewire::actingAs($this->admin)->test(Form::class, ['release' => $this->release])
            ->set('creditRows', [['contributor_id' => '', 'role' => 'songwriter', 'share' => '']])
            ->set('newContributor', 'Ines Inline')
            ->call('createContributor')
            ->assertSet('creditRows.0.contributor_id', Contributor::query()->where('name', 'Ines Inline')->value('id'));

        Livewire::actingAs($this->admin)->test(ContributorIndex::class)
            ->call('create')->set('name', 'Pat Producer')->set('ipi_number', '123')->call('save')->assertHasErrors('ipi_number')
            ->set('ipi_number', '00123456789')->call('save')->assertHasNoErrors();

        // A credited contributor cannot be deleted.
        $this->addTrack()->assertHasNoErrors();
        Livewire::actingAs($this->admin)->test(ContributorIndex::class)->call('delete', $this->writer->id)->assertDispatched('toast', type: 'danger');
        $this->assertModelExists($this->writer);

        $this->actingAs($this->makeUser(SystemRole::Accountant))->get('/contributors')->assertForbidden();
    }
}
