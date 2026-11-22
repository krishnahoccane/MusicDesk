<?php

namespace App\Livewire\Contributors;

use App\Livewire\Concerns\EnforcesLicense;
use App\Models\Contributor;
use App\Services\Catalog\ContributorService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * Catalog → Contributors: songwriters, producers and other credited people.
 */
class Index extends Component
{
    use EnforcesLicense, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $legal_name = '';

    public string $ipi_number = '';

    public string $pro = '';

    public string $email = '';

    public string $notes = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('contributors.view'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        abort_unless(auth()->user()->can('contributors.manage'), 403);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        abort_unless(auth()->user()->can('contributors.manage'), 403);
        $c = Contributor::query()->findOrFail($id);

        $this->resetForm();
        $this->editingId = $c->id;
        $this->fill([
            'name' => $c->name,
            'legal_name' => (string) $c->legal_name,
            'ipi_number' => (string) $c->ipi_number,
            'pro' => (string) $c->pro,
            'email' => (string) $c->email,
            'notes' => (string) $c->notes,
        ]);
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(ContributorService $contributors): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('releases');
        abort_unless(auth()->user()->can('contributors.manage'), 403);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'ipi_number' => ['nullable', 'regex:/^\s*\d{9,11}\s*$/'],
            'pro' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], ['ipi_number.regex' => __('An IPI name number has 9 to 11 digits.')], ['ipi_number' => __('IPI number'), 'pro' => __('PRO')]);

        $contributor = $this->editingId ? Contributor::query()->findOrFail($this->editingId) : null;
        $contributors->save(auth()->user(), $contributor, $data);

        $this->dispatch('toast', message: $contributor ? __('Contributor updated.') : __('Contributor added.'), type: 'success');
        $this->resetForm();
    }

    public function delete(int $id, ContributorService $contributors): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('releases');
        abort_unless(auth()->user()->can('contributors.manage'), 403);

        try {
            $contributors->delete(auth()->user(), Contributor::query()->findOrFail($id));
            $this->dispatch('toast', message: __('Contributor deleted.'), type: 'success');
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'danger');
        }
    }

    protected function resetForm(): void
    {
        $this->reset('showForm', 'editingId', 'name', 'legal_name', 'ipi_number', 'pro', 'email', 'notes');
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.contributors.index', [
            'contributors' => Contributor::query()
                ->withCount('credits')
                ->when($this->search !== '', fn ($q) => $q->search($this->search))
                ->orderBy('name')
                ->paginate(25),
            'canManage' => auth()->user()->can('contributors.manage'),
        ])->title(__('Contributors'))->layoutData(['breadcrumbs' => [
            ['label' => __('Catalog')], ['label' => __('Contributors')],
        ]]);
    }
}
