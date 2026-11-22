<div>
    <x-page-header :title="$period->name" :subtitle="App\Support\Format::date($period->starts_on).' – '.App\Support\Format::date($period->ends_on)">
        @if ($canManage && ! $period->isLocked())
            <x-slot:actions>
                <button type="button" class="btn btn-light" wire:click="recalculate" wire:loading.attr="disabled" wire:target="recalculate">
                    <x-icon name="refresh-cw" size="16" /> {{ __('Recalculate') }}
                </button>
                @if ($hasEnded)
                    <button type="button" class="btn btn-primary" wire:click="lock"
                            wire:confirm="{{ __('Lock :name? Earnings are posted to each artist’s account and the period can no longer change. This cannot be undone.', ['name' => $period->name]) }}">
                        <x-icon name="lock" size="16" /> {{ __('Lock period') }}
                    </button>
                @else
                    <span class="d-inline-block" tabindex="0" data-bs-toggle="tooltip" title="{{ __('Can be locked after :date', ['date' => App\Support\Format::date($period->ends_on)]) }}">
                        <button type="button" class="btn btn-primary" disabled><x-icon name="lock" size="16" /> {{ __('Lock period') }}</button>
                    </span>
                @endif
            </x-slot:actions>
        @endif
    </x-page-header>

    @if ($period->isLocked())
        <x-alert variant="success" class="mb-4" :title="__('Locked')">
            {{ __('Locked on :date by :user. Earnings have been posted to artist accounts; corrections are made with adjustments on the artist’s Earnings tab.', ['date' => App\Support\Format::datetime($period->locked_at), 'user' => $period->lockedBy?->name ?? __('System')]) }}
        </x-alert>
    @elseif ($unmatchedCount > 0)
        <x-alert variant="warning" class="mb-4">
            {{ trans_choice('{1} 1 sales line (:amount) is not matched to your catalogue and is not paid to anyone yet.|[2,*] :count sales lines (:amount) are not matched to your catalogue and are not paid to anyone yet.', $unmatchedCount, ['count' => number_format($unmatchedCount), 'amount' => App\Support\Format::money($unmatchedAmount, $currency)]) }}
            <button type="button" class="btn btn-link p-0 ms-1 fw-semibold align-baseline" wire:click="show('unmatched')">{{ __('Match them') }} &rarr;</button>
        </x-alert>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Matched revenue')" :value="App\Support\Format::money($totals['revenue'], $currency)" icon="wallet" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Artist earnings')" :value="App\Support\Format::money($totals['artist'], $currency)" icon="mic-vocal" variant="success" :hint="trans_choice('{1} 1 artist|[0,*] :count artists', $totals['artists'], ['count' => $totals['artists']])" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Label share')" :value="App\Support\Format::money($totals['label'], $currency)" icon="building-2" variant="info" /></div>
        <div class="col-sm-6 col-xl-3"><x-stat-card :label="__('Unmatched')" :value="App\Support\Format::money($unmatchedAmount, $currency)" icon="circle-help" :variant="$unmatchedCount > 0 ? 'warning' : null" /></div>
    </div>

    @php($tabLabels = ['imports' => [__('Statements'), 'file-spreadsheet'], 'unmatched' => [__('Unmatched sales'), 'circle-help'], 'earnings' => [__('Earnings by artist'), 'mic-vocal']])
    <ul class="nav nav-underline mb-4 ld-settings-tabs" role="tablist">
        @foreach ($tabLabels as $key => [$label, $icon])
            <li class="nav-item" role="presentation">
                <button type="button" role="tab" wire:click="show('{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        @class(['nav-link d-flex align-items-center gap-2', 'active' => $tab === $key])>
                    <x-icon :name="$icon" size="16" /> {{ $label }}
                    @if ($key === 'unmatched' && $unmatchedCount) <span class="ld-badge ld-badge--warning py-0 px-2">{{ number_format($unmatchedCount) }}</span> @endif
                </button>
            </li>
        @endforeach
    </ul>

    @if ($tab === 'unmatched')
        <livewire:royalties.unmatched :period="$period" :key="'unmatched-'.$period->id.'-'.$period->status" />
    @elseif ($tab === 'earnings')
        <div class="card">
            @if ($earnings->isEmpty())
                <x-empty-state icon="mic-vocal" :title="__('No earnings yet')" :description="__('Earnings appear once statements are imported and matched to tracks with artists.')" />
            @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('Artist') }}</th>
                                <th class="text-end d-none d-md-table-cell">{{ __('Units') }}</th>
                                <th class="text-end">{{ __('Revenue') }}</th>
                                <th class="text-end">{{ __('Artist') }}</th>
                                <th class="text-end d-none d-lg-table-cell">{{ __('Label') }}</th>
                                <th class="text-end"><span class="visually-hidden">{{ __('Statement') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($earnings as $row)
                                <tr>
                                    <td>
                                        @if ($row['model'])
                                            <a href="{{ route('artists.show', ['artist' => $row['model'], 'tab' => 'earnings']) }}" class="d-flex align-items-center gap-2 text-reset text-decoration-none">
                                                <x-artist-avatar :artist="$row['model']" size="28" /> <span class="fw-semibold">{{ $row['model']->name }}</span>
                                            </a>
                                        @endif
                                    </td>
                                    <td class="text-end d-none d-md-table-cell">{{ number_format($row['units']) }}</td>
                                    <td class="text-end text-nowrap">{{ App\Support\Format::money($row['revenue'], $currency) }}</td>
                                    <td class="text-end text-nowrap fw-semibold">{{ App\Support\Format::money($row['artist'], $currency) }}</td>
                                    <td class="text-end text-nowrap d-none d-lg-table-cell">{{ App\Support\Format::money($row['label'], $currency) }}</td>
                                    <td class="text-end">
                                        @if ($row['model'])
                                            <a href="{{ route('royalties.statements.show', [$period, $row['model']]) }}" class="btn btn-sm btn-light">{{ __('Statement') }}</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @else
        <livewire:royalties.imports :period="$period" :key="'imports-'.$period->id.'-'.$period->status" />
    @endif
</div>
