<?php

namespace App\Livewire\Releases;

use App\Livewire\Concerns\EnforcesLicense;
use App\Models\ActivityLog;
use App\Models\Release;
use App\Models\Track;
use App\Services\Catalog\ReleaseService;
use App\Services\Catalog\ReleaseWorkflow;
use App\Services\License\LicenseService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Release page: overview with completeness checklist and workflow actions,
 * tracks, and history.
 */
class Show extends Component
{
    use EnforcesLicense;

    public Release $release;

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    /** Workflow action waiting for a comment (reject / reopen). */
    public ?string $pendingAction = null;

    public string $comment = '';

    public function mount(Release $release): void
    {
        $this->authorize('view', $release);
        $this->release = $release;

        if (! in_array($this->tab, $this->tabs(), true)) {
            $this->tab = 'overview';
        }
    }

    /**
     * @return list<string>
     */
    protected function tabs(): array
    {
        return array_values(array_filter([
            'overview',
            app(LicenseService::class)->canUse('tracks') && auth()->user()->can('tracks.view') ? 'tracks' : null,
            app(LicenseService::class)->canUse('distribution') && auth()->user()->can('distribution.view') ? 'distribution' : null,
            'history',
        ]));
    }

    public function show(string $tab): void
    {
        $this->tab = in_array($tab, $this->tabs(), true) ? $tab : 'overview';
    }

    #[On('release-updated')]
    public function refreshRelease(): void
    {
        $this->release->refresh();
    }

    public function act(string $action, ReleaseWorkflow $workflow): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('releases');

        if (! in_array($action, $workflow->available(auth()->user(), $this->release), true)) {
            abort(403);
        }

        if ($workflow->requiresComment($action) && $this->pendingAction !== $action) {
            $this->pendingAction = $action;
            $this->comment = '';
            $this->resetValidation();

            return;
        }

        if ($workflow->requiresComment($action)) {
            $this->validate(['comment' => ['required', 'string', 'max:2000']], [], ['comment' => __('comment')]);
        }

        try {
            $workflow->transition(auth()->user(), $this->release, $action, $this->comment !== '' ? $this->comment : null);
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'danger');

            return;
        }

        $this->release->refresh();
        $this->reset('pendingAction', 'comment');
        $this->dispatch('toast', message: __('Status changed to :status.', ['status' => $this->release->status->label()]), type: 'success');
        $this->dispatch('release-updated');
    }

    public function cancelAction(): void
    {
        $this->reset('pendingAction', 'comment');
        $this->resetValidation();
    }

    public function delete(ReleaseService $releases)
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('releases');
        $this->authorize('delete', $this->release);

        try {
            $releases->delete(auth()->user(), $this->release);
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'danger');

            return null;
        }

        session()->flash('success', __(':title has been deleted.', ['title' => $this->release->displayTitle()]));

        return $this->redirectRoute('releases.index');
    }

    public function restore(ReleaseService $releases): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('releases');
        $this->authorize('restore', $this->release);

        $releases->restore(auth()->user(), $this->release);
        $this->release->refresh();
        $this->dispatch('toast', message: __(':title has been restored.', ['title' => $this->release->displayTitle()]), type: 'success');
    }

    public function render(ReleaseWorkflow $workflow): View
    {
        $release = $this->release;
        $release->load(['artists:artists.id,artists.name,artists.photo_path', 'genre:id,name', 'language:id,name']);
        $release->loadCount(['tracks', 'deliveries']);

        $data = [
            'tabs' => $this->tabs(),
            'actions' => $workflow->available(auth()->user(), $release),
            'actionLabels' => ReleaseWorkflow::labels(),
            'checklist' => $workflow->checklist($release),
        ];

        if ($this->tab === 'history') {
            $trackIds = Track::query()->where('release_id', $release->id)->pluck('id');
            $data['history'] = $release->history()->with('user:id,name,deleted_at')->get();
            $data['activity'] = ActivityLog::query()
                ->with('user:id,name,deleted_at')
                ->where(fn ($q) => $q
                    ->where(fn ($s) => $s->where('subject_type', $release->getMorphClass())->where('subject_id', $release->id))
                    ->orWhere(fn ($s) => $s->where('subject_type', (new Track)->getMorphClass())->whereIn('subject_id', $trackIds)))
                ->latest('id')->limit(50)->get();
        }

        return view('livewire.releases.show', $data)
            ->title($release->displayTitle())
            ->layoutData(['breadcrumbs' => [
                ['label' => __('Catalog')],
                ['label' => __('Releases'), 'url' => route('releases.index')],
                ['label' => $release->displayTitle()],
            ]]);
    }
}
