<?php

namespace Tests\Feature;

use App\Enums\SystemRole;
use App\Livewire\Releases\Tracks;
use App\Livewire\Settings\Website;
use App\Livewire\Settings\WebsitePages;
use App\Models\Artist;
use App\Models\Release;
use App\Models\SiteMessage;
use App\Models\SitePage;
use App\Models\Track;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\Website\WebsiteContent;
use Database\Seeders\CatalogReferenceSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 4.3.6: the label website (on/off, themes, pages, contact form) and track numbers.
 */
class Release436WebsiteTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Artist $artist;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, CatalogReferenceSeeder::class]);
        $this->admin = $this->makeUser(SystemRole::SuperAdmin);
        $this->activateLicense();
        $this->artist = Artist::factory()->create(['name' => 'Nova Lights', 'status' => 'active', 'email' => 'nova-private@example.com', 'notes' => 'Secret deal notes', 'bio' => 'Dream pop from the coast.']);
    }

    protected function release(string $title, string $status, string $date): Release
    {
        $release = Release::factory()->create(['title' => $title, 'type' => 'album', 'release_date' => $date]);
        $release->forceFill(['status' => $status])->save();
        $release->artists()->sync([$this->artist->id => ['role' => 'primary', 'position' => 1]]);

        return $release;
    }

    protected function enable(array $settings = []): void
    {
        app(SettingsService::class)->setMany(['website.enabled' => true] + $settings);
    }

    public function test_website_is_off_by_default_and_home_opens_sign_in(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/music')->assertNotFound();
        $this->get('/roster')->assertNotFound();
        $this->actingAs($this->admin)->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_only_public_catalogue_is_shown(): void
    {
        $this->enable(['website.hero_title' => 'Sound of the Coast', 'website.show_upcoming' => true]);
        $live = $this->release('Out Now', 'approved', now()->subWeek()->toDateString());
        $soon = $this->release('Coming Up', 'ready', now()->addMonth()->toDateString());
        $draft = $this->release('Hidden Draft', 'draft', now()->subWeek()->toDateString());
        $review = $this->release('In Review', 'in_review', now()->subWeek()->toDateString());

        $home = $this->get('/')->assertOk()->assertSee('Sound of the Coast')->assertSee('Out Now')->assertSee('Coming Up')
            ->assertDontSee('Hidden Draft')->assertDontSee('In Review')->assertSee('Nova Lights')
            ->assertSee('data-site-theme="aurora"', false)->assertSee('index, follow', false);
        $home->assertDontSee('nova-private@example.com')->assertDontSee('Secret deal notes');

        $this->get('/music/'.WebsiteContent::releaseSlug($live))->assertOk()->assertSee('Out Now')->assertSee('Nova Lights');
        $this->get('/music/'.$live->id)->assertRedirect('/music/'.WebsiteContent::releaseSlug($live));
        $this->get('/music/'.WebsiteContent::releaseSlug($draft))->assertNotFound();
        $this->get(route('site.artwork', $draft->id))->assertNotFound();

        $this->get('/roster/'.WebsiteContent::artistSlug($this->artist))->assertOk()->assertSee('Dream pop from the coast.')
            ->assertDontSee('nova-private@example.com')->assertDontSee('Secret deal notes');

        // Upcoming releases can be hidden.
        app(SettingsService::class)->set('website.show_upcoming', false);
        $this->get('/')->assertDontSee('Coming Up');
        $this->get('/music/'.WebsiteContent::releaseSlug($soon))->assertNotFound();
    }

    public function test_settings_switch_the_website_and_pick_a_theme(): void
    {
        Livewire::actingAs($this->admin)->test(Website::class)
            ->set('enabled', true)->set('theme', 'noir')->set('hero_title', 'Night Label')
            ->set('socials.instagram', 'http://insecure.example')->call('save')->assertHasErrors('socials.instagram')
            ->set('socials.instagram', 'https://instagram.com/nightlabel')->set('theme', 'nope')->call('save')->assertHasErrors('theme')
            ->set('theme', 'noir')->call('save')->assertHasNoErrors();

        $this->assertCount(10, WebsiteContent::THEMES);
        $this->get('/')->assertOk()->assertSee('Night Label')->assertSee('data-site-theme="noir"', false)
            ->assertSee('data-bs-theme="dark"', false)->assertSee('https://instagram.com/nightlabel');

        $this->actingAs($this->makeUser(SystemRole::Viewer))->get(route('settings.website'))->assertForbidden();
    }

    public function test_pages_are_managed_and_published(): void
    {
        $this->enable();
        $pages = Livewire::actingAs($this->admin)->test(WebsitePages::class);
        $this->assertSame(['about', 'services', 'pricing', 'contact'], SitePage::query()->orderBy('sort')->pluck('type')->all());
        $this->get('/pricing')->assertNotFound(); // not published yet

        $pricing = SitePage::query()->where('type', 'pricing')->sole();
        $pages->call('edit', $pricing->id)->call('addItem', 'plans')
            ->set('content.plans.0.name', 'Starter')->set('content.plans.0.price', '₹4,999')->set('content.plans.0.period', 'per release')
            ->set('content.plans.0.features', "Delivery to 150 stores\nMonthly reports")
            ->set('content.plans.0.cta_label', 'Get started')->set('content.plans.0.cta_url', 'javascript:alert(1)')
            ->call('save')->assertHasErrors('content.plans.0.cta_url')
            ->set('content.plans.0.cta_url', '/contact')->set('is_published', true)->call('save')->assertHasNoErrors();

        $this->get('/pricing')->assertOk()->assertSee('Starter')->assertSee('₹4,999')->assertSee('Monthly reports');
        $this->get('/')->assertSee('href="'.route('site.pricing').'"', false);

        // The label's own page, with Markdown (raw HTML removed).
        $pages->call('create')->set('title', 'Demo submissions')->set('content.body', "## How to send demos\n\n<script>alert(1)</script>\n\nSend us a link.")
            ->set('is_published', true)->call('save')->assertHasNoErrors();
        $this->get('/pages/demo-submissions')->assertOk()->assertSee('How to send demos')->assertSee('Send us a link.')->assertDontSee('<script>alert(1)</script>', false);

        $custom = SitePage::query()->where('slug', 'demo-submissions')->sole();
        $pages->call('delete', $custom->id);
        $this->get('/pages/demo-submissions')->assertNotFound();
    }

    public function test_contact_form_keeps_and_emails_messages(): void
    {
        $this->enable(['website.contact_email' => 'hello@label.example']);
        app(WebsiteContent::class)->ensureBuiltInPages();
        SitePage::query()->where('type', 'contact')->update(['is_published' => true]);

        $this->get('/contact')->assertOk()->assertSee('Send message');
        $this->post('/contact', ['name' => 'Ana', 'email' => 'bad', 'message' => 'short'])->assertSessionHasErrors(['email', 'message']);
        $this->post('/contact', ['name' => 'Ana', 'email' => 'ana@example.com', 'subject' => 'Demo', 'message' => 'Here is my new single, have a listen.'])
            ->assertSessionHasNoErrors()->assertRedirect(route('site.contact'))->assertSessionHas('sent');

        $message = SiteMessage::query()->sole();
        $this->assertSame('Demo', $message->subject);
        $this->assertTrue($message->mailed);

        // Bots fill the hidden field: nothing is stored.
        $this->post('/contact', ['name' => 'Bot', 'email' => 'bot@example.com', 'message' => 'Buy cheap things now!!', 'website_url' => 'http://spam'])->assertRedirect();
        $this->assertSame(1, SiteMessage::query()->count());

        Livewire::actingAs($this->admin)->test(WebsitePages::class)->assertSee('Here is my new single')->call('markRead', $message->id);
        $this->assertNotNull($message->fresh()->read_at);
    }

    public function test_track_numbers_follow_the_list_order(): void
    {
        $release = $this->release('Numbers', 'draft', now()->toDateString());
        foreach (['One', 'Two', 'Three'] as $i => $title) {
            $release->tracks()->create(['title' => $title, 'disc_number' => $i === 2 ? 2 : 1, 'position' => $i === 2 ? 1 : $i + 1, 'explicit' => false]);
        }

        $page = Livewire::actingAs($this->admin)->test(Tracks::class, ['release' => $release]);
        $page->assertSeeInOrder(['One', 'Two', 'Three'])->assertDontSee('Disc 2');

        // Moving "Three" up crosses the old disc boundary and renumbers 1…n.
        $three = Track::query()->where('title', 'Three')->sole();
        $page->call('move', $three->id, -1);
        $this->assertSame(['One', 'Three', 'Two'], $release->tracks()->pluck('title')->all());
        $this->assertSame([1, 2, 3], $release->tracks()->pluck('position')->all());
    }
}
