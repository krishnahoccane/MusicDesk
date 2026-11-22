<div class="row g-3">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <x-icon name="git-pull-request-arrow" size="18" class="text-muted" />
                <h2 class="card-title">{{ __('Status history') }}</h2>
            </div>
            @if ($history->isEmpty())
                <div class="card-body small text-muted">{{ __('The release has not been submitted yet.') }}</div>
            @else
                <ul class="list-unstyled mb-0">
                    @foreach ($history as $entry)
                        <li class="px-4 py-3 border-bottom">
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                @if ($entry->from_status)
                                    <x-badge :variant="$entry->from_status->variant()">{{ $entry->from_status->label() }}</x-badge>
                                    <x-icon name="arrow-right" size="14" class="text-muted" />
                                @endif
                                <x-badge :variant="$entry->to_status->variant()">{{ $entry->to_status->label() }}</x-badge>
                            </div>
                            <div class="small text-muted mt-1">{{ $entry->user?->name ?? __('System') }} &middot; {{ App\Support\Format::datetime($entry->created_at) }}</div>
                            @if ($entry->comment)
                                <div class="small mt-1" style="white-space: pre-line">{{ $entry->comment }}</div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
    <div class="col-lg-6">
        @can('activity.view')
            <div class="card">
                <div class="card-header">
                    <x-icon name="history" size="18" class="text-muted" />
                    <h2 class="card-title">{{ __('Activity') }}</h2>
                </div>
                @if ($activity->isEmpty())
                    <div class="card-body small text-muted">{{ __('No activity yet.') }}</div>
                @else
                    <ul class="list-unstyled mb-0">
                        @foreach ($activity as $entry)
                            <li class="px-4 py-2 border-bottom small">
                                <div>{{ $entry->description }}</div>
                                <div class="text-muted">{{ $entry->user?->name ?? __('System') }} &middot; {{ App\Support\Format::datetime($entry->created_at) }}</div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endcan
    </div>
</div>
