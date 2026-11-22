<?php

namespace Tests\Feature\Catalog;

use App\Enums\SystemRole;
use App\Exceptions\CatalogException;
use App\Livewire\Releases\Show;
use App\Livewire\Tracks\Form as TrackForm;
use App\Models\Release;
use App\Models\User;
use App\Services\Catalog\ReleaseWorkflow;
use Database\Seeders\CatalogReferenceSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\CreatesCatalog;
use Tests\TestCase;

class ReleaseWorkflowTest extends TestCase
{
    use CreatesCatalog, RefreshDatabase;

    protected User $artistManager;

    protected User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, CatalogReferenceSeeder::class]);
        $this->activateLicense();
        Storage::fake('local');

        $this->artistManager = $this->makeUser(SystemRole::ArtistManager);     // can submit, not review
        $this->reviewer = $this->makeUser(SystemRole::DistributionManager);    // can review
    }

    public function test_incomplete_release_cannot_be_submitted(): void
    {
        $release = Release::factory()->create();

        Livewire::actingAs($this->artistManager)->test(Show::class, ['release' => $release])
            ->call('act', 'submit')
            ->assertDispatched('toast', type: 'danger');

        $this->assertSame('draft', $release->fresh()->status->value);
        $checklist = collect(app(ReleaseWorkflow::class)->checklist($release))->pluck('ok', 'label');
        $this->assertFalse($checklist[__('Artwork')]);
        $this->assertFalse($checklist[__('At least one track')]);
    }

    public function test_full_approval_cycle_with_history(): void
    {
        $release = $this->completeRelease();
        $this->assertTrue(collect(app(ReleaseWorkflow::class)->checklist($release))->every(fn ($i) => $i['ok']));

        // Artist Manager submits.
        Livewire::actingAs($this->artistManager)->test(Show::class, ['release' => $release])->call('act', 'submit');
        $this->assertSame('submitted', $release->fresh()->status->value);
        $this->assertNotNull($release->fresh()->submitted_at);

        // …but cannot approve.
        Livewire::actingAs($this->artistManager)->test(Show::class, ['release' => $release->fresh()])->call('act', 'approve')->assertForbidden();

        // Content is locked while under review.
        $this->actingAs($this->artistManager)->get(route('releases.edit', $release))->assertForbidden();
        $this->actingAs($this->artistManager)->get(route('releases.tracks.create', $release))->assertForbidden();
        Livewire::actingAs($this->artistManager)->test(TrackForm::class, ['track' => $this->trackOf($release)])
            ->set('title', 'Changed')->call('save')->assertForbidden();

        // Reviewer starts the review and requests changes (comment required).
        $page = Livewire::actingAs($this->reviewer)->test(Show::class, ['release' => $release->fresh()]);
        $page->call('act', 'start_review');
        $this->assertSame('in_review', $release->fresh()->status->value);
        $page->call('act', 'reject')->assertSet('pendingAction', 'reject');
        $page->call('act', 'reject')->assertHasErrors('comment');
        $page->set('comment', 'Please fix the ℗ line.')->call('act', 'reject')->assertHasNoErrors();
        $this->assertSame('rejected', $release->fresh()->status->value);

        // Back to editable; resubmit, approve, mark ready.
        $this->actingAs($this->artistManager)->get(route('releases.edit', $release))->assertOk();
        Livewire::actingAs($this->artistManager)->test(Show::class, ['release' => $release->fresh()])->call('act', 'submit');
        Livewire::actingAs($this->reviewer)->test(Show::class, ['release' => $release->fresh()])->call('act', 'approve');
        $this->assertSame('approved', $release->fresh()->status->value);
        $this->assertNotNull($release->fresh()->approved_at);
        Livewire::actingAs($this->reviewer)->test(Show::class, ['release' => $release->fresh()])->call('act', 'mark_ready');
        $this->assertSame('ready', $release->fresh()->status->value);

        $history = $release->history()->get()->reverse()->values();
        $this->assertSame(
            ['submitted', 'in_review', 'rejected', 'submitted', 'approved', 'ready'],
            $history->map(fn ($h) => $h->to_status->value)->all(),
        );
        $this->assertSame('Please fix the ℗ line.', $history[2]->comment);
        $this->assertSame($this->reviewer->id, $history[2]->user_id);
        $this->assertDatabaseHas('activity_logs', ['event' => 'release.approve']);
    }

    public function test_reopen_requires_review_permission_and_a_comment(): void
    {
        $release = $this->completeRelease();
        $release->forceFill(['status' => 'approved'])->save();

        Livewire::actingAs($this->artistManager)->test(Show::class, ['release' => $release])->call('act', 'reopen')->assertForbidden();

        $this->expectException(CatalogException::class);
        app(ReleaseWorkflow::class)->transition($this->reviewer, $release, 'reopen', '  ');
    }

    public function test_stale_transitions_are_refused(): void
    {
        $release = $this->completeRelease();
        $release->forceFill(['status' => 'submitted'])->save();
        $stale = Release::query()->find($release->id);

        app(ReleaseWorkflow::class)->transition($this->reviewer, $release, 'approve');

        // A second reviewer acting on an out-of-date page cannot reject an approved release.
        $this->expectException(CatalogException::class);
        app(ReleaseWorkflow::class)->transition($this->reviewer, $stale, 'reject', 'Too late');
    }

    public function test_approved_release_cannot_be_deleted(): void
    {
        $release = $this->completeRelease();
        $release->forceFill(['status' => 'approved'])->save();
        $admin = $this->makeUser(SystemRole::SuperAdmin);

        Livewire::actingAs($admin)->test(Show::class, ['release' => $release])->call('delete')->assertForbidden();
        $this->assertNotSoftDeleted($release);
    }
}
