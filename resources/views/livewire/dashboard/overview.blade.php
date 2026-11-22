<div>
    {{-- Hero --}}
    <section class="ld-hero">
        <div class="ld-hero__wave" aria-hidden="true">
            @foreach ([30, 55, 40, 70, 48, 82, 36, 64, 44, 74, 34, 58, 26, 50, 38, 66] as $h)
                <span style="height: {{ $h }}%"></span>
            @endforeach
        </div>
        <div class="position-relative" style="max-width: 760px">
            <div class="ld-hero__eyebrow">{{ $today }}</div>
            <h1 class="ld-hero__title">{{ $greeting }}, {{ $firstName }}</h1>
            <p class="ld-hero__text">{{ __('Here is what is happening at :label today.', ['label' => config('app.name')]) }}</p>
            @if ($actions)
                <div class="d-flex flex-wrap gap-2 mt-3">
                    @foreach ($actions as $action)
                        <a href="{{ $action['url'] }}" class="ld-quick-action"><x-icon :name="$action['icon']" size="15" /> {{ $action['label'] }}</a>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    {{-- KPIs --}}
    @if ($kpis)
        <div class="row g-3 mb-4">
            @foreach ($kpis as $kpi)
                <div class="col-sm-6 col-xl-3">
                    <x-stat-card :label="$kpi['label']" :value="$kpi['value']" :icon="$kpi['icon']" :variant="$kpi['variant']" :hint="$kpi['hint']" :href="$kpi['url']" class="ld-card--link" />
                </div>
            @endforeach
        </div>
    @endif

    <div class="row g-3">
        <div class="col-xl-8">
            {{-- Charts --}}
            @if (isset($charts['revenue']))
                <div class="card mb-3">
                    <div class="card-header">
                        <x-icon name="trending-up" size="18" />
                        <h2 class="card-title">{{ __('Royalty revenue by period') }}</h2>
                        <span class="small text-muted ms-auto">{{ $currency }}</span>
                    </div>
                    <div class="card-body pt-2"><div data-chart='@json($charts['revenue'])'></div></div>
                </div>
            @endif

            @if (isset($charts['releases']) || isset($charts['stores']))
                <div class="row g-3 mb-3">
                    @isset($charts['releases'])
                        <div @class(['col-md-6' => isset($charts['stores']), 'col-12' => ! isset($charts['stores'])])>
                            <div class="card h-100">
                                <div class="card-header">
                                    <x-icon name="disc-3" size="18" />
                                    <h2 class="card-title">{{ __('Releases by status') }}</h2>
                                </div>
                                <div class="card-body pt-2"><div data-chart='@json($charts['releases'])'></div></div>
                            </div>
                        </div>
                    @endisset
                    @isset($charts['stores'])
                        <div @class(['col-md-6' => isset($charts['releases']), 'col-12' => ! isset($charts['releases'])])>
                            <div class="card h-100">
                                <div class="card-header">
                                    <x-icon name="send" size="18" />
                                    <h2 class="card-title">{{ __('Top stores') }}</h2>
                                </div>
                                <div class="card-body pt-2"><div data-chart='@json($charts['stores'])'></div></div>
                            </div>
                        </div>
                    @endisset
                </div>
            @endif

            {{-- Upcoming releases --}}
            @if ($upcoming !== null)
                <div class="card mb-3">
                    <div class="card-header">
                        <x-icon name="calendar-days" size="18" />
                        <h2 class="card-title">{{ __('Upcoming releases') }}</h2>
                        <a href="{{ route('releases.index') }}" class="small ms-auto">{{ __('All releases') }} &rarr;</a>
                    </div>
                    @if ($upcoming->isEmpty())
                        <x-empty-state icon="calendar-days" :title="__('Nothing scheduled')" :description="__('Releases dated in the next 60 days appear here.')" />
                    @else
                        <ul class="ld-list">
                            @foreach ($upcoming as $release)
                                <li>
                                    <a href="{{ route('releases.show', $release) }}" class="ld-list__item text-decoration-none">
                                        <x-release-artwork :release="$release" size="40" />
                                        <span class="flex-grow-1 min-w-0">
                                            <span class="d-block fw-semibold text-truncate">{{ $release->displayTitle() }}</span>
                                            <span class="ld-list__meta">{{ $release->artistLine() }} &middot; {{ $release->type->label() }}</span>
                                        </span>
                                        <span class="text-end">
                                            <span class="d-block small fw-semibold" style="color: var(--ld-heading)">{{ App\Support\Format::date($release->release_date) }}</span>
                                            <x-badge :variant="$release->status->variant()">{{ $release->status->label() }}</x-badge>
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            @if (! $charts && $upcoming === null && ! $kpis)
                <div class="card mb-3">
                    <x-empty-state icon="sparkles" :title="__('Welcome to :name', ['name' => config('app.name')])"
                                   :description="__('Your role does not include catalogue or royalty areas yet. Ask an administrator if you need more access.')" />
                </div>
            @endif
        </div>

        <div class="col-xl-4">
            {{-- Attention --}}
            <div class="card mb-3">
                <div class="card-header">
                    <x-icon name="bell-ring" size="18" />
                    <h2 class="card-title">{{ __('Needs attention') }}</h2>
                    @if ($attention)<x-badge variant="warning" class="ms-auto">{{ count($attention) }}</x-badge>@endif
                </div>
                @if ($attention)
                    <ul class="ld-list">
                        @foreach ($attention as $item)
                            <li>
                                <a href="{{ $item['url'] }}" class="ld-list__item text-decoration-none">
                                    <span class="ld-list__icon ld-list__icon--{{ $item['variant'] }}"><x-icon :name="$item['icon']" size="16" /></span>
                                    <span class="flex-grow-1 min-w-0">
                                        <span class="d-block fw-semibold small">{{ $item['title'] }}</span>
                                        <span class="ld-list__meta">{{ $item['meta'] }}</span>
                                    </span>
                                    <x-icon name="chevron-right" size="16" class="text-subtle" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="card-body d-flex align-items-center gap-3">
                        <span class="ld-list__icon ld-list__icon--success"><x-icon name="check-check" size="16" /></span>
                        <span class="small text-muted">{{ __('All caught up. Nothing needs your attention right now.') }}</span>
                    </div>
                @endif
            </div>

            {{-- Administration --}}
            @if ($admin)
                <div class="card mb-3">
                    <div class="card-header">
                        <x-icon name="shield-check" size="18" />
                        <h2 class="card-title">{{ __('Administration') }}</h2>
                    </div>
                    <div class="card-body">
                        @if ($admin['license'])
                            @php($lic = $admin['license'])
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="fw-semibold" style="color: var(--ld-heading)">{{ __('License') }}</span>
                                <x-badge :variant="$lic['state']->variant()" class="ms-auto">{{ $lic['state']->label() }}</x-badge>
                            </div>
                            <div class="small text-muted mb-3">
                                @if ($lic['plan'])
                                    <span class="text-capitalize">{{ $lic['plan'] }}</span> &middot; {{ $lic['domain'] }}<br>
                                    {{ __('Checked :time', ['time' => $lic['checked']?->diffForHumans() ?? __('never')]) }}
                                    @if ($lic['support']) &middot; {{ __('updates until :date', ['date' => App\Support\Format::date($lic['support'])]) }} @endif
                                @else
                                    {{ __('No license is activated.') }}
                                @endif
                                @foreach ($lic['messages'] as $notice)
                                    <div class="mt-1" style="color: var(--ld-info)"><x-icon name="info" size="12" /> {{ $notice }}</div>
                                @endforeach
                            </div>
                        @endif

                        @if ($admin['users'])
                            @php($u = $admin['users'])
                            <div class="d-flex justify-content-between small mb-1">
                                <a href="{{ route('users.index') }}" class="fw-semibold" style="color: var(--ld-heading)">{{ __('Active users') }}</a>
                                <span class="text-muted">{{ $u['active'] }}{{ $u['limit'] ? ' / '.$u['limit'] : '' }}</span>
                            </div>
                            <div class="ld-progress mb-3"><span style="width: {{ $u['limit'] ? min(100, round($u['active'] / max(1, $u['limit']) * 100)) : 100 }}%"></span></div>
                        @endif

                        @if ($admin['health'])
                            <div class="fw-semibold small mb-1" style="color: var(--ld-heading)">{{ __('System health') }}</div>
                            @foreach ($admin['health'] as $check)
                                <div class="ld-health">
                                    <span @class(['ld-health__dot', 'ld-health__dot--'.$check['state'] => $check['state'] !== 'ok'])></span>
                                    <span>{{ $check['label'] }}</span>
                                </div>
                            @endforeach
                        @endif
                    </div>
                </div>
            @endif

            {{-- Activity --}}
            @if ($activity !== null)
                <div class="card">
                    <div class="card-header">
                        <x-icon name="history" size="18" />
                        <h2 class="card-title">{{ __('Recent activity') }}</h2>
                        <a href="{{ route('activity.index') }}" class="small ms-auto">{{ __('All') }} &rarr;</a>
                    </div>
                    @if ($activity->isEmpty())
                        <div class="card-body small text-muted">{{ __('No activity yet.') }}</div>
                    @else
                        <ul class="ld-timeline">
                            @foreach ($activity as $entry)
                                <li>
                                    <div class="small">{{ $entry->description }}</div>
                                    <div class="ld-list__meta">{{ $entry->user?->name ?? __('System') }} &middot; {{ $entry->created_at?->diffForHumans() }}</div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
