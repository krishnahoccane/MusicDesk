<div class="card">
    <div class="card-header">
        <x-icon name="circle-help" size="18" class="text-muted" />
        <h2 class="card-title">{{ __('Unmatched sales') }}</h2>
    </div>
    @if ($groups->isEmpty())
        <x-empty-state icon="circle-check-big" :title="__('Everything is matched')" :description="__('All imported sales lines belong to tracks or releases in your catalogue.')" />
    @else
        <p class="small text-muted px-4 pt-3 mb-2">{{ __('These identifiers were not found in your catalogue. Match each one to a track (for example when the store used an old or wrong ISRC), or mark it as not yours. Unmatched sales are not paid to anyone.') }}</p>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ __('Identifier') }}</th>
                        <th class="d-none d-md-table-cell">{{ __('Store') }}</th>
                        <th class="text-end">{{ __('Lines') }}</th>
                        <th class="text-end">{{ __('Revenue') }}</th>
                        <th class="text-end"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($groups as $group)
                        <tr wire:key="g-{{ $group->kind }}-{{ $group->id_key }}">
                            <td><span class="small text-muted text-uppercase">{{ $group->kind }}</span> <span class="font-monospace">{{ $group->id_key }}</span></td>
                            <td class="d-none d-md-table-cell small">{{ $group->store ?? '—' }}</td>
                            <td class="text-end">{{ number_format($group->line_count) }}</td>
                            <td class="text-end text-nowrap">{{ App\Support\Format::money($group->amount, $currency) }}</td>
                            <td class="text-end text-nowrap">
                                @if ($canManage)
                                    <button type="button" class="btn btn-sm btn-light" wire:click="startMatching('{{ $group->kind }}', '{{ $group->id_key }}')">{{ __('Match to track') }}</button>
                                    <button type="button" class="btn btn-sm btn-light" wire:click="ignore('{{ $group->kind }}', '{{ $group->id_key }}')"
                                            wire:confirm="{{ __('Mark these sales as not belonging to your catalogue?') }}">{{ __('Not ours') }}</button>
                                @endif
                            </td>
                        </tr>
                        @if ($matchingKey === $group->id_key && $matchingKind === $group->kind)
                            <tr wire:key="g-{{ $group->id_key }}-match">
                                <td colspan="5" style="background: var(--ld-surface-hover)">
                                    <div class="d-flex gap-2 align-items-center mb-2">
                                        <div class="input-icon flex-grow-1" style="max-width: 420px">
                                            <x-icon name="search" size="16" />
                                            <input type="search" class="form-control form-control-sm" wire:model.live.debounce.300ms="trackSearch" autofocus
                                                   placeholder="{{ __('Search your tracks by title, artist or ISRC') }}" aria-label="{{ __('Search tracks') }}" maxlength="100">
                                        </div>
                                        <button type="button" class="btn btn-sm btn-light" wire:click="cancel">{{ __('Cancel') }}</button>
                                    </div>
                                    @foreach ($results as $track)
                                        <button type="button" class="d-flex w-100 align-items-center gap-2 btn btn-sm btn-light text-start mb-1" wire:click="assign({{ $track->id }})" wire:key="r-{{ $track->id }}">
                                            <span class="fw-semibold">{{ $track->displayTitle() }}</span>
                                            <span class="text-muted">{{ $track->artistLine() }} &middot; {{ $track->release?->displayTitle() }}</span>
                                            <span class="ms-auto font-monospace small">{{ $track->formattedIsrc() }}</span>
                                        </button>
                                    @endforeach
                                    @if (mb_strlen(trim($trackSearch)) >= 2 && $results->isEmpty())
                                        <div class="small text-muted">{{ __('No tracks found.') }}</div>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
