<div>
    <x-page-header :title="__('Artist balances')" :subtitle="__('Positive balances are owed to the artist; negative balances are advances or expenses still to be recouped.')" />

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <select class="form-select" style="max-width: 240px" wire:model.live="filter" aria-label="{{ __('Filter balances') }}">
            <option value="">{{ __('All artists with an account') }}</option>
            <option value="owed">{{ __('Owed to the artist') }}</option>
            <option value="unrecouped">{{ __('Unrecouped') }}</option>
        </select>
        <x-badge variant="success" icon="wallet">{{ __('Total owed: :amount', ['amount' => App\Support\Format::money($totalOwed, $currency)]) }}</x-badge>
    </div>

    <div class="card">
        @if ($rows->isEmpty())
            <x-empty-state icon="scale" :title="__('No balances yet')" :description="__('Balances appear when a royalty period is locked or an advance, expense or payment is recorded.')" />
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('Artist') }}</th>
                            <th class="text-end d-none d-md-table-cell">{{ __('Earned') }}</th>
                            <th class="text-end d-none d-md-table-cell">{{ __('Advances & expenses') }}</th>
                            <th class="text-end d-none d-lg-table-cell">{{ __('Paid') }}</th>
                            <th class="text-end">{{ __('Balance') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr wire:key="b-{{ $row['artist']->id }}">
                                <td>
                                    <a href="{{ route('artists.show', ['artist' => $row['artist'], 'tab' => 'earnings']) }}" class="d-flex align-items-center gap-2 text-reset text-decoration-none">
                                        <x-artist-avatar :artist="$row['artist']" size="28" /> <span class="fw-semibold">{{ $row['artist']->name }}</span>
                                    </a>
                                </td>
                                <td class="text-end text-nowrap d-none d-md-table-cell">{{ App\Support\Format::money($row['earned'], $currency) }}</td>
                                <td class="text-end text-nowrap d-none d-md-table-cell">{{ App\Support\Format::money($row['recoupable'], $currency) }}</td>
                                <td class="text-end text-nowrap d-none d-lg-table-cell">{{ App\Support\Format::money($row['paid'], $currency) }}</td>
                                <td @class(['text-end text-nowrap fw-semibold', 'text-success' => App\Support\Decimal::compare($row['balance'], '0') > 0, 'text-warning' => App\Support\Decimal::compare($row['balance'], '0') < 0])>
                                    {{ App\Support\Format::money($row['balance'], $currency) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
