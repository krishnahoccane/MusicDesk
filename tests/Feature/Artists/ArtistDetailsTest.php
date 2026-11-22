<?php

namespace Tests\Feature\Artists;

use App\Enums\SystemRole;
use App\Livewire\Artists\Contacts;
use App\Livewire\Artists\Links;
use App\Livewire\Artists\Show;
use App\Models\Artist;
use App\Models\ArtistContact;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ArtistDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->manager = $this->makeUser(SystemRole::ArtistManager);
        $this->activateLicense();
        $this->artist = Artist::factory()->create();
    }

    public function test_contacts_crud_with_single_primary(): void
    {
        $component = Livewire::actingAs($this->manager)->test(Contacts::class, ['artist' => $this->artist]);

        $component->call('create')->assertSet('is_primary', true)
            ->set('name', 'Sam Manager')->set('role', 'manager')->set('email', 'sam@example.com')
            ->call('save')->assertHasNoErrors();

        $component->call('create')
            ->set('name', 'Bea Booker')->set('role', 'booking_agent')->set('phone', '+44 20 7946 0000')->set('is_primary', true)
            ->call('save')->assertHasNoErrors();

        $this->assertSame(['Bea Booker'], ArtistContact::query()->where('is_primary', true)->pluck('name')->all());

        // A contact needs an email or a phone number.
        $component->call('create')->set('name', 'Nobody')->call('save')->assertHasErrors('email');

        $sam = ArtistContact::query()->where('name', 'Sam Manager')->first();
        $component->call('edit', $sam->id)->set('name', 'Sam M.')->call('save')->assertHasNoErrors();
        $this->assertSame('Sam M.', $sam->fresh()->name);

        $component->call('delete', $sam->id);
        $this->assertModelMissing($sam);
        $this->assertDatabaseHas('activity_logs', ['event' => 'artist.contact_removed']);
    }

    public function test_contacts_of_another_artist_cannot_be_touched(): void
    {
        $other = Artist::factory()->create();
        $foreign = $other->contacts()->create(['name' => 'Foreign', 'role' => 'manager', 'email' => 'f@example.com']);

        Livewire::actingAs($this->manager)->test(Contacts::class, ['artist' => $this->artist])
            ->call('delete', $foreign->id)
            ->assertNotFound();
        $this->assertModelExists($foreign);
    }

    public function test_viewers_cannot_change_contacts_or_links(): void
    {
        $viewer = $this->makeUser(SystemRole::Viewer);

        Livewire::actingAs($viewer)->test(Contacts::class, ['artist' => $this->artist])->call('create')->assertForbidden();
        Livewire::actingAs($viewer)->test(Links::class, ['artist' => $this->artist])
            ->set('url', 'https://open.spotify.com/artist/1')->call('save')->assertForbidden();
        $this->assertSame(0, $this->artist->links()->count());
    }

    public function test_links_are_validated_against_the_platform(): void
    {
        $component = Livewire::actingAs($this->manager)->test(Links::class, ['artist' => $this->artist]);

        $component->set('platform', 'spotify')->set('url', 'javascript:alert(1)')->call('save')->assertHasErrors('url');
        $component->set('platform', 'spotify')->set('url', 'https://evil.example.com/spotify.com')->call('save')->assertHasErrors('url');
        $component->set('platform', 'spotify')->set('url', 'https://open.spotify.com/artist/abc')->call('save')->assertHasNoErrors();
        $component->set('platform', 'website')->set('url', 'https://nova.example.org')->call('save')->assertHasNoErrors();

        $this->assertSame(2, $this->artist->links()->count());

        $link = $this->artist->links()->first();
        $component->call('delete', $link->id);
        $this->assertSame(1, $this->artist->links()->count());
    }

    public function test_profile_tabs_follow_permissions_and_plan(): void
    {
        // Viewer: no contracts.view / documents.view / activity.view.
        $viewer = $this->makeUser(SystemRole::Viewer);
        Livewire::actingAs($viewer)->test(Show::class, ['artist' => $this->artist])
            ->assertSee('Contacts')->assertDontSee('Documents')
            ->call('show', 'documents')->assertSet('tab', 'overview');

        Livewire::actingAs($this->manager)->test(Show::class, ['artist' => $this->artist])
            ->call('show', 'documents')->assertSet('tab', 'documents');
    }
}
