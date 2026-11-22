<?php

namespace App\Livewire\Artists;

use App\Enums\LedgerEntryType;
use App\Livewire\Concerns\EnforcesLicense;
use App\Models\Artist;
use App\Models\ArtistLedgerEntry;
use App\Models\RoyaltyEarning;
use App\Models\RoyaltyPeriod;
use App\Services\Royalties\ArtistAccountService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * Artist profile → Earnings: account balance, ledger, manual entries
 * (advances, expenses, payments, adjustments) and statements per period.
 */
class Earnings extends Component
{
    use EnforcesLicense;

    #[Locked]
    public Artist $artist;

    public bool $recording = false;

    public string $type = 'payment';

    public string $amount = '';

    public string $direction = 'credit';

    public string $entry_date = '';

    public string $description = '';

    public string $method = '';

    public string $reference = '';

    public function mount(Artist $artist): void
    {
        $this->ensureFeature('royalties');
        abort_unless(auth()->user()->can('payments.view'), 403);
        $this->artist = $artist;
    }

    protected function ensureManage(): void
    {
        $this->ensureLicenseAllowsWrites();
        $this->ensureFeature('royalties');
        abort_unless(auth()->user()->can('payments.manage'), 403);
    }

    public function startRecording(string $type = 'payment'): void
    {
        $this->ensureManage();
        $this->reset('amount', 'description', 'method', 'reference', 'direction');
        $this->type = array_key_exists($type, LedgerEntryType::manualOptions()) ? $type : 'payment';
        $this->entry_date = now()->setTimezone(config('labeldesk.display_timezone'))->toDateString();
        $this->recording = true;
        $this->resetValidation();
    }

    public function record(ArtistAccountService $accounts): void
    {
        $this->ensureManage();

        $this->amount = str_replace([',', ' '], '', $this->amount);
        $data = $this->validate([
            'type' => ['required', Rule::in(array_keys(LedgerEntryType::manualOptions()))],
            'amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'direction' => ['required', Rule::in(['credit', 'debit'])],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'method' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:100', 'not_regex:/^reversal:/'],
        ], ['amount.regex' => __('Enter an amount such as 1500 or 99.95.')]);

        try {
            $accounts->record(auth()->user(), $this->artist, $data);
        } catch (RuntimeException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $this->recording = false;
        $this->dispatch('toast', message: __(':type recorded.', ['type' => LedgerEntryType::from($this->type)->label()]), type: 'success');
    }

    public function reverse(int $id, ArtistAccountService $accounts): void
    {
        $this->ensureManage();
        $entry = ArtistLedgerEntry::query()->with('artist')->where('artist_id', $this->artist->id)->find($id) ?? abort(404);

        try {
            $accounts->reverse(auth()->user(), $entry);
        } catch (RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'danger');

            return;
        }

        $this->dispatch('toast', message: __('Entry reversed with an adjustment.'), type: 'success');
    }

    public function render(): View
    {
        $entries = ArtistLedgerEntry::query()->with('creator:id,name,deleted_at')
            ->where('artist_id', $this->artist->id)->orderByDesc('entry_date')->orderByDesc('id')->get();

        $reversed = $entries->pluck('reference')->filter(fn ($r) => str_starts_with((string) $r, 'reversal:'))
            ->map(fn ($r) => (int) substr($r, 9))->all();

        $periodIds = RoyaltyEarning::query()->where('artist_id', $this->artist->id)->distinct()->pluck('royalty_period_id');

        return view('livewire.artists.earnings', [
            'balance' => ArtistAccountService::balance($this->artist),
            'entries' => $entries,
            'reversed' => $reversed,
            'periods' => RoyaltyPeriod::query()->whereIn('id', $periodIds)->orderByDesc('starts_on')->get(),
            'types' => LedgerEntryType::manualOptions(),
            'canManage' => auth()->user()->can('payments.manage') && ! $this->artist->trashed(),
            'currency' => config('labeldesk.currency'),
        ]);
    }
}
