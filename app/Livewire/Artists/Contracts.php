<?php

namespace App\Livewire\Artists;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Livewire\Concerns\EnforcesLicense;
use App\Models\Artist;
use App\Models\ArtistContract;
use App\Services\ContractService;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Contracts extends Component
{
    use EnforcesLicense;

    #[Locked]
    public Artist $artist;

    public bool $showForm = false;

    #[Locked]
    public ?int $editingId = null;

    public string $title = '';

    public string $type = 'recording';

    public string $status = 'draft';

    public string $start_date = '';

    public string $end_date = '';

    public string $artist_share = '';

    public string $advance_amount = '';

    public string $currency = '';

    public string $territory = '';

    public string $notes = '';

    public function mount(Artist $artist): void
    {
        $this->ensureFeature('documents');
        abort_unless(auth()->user()->can('contracts.view'), 403);
        $this->artist = $artist;
    }

    public function create(): void
    {
        $this->authorize('manageContracts', $this->artist);
        $this->resetForm();
        $this->currency = (string) config('labeldesk.currency');
        $this->territory = __('Worldwide');
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('manageContracts', $this->artist);
        $c = $this->find($id);

        $this->resetForm();
        $this->editingId = $c->id;
        $this->fill([
            'title' => $c->title,
            'type' => $c->type->value,
            'status' => $c->status->value,
            'start_date' => (string) $c->start_date?->format('Y-m-d'),
            'end_date' => (string) $c->end_date?->format('Y-m-d'),
            'artist_share' => $c->artist_share === null ? '' : rtrim(rtrim((string) $c->artist_share, '0'), '.'),
            'advance_amount' => $c->advance_amount === null ? '' : rtrim(rtrim((string) $c->advance_amount, '0'), '.'),
            'currency' => (string) ($c->currency ?? config('labeldesk.currency')),
            'territory' => (string) $c->territory,
            'notes' => (string) $c->notes,
        ]);
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(ContractService $contracts): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('documents');
        $this->authorize('manageContracts', $this->artist);

        $this->artist_share = str_replace([',', '%', ' '], '', $this->artist_share);
        $this->advance_amount = str_replace([',', ' '], '', $this->advance_amount);

        $data = $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'type' => ['required', Rule::enum(ContractType::class)],
            'status' => ['required', Rule::enum(ContractStatus::class)],
            'start_date' => ['nullable', 'date_format:Y-m-d', Rule::requiredIf($this->status === ContractStatus::Active->value)],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'artist_share' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,4})?$/', function (string $a, mixed $v, Closure $fail) {
                if ($v !== '' && $v !== null && bccomp((string) $v, '100', 4) === 1) {
                    $fail(__('The artist share cannot be more than 100%.'));
                }
            }],
            'advance_amount' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'currency' => ['nullable', Rule::in((array) config('labeldesk.installer.currencies')), 'required_with:advance_amount'],
            'territory' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'artist_share.regex' => __('Enter a percentage such as 70 or 62.5.'),
            'advance_amount.regex' => __('Enter an amount such as 5000 or 1250.50.'),
            'start_date.required' => __('An active contract needs a start date.'),
        ], ['artist_share' => __('artist share'), 'advance_amount' => __('advance')]);

        $contract = $this->editingId ? $this->find($this->editingId) : null;
        $contracts->save(auth()->user(), $this->artist, $contract, $data);

        $this->dispatch('toast', message: $contract ? __('Contract updated.') : __('Contract added.'), type: 'success');

        $this->dispatch('artist-updated');
        $this->resetForm();
    }

    public function delete(int $id, ContractService $contracts): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('documents');
        $this->authorize('manageContracts', $this->artist);

        $contracts->delete(auth()->user(), $this->artist, $this->find($id));
        $this->dispatch('toast', message: __('Contract deleted.'), type: 'success');
        $this->dispatch('artist-updated');
        if ($this->editingId === $id) {
            $this->resetForm();
        }
    }

    protected function find(int $id): ArtistContract
    {
        return ArtistContract::query()->where('artist_id', $this->artist->id)->find($id) ?? abort(404);
    }

    protected function resetForm(): void
    {
        $this->reset('showForm', 'editingId', 'title', 'type', 'status', 'start_date', 'end_date', 'artist_share', 'advance_amount', 'currency', 'territory', 'notes');
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.artists.contracts', [
            'contracts' => $this->artist->contracts()->withCount('documents')->orderByDesc('start_date')->orderByDesc('id')->get(),
            'types' => ContractType::options(),
            'statuses' => ContractStatus::options(),
            'currencies' => (array) config('labeldesk.installer.currencies'),
            'canManage' => auth()->user()->can('manageContracts', $this->artist),
        ]);
    }
}
