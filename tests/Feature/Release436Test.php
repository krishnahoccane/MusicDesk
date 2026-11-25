<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Livewire\Releases\Form as ReleaseForm;
use App\Livewire\Tracks\Form as TrackForm;
use App\Models\Artist;
use App\Models\Contributor;
use App\Models\Mood;
use App\Models\Release;
use App\Models\Track;
use App\Models\User;
use App\Services\Catalog\ReleaseService;
use App\Services\Catalog\ReleaseWorkflow;
use Database\Seeders\CatalogReferenceSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\CreatesCatalog;
use Tests\TestCase;

/**
 * 4.3.6: drafts, singles, upload progress, mood (PDL list), CRBT cut, composer and lyricist, quick "New artist".
 */
class Release436Test extends TestCase
{
    use CreatesCatalog, RefreshDatabase;

    protected User $admin;

    protected Artist $artist;

    protected Contributor $composer;

    protected Contributor $lyricist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, CatalogReferenceSeeder::class]);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->activateLicense();
        Storage::fake('local');
        $this->artist = Artist::factory()->create(['name' => 'Nova Lights']);
        $this->composer = Contributor::query()->create(['name' => 'Cleo Composer']);
        $this->lyricist = Contributor::query()->create(['name' => 'Lyle Lyricist']);
    }

    protected function tearDown(): void
    {
        $this->cleanUploads();
        parent::tearDown();
    }

    protected function release(string $type = 'album', string $title = 'Midnight Static'): Release
    {
        $release = Release::factory()->create(['title' => $title, 'type' => $type]);
        $release->artists()->sync([$this->artist->id => ['role' => 'primary', 'position' => 1]]);

        return $release;
    }

    protected function writers(): array
    {
        return [
            ['contributor_id' => $this->composer->id, 'role' => 'composer', 'share' => '100'],
            ['contributor_id' => $this->lyricist->id, 'role' => 'lyricist', 'share' => '100'],
        ];
    }

    // ------------------------------------------------------------ 1. drafts

    public function test_a_release_can_be_saved_as_a_draft_with_incomplete_artists(): void
    {
        Livewire::actingAs($this->admin)->test(ReleaseForm::class)
            ->set('title', 'Half Done')->set('artistRows', [['artist_id' => '', 'role' => 'primary']])
            ->call('save')->assertHasErrors('artistRows.0.artist_id')
            ->call('saveDraft')->assertHasNoErrors()->assertRedirect();

        $release = Release::query()->where('title', 'Half Done')->sole();
        $this->assertSame('draft', $release->status->value);
        $this->assertSame(0, $release->artists()->count());

        // Editing a draft stays on the form.
        Livewire::actingAs($this->admin)->test(ReleaseForm::class, ['release' => $release])
            ->set('version', 'Demo')->call('saveDraft')->assertHasNoErrors()->assertNoRedirect()->assertDispatched('toast', type: 'success');
        $this->assertSame('Demo', $release->fresh()->version);
    }

    public function test_a_track_can_be_saved_as_a_draft_and_blocks_submission_until_completed(): void
    {
        $release = $this->release();

        Livewire::actingAs($this->admin)->test(TrackForm::class, ['release' => $release])
            ->set('title', 'Unfinished')
            ->call('save')->assertHasErrors()
            ->call('saveDraft')->assertHasNoErrors()->assertRedirect();

        $track = Track::query()->where('title', 'Unfinished')->sole();
        $this->assertTrue($track->is_draft);
        $this->assertSame(0, $track->credits()->count(), 'empty credit rows are left out of a draft');

        $checks = collect(app(ReleaseWorkflow::class)->checklist($release))->pluck('ok', 'label');
        $this->assertFalse($checks['No track is still a draft']);
        $this->assertFalse($checks['Every track has a composer and a lyricist']);

        Livewire::actingAs($this->admin)->test(TrackForm::class, ['track' => $track])
            ->set('creditRows', $this->writers())->call('save')->assertHasNoErrors();
        $this->assertFalse($track->fresh()->is_draft);
        $this->assertTrue(collect(app(ReleaseWorkflow::class)->checklist($release))->pluck('ok', 'label')['Every track has a composer and a lyricist']);
    }

    // ------------------------------------------------------------ 8. composer and lyricist

    public function test_a_track_needs_a_composer_and_a_lyricist(): void
    {
        $release = $this->release();
        $form = Livewire::actingAs($this->admin)->test(TrackForm::class, ['release' => $release]);
        $this->assertSame(['composer', 'lyricist'], array_column($form->get('creditRows'), 'role'), 'new tracks start with both');

        $form->set('title', 'Only Music')->set('creditRows', [['contributor_id' => $this->composer->id, 'role' => 'composer', 'share' => '100']])
            ->call('save')->assertHasErrors('creditRows');

        // A songwriter counts as both.
        $form->set('creditRows', [['contributor_id' => $this->composer->id, 'role' => 'songwriter', 'share' => '100']])
            ->call('save')->assertHasNoErrors();
    }

    // ------------------------------------------------------------ 2. singles

    public function test_a_single_track_carries_the_release_title(): void
    {
        $release = $this->release('single', 'Glass City');

        $form = Livewire::actingAs($this->admin)->test(TrackForm::class, ['release' => $release]);
        $this->assertSame('Glass City', $form->get('title'));
        $form->set('title', 'Something Else')->set('creditRows', $this->writers())->call('save')->assertHasNoErrors();
        $track = Track::query()->sole();
        $this->assertSame('Glass City', $track->title, 'the title follows the release');

        app(ReleaseService::class)->update($this->admin, $release, ['title' => 'Glass City (Remastered)', 'type' => 'single'], [['artist_id' => $this->artist->id, 'role' => 'primary']]);
        $this->assertSame('Glass City (Remastered)', $track->fresh()->title);
    }

    // ------------------------------------------------------------ 4/5/7. length, mood, CRBT

    public function test_mood_comes_from_the_pdl_list_and_crbt_is_stored(): void
    {
        $this->assertSame(array_keys(Mood::PDL), Mood::query()->orderBy('sort')->pluck('value')->all());
        $patriotic = Mood::query()->where('value', 'Partiotic')->sole();
        $this->assertSame('Patriotic', $patriotic->name, 'PDL value kept, readable name shown');

        $release = $this->release();
        Livewire::actingAs($this->admin)->test(TrackForm::class, ['release' => $release])
            ->assertSee('Patriotic')->assertSee('Caller tune (CRBT)')->assertDontSee('id="duration"', false)
            ->set('title', 'Anthem')->set('creditRows', $this->writers())
            ->set('mood_id', (string) $patriotic->id)->set('crbt_name', 'Anthem Tune')->set('crbt_start', '0:45')
            ->call('save')->assertHasNoErrors();

        $track = Track::query()->sole();
        $this->assertSame($patriotic->id, $track->mood_id);
        $this->assertSame('Anthem Tune', $track->crbt_name);
        $this->assertSame(45, $track->crbt_start_seconds);
        $this->assertSame('0:45', $track->formattedCrbtStart());
    }

    public function test_the_upload_shows_its_length_and_crbt_must_fit_in_the_track(): void
    {
        $release = $this->release();
        $form = Livewire::actingAs($this->admin)->test(TrackForm::class, ['release' => $release])
            ->assertSee('Upload progress')
            ->set('audio', $this->upload('glass.flac', $this->flacBytes(60)));

        $this->assertSame(60, $form->get('audioInfo')['duration']);
        $form->assertSee('Ready to save')->assertSee('1:00');

        $form->set('title', 'Short')->set('creditRows', $this->writers())->set('crbt_start', '0:45')
            ->call('save')->assertHasErrors('crbt_start');
        $form->set('crbt_start', '0:30')->call('save')->assertHasNoErrors();
        $this->assertSame(60, Track::query()->sole()->duration_seconds, 'length taken from the file');
    }

    public function test_crbt_name_is_at_most_64_characters(): void
    {
        Livewire::actingAs($this->admin)->test(TrackForm::class, ['release' => $this->release()])
            ->set('title', 'Long')->set('creditRows', $this->writers())->set('crbt_name', str_repeat('x', 65))
            ->call('save')->assertHasErrors('crbt_name');
    }

    // ------------------------------------------------------------ 6. New artist in the Artists card

    public function test_a_new_artist_can_be_added_without_leaving_the_form(): void
    {
        $form = Livewire::actingAs($this->admin)->test(ReleaseForm::class)
            ->assertSee('New artist')
            ->set('artistRows', [['artist_id' => '', 'role' => 'primary']])
            ->set('showNewArtist', true)->set('newArtistName', 'Aria Vale')->call('createArtist')->assertHasNoErrors();

        $artist = Artist::query()->where('name', 'Aria Vale')->sole();
        $this->assertSame('active', $artist->status->value);
        $this->assertSame($artist->id, $form->get('artistRows')[0]['artist_id'], 'fills the empty row');
        $this->assertFalse($form->get('showNewArtist'));

        // Accounts that cannot create artists do not get the button or the action.
        $viewer = $this->makeUser(SystemRole::Viewer);
        Livewire::actingAs($viewer)->test(TrackForm::class, ['track' => $this->makeTrack()])->assertDontSee('New artist');
    }

    protected function makeTrack(): Track
    {
        $release = $this->release();
        Livewire::actingAs($this->admin)->test(TrackForm::class, ['release' => $release])
            ->set('title', 'Viewable')->set('creditRows', $this->writers())->call('save');

        return Track::query()->where('title', 'Viewable')->sole();
    }
}
