<x-layouts.app :title="__('Statement — :artist', ['artist' => $artist->name])"
               :breadcrumbs="auth()->user()->can('royalties.view')
                   ? [['label' => __('Business')], ['label' => __('Royalties'), 'url' => route('royalties.index')], ['label' => $period->name, 'url' => route('royalties.periods.show', ['period' => $period, 'tab' => 'earnings'])], ['label' => $artist->name]]
                   : [['label' => __('Artists'), 'url' => route('artists.index')], ['label' => $artist->name, 'url' => route('artists.show', ['artist' => $artist, 'tab' => 'earnings'])], ['label' => $period->name]]">
    <x-page-header :title="__('Royalty statement')" :subtitle="$artist->name.' · '.$period->name.' ('.App\Support\Format::date($period->starts_on).' – '.App\Support\Format::date($period->ends_on).')'">
        <x-slot:actions>
            <a href="{{ route('royalties.statements.export', [$period, $artist, 'xlsx']) }}" class="btn btn-light"><x-icon name="file-spreadsheet" size="16" /> {{ __('Excel') }}</a>
            <a href="{{ route('royalties.statements.export', [$period, $artist, 'csv']) }}" class="btn btn-light"><x-icon name="download" size="16" /> CSV</a>
            <button type="button" class="btn btn-light" onclick="window.print()"><x-icon name="printer" size="16" /> {{ __('Print') }}</button>
        </x-slot:actions>
    </x-page-header>

    @if ($provisional)
        <x-alert variant="info" class="mb-4" :title="__('Provisional')">
            {{ __('This period is still open. Figures may change until it is locked, and the earnings are not yet in the artist’s account.') }}
        </x-alert>
    @endif

    <div class="card mb-3">
        <div class="card-header">
            <x-icon name="audio-lines" size="18" class="text-muted" />
            <h2 class="card-title">{{ __('Earnings by track') }}</h2>
        </div>
        @if ($rows->isEmpty())
            <div class="card-body small text-muted">{{ __('No sales for this artist in this period.') }}</div>
        @else
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('Track') }}</th>
                            <th class="d-none d-md-table-cell">{{ __('ISRC') }}</th>
                            <th class="text-end">{{ __('Units') }}</th>
                            <th class="text-end">{{ __('Revenue') }}</th>
                            <th class="text-end">{{ __('Share') }}</th>
                            <th class="text-end">{{ __('Earnings') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td><div class="fw-semibold">{{ $row['title'] }}</div><div class="small text-muted">{{ $row['release'] }}</div></td>
                                <td class="d-none d-md-table-cell font-monospace small">{{ $row['isrc'] ?? '—' }}</td>
                                <td class="text-end">{{ number_format($row['units']) }}</td>
                                <td class="text-end text-nowrap">{{ App\Support\Format::money($row['revenue'], $currency) }}</td>
                                <td class="text-end">{{ App\Support\Format::percent($row['share']) }}</td>
                                <td class="text-end text-nowrap fw-semibold">{{ App\Support\Format::money($row['amount'], $currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="fw-semibold">
                            <td>{{ __('Total') }}</td>
                            <td class="d-none d-md-table-cell"></td>
                            <td class="text-end">{{ number_format($totals['units']) }}</td>
                            <td class="text-end text-nowrap">{{ App\Support\Format::money($totals['revenue'], $currency) }}</td>
                            <td></td>
                            <td class="text-end text-nowrap">{{ App\Support\Format::money($totals['amount'], $currency) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </div>

    <div class="card">
        <div class="card-header">
            <x-icon name="book-open" size="18" class="text-muted" />
            <h2 class="card-title">{{ __('Account') }}</h2>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <tbody>
                    <tr>
                        <td colspan="2" class="fw-semibold">{{ __('Opening balance') }} <span class="small text-muted fw-normal">({{ App\Support\Format::date($period->starts_on) }})</span></td>
                        <td class="text-end text-nowrap fw-semibold">{{ App\Support\Format::money($opening, $currency) }}</td>
                    </tr>
                    @foreach ($entries as $entry)
                        <tr>
                            <td class="small text-nowrap">{{ App\Support\Format::date($entry->entry_date) }}</td>
                            <td>{{ $entry->description }} <span class="small text-muted">· {{ $entry->type->label() }}</span></td>
                            <td class="text-end text-nowrap">{{ App\Support\Format::money((string) $entry->amount, $currency) }}</td>
                        </tr>
                    @endforeach
                    <tr>
                        <td colspan="2" class="fw-semibold">{{ __('Closing balance') }} <span class="small text-muted fw-normal">({{ App\Support\Format::date($period->ends_on) }})</span></td>
                        <td class="text-end text-nowrap fw-semibold">{{ App\Support\Format::money($closing, $currency) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</x-layouts.app>
