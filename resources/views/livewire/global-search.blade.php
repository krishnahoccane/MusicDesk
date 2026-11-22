<div class="ld-search" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false; $refs.input.blur()"
     @keydown.window="if ((($event.ctrlKey || $event.metaKey) && $event.key.toLowerCase() === 'k') || ($event.key === '/' && ! ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName) && ! document.activeElement.isContentEditable)) { $event.preventDefault(); $refs.input.focus(); open = true; }"
     role="search">
    <x-icon name="search" size="16" />
    <input x-ref="input" type="search" class="ld-search__input" wire:model.live.debounce.250ms="query" @focus="open = true" @input="open = true"
           placeholder="{{ __('Search artists, releases, tracks…') }}" aria-label="{{ __('Search') }}" autocomplete="off" maxlength="100"
           @keydown.arrow-down.prevent="$refs.panel?.querySelector('a')?.focus()">
    <span class="ld-search__kbd" aria-hidden="true">Ctrl K</span>

    @if ($searched)
        <div class="ld-search__panel" x-show="open" x-ref="panel" x-cloak
             @keydown.arrow-down.prevent="$event.target.closest('a') && ([...$refs.panel.querySelectorAll('a')][[...$refs.panel.querySelectorAll('a')].indexOf($event.target) + 1]?.focus())"
             @keydown.arrow-up.prevent="$event.target.closest('a') && (([...$refs.panel.querySelectorAll('a')][[...$refs.panel.querySelectorAll('a')].indexOf($event.target) - 1]) ?? $refs.input).focus()">
            @forelse ($groups as $group)
                <div class="ld-search__group">{{ $group['label'] }}</div>
                @foreach ($group['items'] as $item)
                    <a href="{{ $item['url'] }}" class="ld-search__item">
                        <span class="ld-list__icon"><x-icon :name="$group['icon']" size="16" /></span>
                        <span class="min-w-0">
                            <span class="d-block text-truncate fw-semibold">{{ $item['title'] }}</span>
                            @if ($item['meta'] !== '')<small class="text-truncate">{{ $item['meta'] }}</small>@endif
                        </span>
                    </a>
                @endforeach
            @empty
                <div class="text-center text-muted small py-4">{{ __('No matches for ":q".', ['q' => $query]) }}</div>
            @endforelse
        </div>
    @endif
</div>
