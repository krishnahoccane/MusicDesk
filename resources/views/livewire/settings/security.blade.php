<div>
    <x-page-header :title="__('Settings')" :subtitle="__('Configure how :product works for your label.', ['product' => config('labeldesk.product.name')])" />

    @include('livewire.settings._tabs')

    <div class="row g-3">
        <div class="col-xl-7">
            <form class="card" wire:submit="save" novalidate>
                <div class="card-header">
                    <x-icon name="life-buoy" size="18" />
                    <h2 class="card-title">{{ __('MusicDesk support access') }}</h2>
                </div>
                <div class="card-body">
                    <p class="small text-muted">
                        {{ __('MusicDesk support can sign in to this site as a Super Admin to help with a problem — for example when the license cannot be verified. Each sign-in is signed by MusicDesk.tech, limited to 60 minutes, recorded in the activity log, and shown with a banner while it is active. No data is ever deleted.') }}
                    </p>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="support_sso" wire:model.live="support_sso">
                        <label class="form-check-label fw-semibold" for="support_sso" style="color: var(--ld-heading)">{{ __('Allow MusicDesk support to sign in') }}</label>
                        <div class="form-text">{{ __('When off, support cannot sign in — you may need to turn it on before MusicDesk can help with a locked or unlicensed site.') }}</div>
                    </div>

                    <div class="form-check form-switch" @if (! $support_sso) style="opacity:.5" @endif>
                        <input class="form-check-input" type="checkbox" role="switch" id="support_sso_email" wire:model="support_sso_email" @disabled(! $support_sso)>
                        <label class="form-check-label" for="support_sso_email">{{ __('E-mail me whenever support signs in') }}</label>
                        <div class="form-text">{{ __('The Super Admin gets an e-mail each time a support session starts.') }}</div>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading wire:target="save" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                        {{ __('Save') }}
                    </button>
                </div>
            </form>
        </div>

        <div class="col-xl-5">
            <div class="card">
                <div class="card-header">
                    <x-icon name="history" size="18" />
                    <h2 class="card-title">{{ __('Recent support sign-ins') }}</h2>
                </div>
                @if ($recentSupport->isEmpty())
                    <x-empty-state icon="shield-check" :title="__('None yet')" :description="__('MusicDesk support has not signed in to this site.')" />
                @else
                    <ul class="ld-list">
                        @foreach ($recentSupport as $entry)
                            <li class="ld-list__item">
                                <span class="flex-grow-1 min-w-0">
                                    <span class="d-block small">{{ $entry->description }}</span>
                                    <span class="d-block small text-muted">{{ $entry->created_at->setTimezone(config('labeldesk.display_timezone'))->format(config('labeldesk.datetime_format')) }}@if (data_get($entry->properties, 'ip')) · {{ data_get($entry->properties, 'ip') }} @endif</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>
