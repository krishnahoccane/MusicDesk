<?php

namespace App\Livewire\Royalties;

use App\Enums\LedgerEntryType;
use App\Models\Artist;
use App\Models\ArtistLedgerEntry;
use App\Support\Decimal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Royalties → Artist balances: what is owed to (or recoupable from) each artist.
 */
class Balances extends Component
{
    #[Url(except: '')]
    public string $filter = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('payments.view'), 403);
    }

    public function render(): View
    {
        $rows = [];
        foreach (ArtistLedgerEntry::query()->select(['artist_id', 'type', 'amount'])->cursor() as $entry) {
            $row = $rows[$entry->artist_id] ??= ['earned' => '0', 'recoupable' => '0', 'paid' => '0', 'balance' => '0'];
            $amount = (string) $entry->amount;
            $row['balance'] = Decimal::add($row['balance'], $amount);
            match ($entry->type) {
                LedgerEntryType::Earnings => $row['earned'] = Decimal::add($row['earned'], $amount),
                LedgerEntryType::Payment => $row['paid'] = Decimal::sub($row['paid'], $amount),
                LedgerEntryType::Advance, LedgerEntryType::Expense => $row['recoupable'] = Decimal::sub($row['recoupable'], $amount),
                default => null,
            };
            $rows[$entry->artist_id] = $row;
        }

        $artists = Artist::withTrashed()->whereIn('id', array_keys($rows))->get()->keyBy('id');
        $list = collect($rows)
            ->map(fn ($row, $id) => $row + ['artist' => $artists[$id] ?? null])
            ->filter(fn ($row) => $row['artist'] !== null)
            ->when($this->filter === 'owed', fn ($c) => $c->filter(fn ($r) => Decimal::compare($r['balance'], '0') > 0))
            ->when($this->filter === 'unrecouped', fn ($c) => $c->filter(fn ($r) => Decimal::compare($r['balance'], '0') < 0))
            ->sortBy(fn ($r) => $r['artist']->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return view('livewire.royalties.balances', [
            'rows' => $list,
            'totalOwed' => Decimal::sum($list->pluck('balance')->filter(fn ($b) => Decimal::compare($b, '0') > 0)),
            'currency' => config('labeldesk.currency'),
        ])->title(__('Artist balances'))->layoutData(['breadcrumbs' => [
            ['label' => __('Business')], ['label' => __('Royalties'), 'url' => route('royalties.index')], ['label' => __('Artist balances')],
        ]]);
    }
}
