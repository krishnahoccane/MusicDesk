<?php

namespace App\Livewire\Distribution;

use App\Livewire\Concerns\EnforcesLicense;
use App\Models\Dsp;
use App\Services\Distribution\DspService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * Business → Distribution → Stores: the list of stores releases can be sent to.
 */
class Dsps extends Component
{
    use EnforcesLicense;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public string $website = '';

    public int|string $lead_time_days = '';

    public string $delivery_notes = '';

    public bool $is_active = true;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('dsps.manage'), 403);
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $dsp = Dsp::query()->findOrFail($id);
        $this->resetForm();
        $this->editingId = $dsp->id;
        $this->fill([
            'name' => $dsp->name,
            'website' => (string) $dsp->website,
            'lead_time_days' => (string) $dsp->lead_time_days,
            'delivery_notes' => (string) $dsp->delivery_notes,
            'is_active' => $dsp->is_active,
        ]);
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(DspService $dsps): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('distribution');
        abort_unless(auth()->user()->can('dsps.manage'), 403);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('dsps', 'name')->ignore($this->editingId)],
            'website' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'lead_time_days' => ['nullable', 'integer', 'between:0,365'],
            'delivery_notes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ], [], ['lead_time_days' => __('lead time')]);

        $dsp = $this->editingId ? Dsp::query()->findOrFail($this->editingId) : null;
        $dsps->save(auth()->user(), $dsp, $data);

        $this->dispatch('toast', message: $dsp ? __('Store updated.') : __('Store added.'), type: 'success');
        $this->resetForm();
    }

    public function toggleActive(int $id, DspService $dsps): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('distribution');
        abort_unless(auth()->user()->can('dsps.manage'), 403);

        $dsp = Dsp::query()->findOrFail($id);
        $dsps->save(auth()->user(), $dsp, [...$dsp->only(['name', 'website', 'lead_time_days', 'delivery_notes']), 'is_active' => ! $dsp->is_active]);
    }

    public function delete(int $id, DspService $dsps): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('distribution');
        abort_unless(auth()->user()->can('dsps.manage'), 403);

        try {
            $dsps->delete(auth()->user(), Dsp::query()->findOrFail($id));
            $this->dispatch('toast', message: __('Store deleted.'), type: 'success');
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'danger');
        }
    }

    protected function resetForm(): void
    {
        $this->reset('showForm', 'editingId', 'name', 'website', 'lead_time_days', 'delivery_notes', 'is_active');
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.distribution.dsps', [
            'dsps' => Dsp::query()->withCount('deliveries')->orderByDesc('is_active')->orderBy('name')->get(),
        ])->title(__('Stores'))->layoutData(['breadcrumbs' => [
            ['label' => __('Business')], ['label' => __('Distribution'), 'url' => route('distribution.index')], ['label' => __('Stores')],
        ]]);
    }
}
