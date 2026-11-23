<?php

namespace App\Livewire\Labels;

use App\Livewire\Concerns\EnforcesLicense;
use App\Models\Label;
use App\Services\Activity\ActivityLogger;
use App\Support\CatalogDefaults;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Catalog → Labels: sub-labels (imprints) under the main label.
 */
class Index extends Component
{
    use EnforcesLicense, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $parent_id = '';

    public string $code = '';

    public string $contact_name = '';

    public string $email = '';

    public string $website = '';

    public string $country = '';

    public string $notes = '';

    public bool $is_active = true;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('labels.view'), 403);
        $this->ensureFeature('releases');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        abort_unless(auth()->user()->can('labels.manage'), 403);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        abort_unless(auth()->user()->can('labels.manage'), 403);
        $label = Label::query()->findOrFail($id);

        $this->resetForm();
        $this->editingId = $label->id;
        $this->fill([
            'name' => $label->name,
            'parent_id' => (string) $label->parent_id,
            'code' => (string) $label->code,
            'contact_name' => (string) $label->contact_name,
            'email' => (string) $label->email,
            'website' => (string) $label->website,
            'country' => (string) $label->country,
            'notes' => (string) $label->notes,
            'is_active' => $label->is_active,
        ]);
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(ActivityLogger $activity): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('releases');
        abort_unless(auth()->user()->can('labels.manage'), 403);

        $label = $this->editingId ? Label::query()->findOrFail($this->editingId) : null;
        $this->code = strtoupper(trim($this->code));
        $this->country = strtoupper(trim($this->country));

        $data = $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'parent_id' => ['nullable', Rule::exists('labels', 'id')->whereNull('deleted_at'),
                Rule::notIn($label ? $label->descendantIds() : [])],
            'code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/', Rule::unique('labels', 'code')->ignore($label?->id)],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'country' => ['nullable', 'regex:/^[A-Z]{2}$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ], [
            'parent_id.not_in' => __('A label cannot be placed under itself or one of its own sub-labels.'),
            'code.regex' => __('Use capital letters, digits and dashes.'),
            'country.regex' => __('Use the two-letter country code (e.g. GB, IN, US).'),
        ], ['parent_id' => __('parent label'), 'code' => __('label code')]);

        $data['parent_id'] = $data['parent_id'] !== '' && $data['parent_id'] !== null ? (int) $data['parent_id'] : null;
        foreach (['code', 'contact_name', 'email', 'website', 'country', 'notes'] as $key) {
            $data[$key] = ($data[$key] ?? '') === '' ? null : $data[$key];
        }

        $label = $label ? tap($label)->update($data) : Label::query()->create($data);
        $activity->log($this->editingId ? 'label.updated' : 'label.created', $this->editingId ? __('Updated label :name', ['name' => $label->name]) : __('Added label :name', ['name' => $label->name]), $label);

        $this->dispatch('toast', message: $this->editingId ? __('Label updated.') : __('Label added.'), type: 'success');
        $this->resetForm();
    }

    public function toggle(int $id, ActivityLogger $activity): void
    {
        $this->ensureLicenseAllowsWrites();
        abort_unless(auth()->user()->can('labels.manage'), 403);
        $label = Label::query()->findOrFail($id);
        $label->update(['is_active' => ! $label->is_active]);
        $activity->log('label.updated', $label->is_active ? __('Activated label :name', ['name' => $label->name]) : __('Deactivated label :name', ['name' => $label->name]), $label);

        $this->dispatch('toast', message: $label->is_active ? __(':name is active.', ['name' => $label->name]) : __(':name is inactive. It can no longer be chosen for new releases.', ['name' => $label->name]), type: 'success');
    }

    public function delete(int $id, ActivityLogger $activity): void
    {
        $this->ensureLicenseAllowsWrites();
        abort_unless(auth()->user()->can('labels.manage'), 403);
        $label = Label::query()->withCount(['releases', 'children'])->findOrFail($id);

        if ($label->releases_count > 0 || $label->children_count > 0) {
            $this->dispatch('toast', message: __(':name still has releases or sub-labels. Move them first, or deactivate the label instead.', ['name' => $label->name]), type: 'danger');

            return;
        }

        $label->delete();
        $activity->log('label.deleted', __('Deleted label :name', ['name' => $label->name]), $label);
        $this->dispatch('toast', message: __('Label deleted.'), type: 'success');
    }

    protected function resetForm(): void
    {
        $this->reset('showForm', 'editingId', 'name', 'parent_id', 'code', 'contact_name', 'email', 'website', 'country', 'notes', 'is_active');
        $this->resetValidation();
    }

    public function render(): View
    {
        $exclude = $this->editingId ? Label::query()->find($this->editingId)?->descendantIds() ?? [] : [];

        return view('livewire.labels.index', [
            'labels' => Label::query()
                ->with(['parent:id,name', 'owner:id,name,email'])
                ->withCount(['releases', 'children'])
                ->when($this->search !== '', fn ($q) => $q->search($this->search))
                ->when($this->status === 'active', fn ($q) => $q->where('is_active', true))
                ->when($this->status === 'inactive', fn ($q) => $q->where('is_active', false))
                ->orderBy('name')
                ->paginate(25),
            'parents' => Label::query()->with('parent')->whereNotIn('id', $exclude)->orderBy('name')->get(),
            'mainLabel' => CatalogDefaults::labelName(),
            'canManage' => auth()->user()->can('labels.manage'),
        ])->title(__('Labels'))->layoutData(['breadcrumbs' => [
            ['label' => __('Catalog')], ['label' => __('Labels')],
        ]]);
    }
}
