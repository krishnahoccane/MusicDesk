<div>
    <x-page-header :title="__('Activity log')" :subtitle="__('Who did what, and when. Entries cannot be edited.')" />

    <div class="card">
        <div class="card-body border-bottom py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-4 col-xl-3">
                    <div class="input-icon">
                        <x-icon name="search" size="16" />
                        <input type="search" class="form-control" placeholder="{{ __('Search description or IP') }}"
                               wire:model.live.debounce.300ms="search" aria-label="{{ __('Search activity') }}" maxlength="100">
                    </div>
                </div>
                <div class="col-sm-6 col-md-4 col-xl-2">
                    <select class="form-select" wire:model.live="event" aria-label="{{ __('Filter by event') }}">
                        <option value="">{{ __('All events') }}</option>
                        @foreach ($events as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-md-4 col-xl-2">
                    <select class="form-select" wire:model.live="user" aria-label="{{ __('Filter by user') }}">
                        <option value="">{{ __('All users') }}</option>
                        <option value="system">{{ __('System / guest') }}</option>
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label small mb-1" for="from">{{ __('From') }}</label>
                    <input id="from" type="date" class="form-control" wire:model.live="from">
                </div>
                <div class="col-6 col-md-3 col-xl-2">
                    <label class="form-label small mb-1" for="to">{{ __('To') }}</label>
                    <input id="to" type="date" class="form-control" wire:model.live="to">
                </div>
                <div class="col-md-2 col-xl-1">
                    @if ($search !== '' || $event !== '' || $user !== '' || $from !== '' || $to !== '')
                        <button type="button" class="btn btn-light w-100" wire:click="clearFilters">{{ __('Clear') }}</button>
                    @endif
                </div>
            </div>
        </div>

        @if ($entries->isEmpty())
            <x-empty-state icon="history" :title="__('No activity found')" :description="__('Nothing matches these filters.')" />
        @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('When') }}</th>
                            <th>{{ __('User') }}</th>
                            <th>{{ __('Event') }}</th>
                            <th>{{ __('Description') }}</th>
                            <th class="d-none d-lg-table-cell">{{ __('IP address') }}</th>
                            <th><span class="visually-hidden">{{ __('Details') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr wire:key="entry-{{ $entry->id }}">
                                <td class="text-nowrap small">{{ App\Support\Format::datetime($entry->created_at) }}</td>
                                <td class="small">
                                    @if ($entry->user)
                                        {{ $entry->user->name }}
                                        @if ($entry->user->trashed()) <span class="text-muted">({{ __('deleted') }})</span> @endif
                                    @else
                                        <span class="text-muted">{{ __('System / guest') }}</span>
                                    @endif
                                </td>
                                <td><x-badge :variant="str_starts_with($entry->event, 'auth.failed') || str_ends_with($entry->event, 'deleted') || str_ends_with($entry->event, 'deactivated') || $entry->event === 'auth.lockout' ? 'warning' : null">{{ $events[$entry->event] ?? $entry->event }}</x-badge></td>
                                <td>{{ $entry->description }}</td>
                                <td class="d-none d-lg-table-cell small text-muted font-monospace">{{ $entry->ip_address ?? '—' }}</td>
                                <td class="text-end">
                                    @if ($entry->properties || $entry->user_agent)
                                        <button type="button" class="ld-icon-btn" wire:click="toggle({{ $entry->id }})"
                                                aria-expanded="{{ $expanded === $entry->id ? 'true' : 'false' }}" aria-label="{{ __('Show details') }}">
                                            <x-icon :name="$expanded === $entry->id ? 'chevron-up' : 'chevron-down'" size="16" />
                                        </button>
                                    @endif
                                </td>
                            </tr>
                            @if ($expanded === $entry->id)
                                <tr wire:key="entry-{{ $entry->id }}-details">
                                    <td colspan="6" class="small" style="background: var(--ld-surface-hover)">
                                        @if ($entry->properties)
                                            <dl class="ld-dl mb-2">
                                                @foreach ($entry->properties as $key => $value)
                                                    <dt>{{ Str::headline($key) }}</dt>
                                                    <dd class="text-break">
                                                        @php($isChange = is_array($value) && array_is_list($value) && count($value) === 2 && ! is_array($value[0]) && ! is_array($value[1]) && ! in_array($key, ['granted', 'revoked', 'permissions'], true))
                                                        @if ($isChange)
                                                            {{ $value[0] ?? '—' }} &rarr; {{ $value[1] ?? '—' }}
                                                        @elseif (is_array($value))
                                                            {{ collect($value)->map(fn ($v) => is_scalar($v) ? $v : json_encode($v))->join(', ') }}
                                                        @elseif (is_bool($value))
                                                            {{ $value ? __('Yes') : __('No') }}
                                                        @else
                                                            {{ $value }}
                                                        @endif
                                                    </dd>
                                                @endforeach
                                            </dl>
                                        @endif
                                        @if ($entry->user_agent)
                                            <div class="text-muted text-break">{{ $entry->user_agent }}</div>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($entries->hasPages())
                <div class="card-body border-top py-2">{{ $entries->links() }}</div>
            @endif
        @endif
    </div>
</div>
