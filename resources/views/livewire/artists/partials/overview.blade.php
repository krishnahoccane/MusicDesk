<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header">
                <x-icon name="info" size="18" class="text-muted" />
                <h2 class="card-title">{{ __('Details') }}</h2>
            </div>
            <div class="card-body py-2">
                <dl class="ld-dl">
                    <dt>{{ __('Legal name') }}</dt><dd>{{ $artist->legal_name ?? '—' }}</dd>
                    <dt>{{ __('Type') }}</dt><dd>{{ $artist->type->label() }}</dd>
                    <dt>{{ __('Main genre') }}</dt><dd>{{ $artist->primary_genre ?? '—' }}</dd>
                    <dt>{{ __('Country') }}</dt><dd>{{ $artist->country_name ?? '—' }}</dd>
                    <dt>{{ __('Email') }}</dt>
                    <dd>@if ($artist->email)<a href="mailto:{{ $artist->email }}">{{ $artist->email }}</a>@else — @endif</dd>
                    <dt>{{ __('Phone') }}</dt><dd>{{ $artist->phone ?? '—' }}</dd>
                    <dt>{{ __('Added') }}</dt><dd>{{ App\Support\Format::date($artist->created_at) }}</dd>
                </dl>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <x-icon name="book-open" size="18" class="text-muted" />
                <h2 class="card-title">{{ __('Biography') }}</h2>
            </div>
            <div class="card-body">
                @if ($artist->bio)
                    <p class="mb-0" style="white-space: pre-line">{{ $artist->bio }}</p>
                @else
                    <p class="text-muted mb-0">{{ __('No biography yet.') }}</p>
                @endif
            </div>
        </div>

        @if ($artist->notes)
            <div class="card">
                <div class="card-header">
                    <x-icon name="sticky-note" size="18" class="text-muted" />
                    <h2 class="card-title">{{ __('Internal notes') }}</h2>
                </div>
                <div class="card-body"><p class="mb-0" style="white-space: pre-line">{{ $artist->notes }}</p></div>
            </div>
        @endif
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header">
                <x-icon name="contact" size="18" class="text-muted" />
                <h2 class="card-title">{{ __('Primary contact') }}</h2>
                <button type="button" class="btn btn-sm btn-link ms-auto p-0" wire:click="show('contacts')">{{ __('All contacts') }}</button>
            </div>
            <div class="card-body small">
                @if ($primaryContact)
                    <div class="fw-semibold">{{ $primaryContact->name }}</div>
                    <div class="text-muted mb-1">{{ $primaryContact->role->label() }}</div>
                    @if ($primaryContact->email)<div><a href="mailto:{{ $primaryContact->email }}">{{ $primaryContact->email }}</a></div>@endif
                    @if ($primaryContact->phone)<div>{{ $primaryContact->phone }}</div>@endif
                @else
                    <span class="text-muted">{{ __('No contacts yet.') }}</span>
                @endif
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header">
                <x-icon name="link" size="18" class="text-muted" />
                <h2 class="card-title">{{ __('Links') }}</h2>
            </div>
            <div class="card-body small">
                @forelse ($links as $link)
                    <a href="{{ $link->url }}" target="_blank" rel="noopener noreferrer nofollow" class="d-inline-flex align-items-center gap-1 me-3 mb-1">
                        <x-icon :name="$link->platform->icon()" size="14" /> {{ $link->platform->label() }}
                    </a>
                @empty
                    <span class="text-muted">{{ __('No links yet.') }}</span>
                @endforelse
            </div>
        </div>

        @if (in_array('contracts', $tabs, true))
            <div class="card mb-3">
                <div class="card-header">
                    <x-icon name="file-signature" size="18" class="text-muted" />
                    <h2 class="card-title">{{ __('Active contracts') }}</h2>
                </div>
                <ul class="list-unstyled mb-0 px-4 py-2 small">
                    @forelse ($activeContracts as $contract)
                        <li class="py-1 d-flex justify-content-between gap-2">
                            <span>{{ $contract->title }}</span>
                            <span class="text-muted text-nowrap">{{ $contract->end_date ? __('until :date', ['date' => App\Support\Format::date($contract->end_date)]) : __('no end date') }}</span>
                        </li>
                    @empty
                        <li class="py-1 text-muted">{{ __('No active contracts.') }}</li>
                    @endforelse
                </ul>
            </div>
        @endif

        @if (in_array('documents', $tabs, true))
            <div class="card">
                <div class="card-header">
                    <x-icon name="paperclip" size="18" class="text-muted" />
                    <h2 class="card-title">{{ __('Recent documents') }}</h2>
                </div>
                <ul class="list-unstyled mb-0 px-4 py-2 small">
                    @forelse ($recentDocuments as $document)
                        <li class="py-1">
                            <a href="{{ route('artists.documents.download', [$artist, $document]) }}" class="d-inline-flex align-items-center gap-1">
                                <x-icon :name="$document->icon()" size="14" /> {{ $document->title }}
                            </a>
                        </li>
                    @empty
                        <li class="py-1 text-muted">{{ __('No documents yet.') }}</li>
                    @endforelse
                </ul>
            </div>
        @endif
    </div>
</div>
