<div class="card">
    @if ($activity->isEmpty())
        <x-empty-state icon="history" :title="__('No activity yet')" />
    @else
        <ul class="list-unstyled mb-0">
            @foreach ($activity as $entry)
                <li class="d-flex gap-3 px-4 py-3 border-bottom">
                    <x-icon name="history" size="16" class="text-muted mt-1" />
                    <div class="flex-grow-1">
                        <div>{{ $entry->description }}</div>
                        <div class="small text-muted">
                            {{ $entry->user?->name ?? __('System') }} &middot; {{ App\Support\Format::datetime($entry->created_at) }}
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
